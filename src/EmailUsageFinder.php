<?php

namespace PurpleSpider\PageTypeTester;

use DNADesign\Elemental\Controllers\ElementController;
use DNADesign\Elemental\Models\BaseElement;
use Page;
use PhpToken;
use PurpleSpider\PageTypeTester\Model\EmailUsage;
use ReflectionClass;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Manifest\ClassLoader;
use SilverStripe\ORM\DataObject;
use Throwable;

/**
 * Finds the code that sends email for a page type, block type or admin section, by
 * reading its source rather than running it, so nothing is sent to find out.
 *
 * What counts as a type's own code:
 *  - its class and controller, and their parents up to the common base (Page and
 *    PageController, BaseElement and ElementController, DataObject, ModelAdmin), so a
 *    page type extending UserDefinedForm is flagged, but a form every page has through
 *    PageController is only flagged once, on Page
 *  - extensions applied to those classes, and traits they use
 *
 * From there it follows the classes that code uses, such as a form class or a
 * notification service, up to reference_depth steps. Only the site's own code is
 * followed, not vendor, which would otherwise flag everything that touches Member.
 *
 * It is a static reading, so it reports where email could be sent, not that it is. An
 * email sent from code the report cannot see, such as a UserDefinedForm's recipients
 * being chosen in the CMS, is still found, because the code that sends it is.
 */
class EmailUsageFinder
{
    use Configurable;

    /**
     * Using one of these, or a subclass, is taken to mean an email is sent: creating
     * one, injecting one, or taking one as a parameter to change it before it goes.
     * Static helpers such as Email::is_valid_address() do not count.
     *
     * @config
     */
    private static array $mail_classes = [
        'SilverStripe\Control\Email\Email',
        'Symfony\Component\Mailer\MailerInterface',
    ];

    /**
     * Global functions that send email.
     *
     * @config
     */
    private static array $mail_functions = [
        'mail',
    ];

    /**
     * How many classes deep to follow from a type's own code, e.g. 2 reaches a service
     * called by a form that the controller builds.
     *
     * @config
     */
    private static int $reference_depth = 2;

    /**
     * Names that sit where a class name could, but are not one.
     */
    private const NOT_CLASS_NAMES = [
        'self', 'static', 'parent', 'true', 'false', 'null', 'array', 'callable', 'bool', 'int',
        'float', 'string', 'iterable', 'object', 'mixed', 'void', 'never',
    ];

    /**
     * @var array<string, array{class: string, usages: array, references: string[]}> Keyed by file.
     */
    private array $scans = [];

    /**
     * @var array<string, ?string> Keyed by lower case class name.
     */
    private array $mailers = [];

    /**
     * @return EmailUsage[]
     */
    public function forPageType(string $class): array
    {
        $isBase = strcasecmp($class, Page::class) === 0;
        $controllerStops = ['PageController', ContentController::class];

        $classes = $this->chain($class, [Page::class, SiteTree::class]);

        // A page type with no controller of its own uses PageController, which is
        // reported on Page rather than against every type.
        $controller = (string) singleton($class)->getControllerName();
        if (class_exists($controller) && ($isBase || !$this->isOneOf($controller, $controllerStops))) {
            $classes = array_merge($classes, $this->chain($controller, $controllerStops));
        }

        return $this->find($classes);
    }

    /**
     * @return EmailUsage[]
     */
    public function forBlockType(string $class): array
    {
        $classes = $this->chain($class, [BaseElement::class]);

        $controller = (string) Config::inst()->get($class, 'controller_class');
        if (class_exists($controller) && !$this->isOneOf($controller, [ElementController::class])) {
            $classes = array_merge($classes, $this->chain($controller, [ElementController::class]));
        }

        return $this->find($classes);
    }

    /**
     * The admin's own code, such as an import or export that emails someone. The records
     * it manages are read separately, with forModel(), so each is reported by its own
     * edit form, and are not followed from here, where naming them in managed_models
     * would otherwise report them twice.
     *
     * @param string[] $modelClasses The records it manages.
     * @return EmailUsage[]
     */
    public function forAdmin(string $adminClass, array $modelClasses = []): array
    {
        return $this->find($this->chain($adminClass, [ModelAdmin::class]), $modelClasses);
    }

    /**
     * A record's code, such as a notification sent from onAfterWrite().
     *
     * @return EmailUsage[]
     */
    public function forModel(string $class): array
    {
        return $this->find($this->chain($class, [DataObject::class]));
    }

    /**
     * The class and its parents, stopping short of the first of $stopAt. The class
     * itself is always included, so Page is its own chain.
     *
     * @param string[] $stopAt
     * @return string[]
     */
    private function chain(string $class, array $stopAt): array
    {
        $chain = [$class];

        $ancestors = array_reverse(array_values(ClassInfo::ancestry($class)));
        foreach (array_slice($ancestors, 1) as $ancestor) {
            if ($this->isOneOf($ancestor, $stopAt)) {
                break;
            }
            $chain[] = $ancestor;
        }

        return $chain;
    }

    /**
     * @param string[] $classes
     * @param string[] $skip Classes not to follow into, as they are reported elsewhere.
     * @return EmailUsage[]
     */
    private function find(array $classes, array $skip = []): array
    {
        $depth = (int) static::config()->get('reference_depth');

        $seen = [];
        foreach ($skip as $class) {
            $file = $this->fileFor($class);
            if ($file !== null) {
                $seen[$file] = true;
            }
        }

        // Breadth first, so each usage is reported by the shortest route to it, and a
        // type's own code is claimed as its own before anything can reach it by reference.
        $queue = [];
        foreach ($this->withExtensionsAndTraits($classes) as $class) {
            $file = $this->fileFor($class);
            if ($file !== null && !isset($seen[$file])) {
                $seen[$file] = true;
                $queue[] = [$file, []];
            }
        }

        $usages = [];
        while ($queue) {
            [$file, $via] = array_shift($queue);
            $scan = $this->scan($file);

            foreach ($scan['usages'] as $usage) {
                $usages[] = new EmailUsage(
                    $usage['class'],
                    $usage['method'],
                    $usage['line'],
                    $this->relativePath($file),
                    $usage['mailer'],
                    $via
                );
            }

            if (count($via) >= $depth) {
                continue;
            }

            foreach ($scan['references'] as $reference) {
                $referenceFile = $this->fileFor($reference);
                if ($referenceFile === null || isset($seen[$referenceFile]) || !$this->isSiteCode($referenceFile)) {
                    continue;
                }

                $seen[$referenceFile] = true;
                $queue[] = [$referenceFile, array_merge($via, [$scan['class']])];
            }
        }

        return $usages;
    }

    /**
     * Extensions applied to each class directly, since extensions on a parent are
     * reported on the parent, and every trait any of them use.
     *
     * @param string[] $classes
     * @return string[]
     */
    private function withExtensionsAndTraits(array $classes): array
    {
        $all = [];
        foreach ($classes as $class) {
            $all[] = $class;

            $extensions = Config::inst()->get(
                $class,
                'extensions',
                Config::UNINHERITED | Config::EXCLUDE_EXTRA_SOURCES
            );
            foreach (array_filter((array) $extensions) as $extension) {
                $all[] = Extension::get_classname_without_arguments($extension);
            }
        }

        $traits = [];
        foreach ($all as $class) {
            $traits = array_merge($traits, $this->traitsOf($class));
        }

        return array_values(array_unique(array_merge($all, $traits)));
    }

    /**
     * @return string[]
     */
    private function traitsOf(string $class): array
    {
        if (!class_exists($class) && !trait_exists($class)) {
            return [];
        }

        $traits = [];
        foreach ((new ReflectionClass($class))->getTraitNames() as $trait) {
            $traits[] = $trait;
            $traits = array_merge($traits, $this->traitsOf($trait));
        }

        return $traits;
    }

    /**
     * Reads one file's email usages, and the classes it refers to. The result is kept,
     * as the same controller or form is reached from many types.
     *
     * @return array{class: string, usages: array, references: string[]}
     */
    private function scan(string $file): array
    {
        if (isset($this->scans[$file])) {
            return $this->scans[$file];
        }

        $code = is_readable($file) ? (string) file_get_contents($file) : '';
        $tokens = array_values(array_filter(
            PhpToken::tokenize($code),
            fn (PhpToken $token) => !$token->isIgnorable()
        ));

        $mailFunctions = array_map('strtolower', (array) static::config()->get('mail_functions'));

        $fileClass = '';
        $namespace = '';
        $imports = [];
        $depth = 0;
        $classes = [];
        $functions = [];
        $pendingClass = null;
        $pendingFunction = null;
        $usages = [];
        $references = [];

        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $prev = $tokens[$i - 1] ?? null;
            $next = $tokens[$i + 1] ?? null;

            if ($token->is(T_NAMESPACE)) {
                $namespace = $next?->is([T_STRING, T_NAME_QUALIFIED]) ? $next->text : '';
                $imports = [];
                continue;
            }

            // A use statement outside a class imports names. Inside one it brings in a
            // trait, and after a closure's parameters it captures variables.
            if ($token->is(T_USE) && !$classes && !$prev?->is(')')) {
                $i = $this->readImports($tokens, $i + 1, $imports);
                continue;
            }

            if ($token->is(['{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
                if ($pendingClass !== null) {
                    $classes[] = [$depth, $pendingClass];
                    $pendingClass = null;
                } elseif ($pendingFunction !== null) {
                    $functions[] = [$depth, $pendingFunction];
                    $pendingFunction = null;
                }
                continue;
            }

            if ($token->is('}')) {
                if ($functions && end($functions)[0] === $depth) {
                    array_pop($functions);
                }
                if ($classes && end($classes)[0] === $depth) {
                    array_pop($classes);
                }
                $depth--;
                continue;
            }

            // An abstract or interface method, which has no body.
            if ($token->is(';')) {
                $pendingFunction = null;
                continue;
            }

            // Foo::class is a class token too, and names nothing new.
            if ($token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM]) && !$prev?->is(T_DOUBLE_COLON)) {
                if ($next?->is(T_STRING)) {
                    $pendingClass = $next->text;
                    $fileClass = $fileClass ?: $next->text;
                    $i++;
                } else {
                    // An anonymous class, which is reported as the class it sits in.
                    $pendingClass = $classes ? end($classes)[1] : $fileClass;
                }
                continue;
            }

            if ($token->is(T_FUNCTION)) {
                $j = $i + 1;
                if (($tokens[$j] ?? null)?->is('&')) {
                    $j++;
                }
                // A closure has no name, and is reported as the method it sits in.
                if (($tokens[$j] ?? null)?->is(T_STRING)) {
                    $pendingFunction = $tokens[$j]->text;
                    $i = $j;
                }
                continue;
            }

            if (!$token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                continue;
            }

            // Members and constants, not classes. A parent class is not followed either:
            // parents are part of a type's own code only up to its common base.
            if ($prev?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_CONST, T_EXTENDS,
                T_IMPLEMENTS, T_GOTO])) {
                continue;
            }

            $where = [
                'class' => $classes ? end($classes)[1] : $fileClass,
                'method' => $functions ? end($functions)[1] : '',
                'line' => $token->line,
            ];

            // A function call, of which only the mail functions matter.
            if ($next?->is('(') && !$prev?->is(T_NEW)) {
                $function = strtolower(ltrim($token->text, '\\'));
                if (!$token->is(T_NAME_QUALIFIED) && in_array($function, $mailFunctions, true)) {
                    $usages[] = $where + ['mailer' => $function . '()'];
                }
                continue;
            }

            $class = $this->resolve($token, $namespace, $imports);
            if ($class === null) {
                continue;
            }

            $mailer = $this->mailerName($class);
            if ($mailer === null) {
                $references[strtolower($class)] = $class;
                continue;
            }

            // Email::create() and Email::class build one; any other static call, such as
            // Email::is_valid_address(), only uses the class.
            $member = $tokens[$i + 2] ?? null;
            if ($next?->is(T_DOUBLE_COLON)
                && !$member?->is(T_CLASS)
                && !($member?->is(T_STRING) && strcasecmp($member->text, 'create') === 0)
            ) {
                continue;
            }

            $usages[] = $where + ['mailer' => $mailer];
        }

        return $this->scans[$file] = [
            'class' => $fileClass ?: basename($file, '.php'),
            'usages' => $this->oncePerMethod($usages),
            'references' => array_values($references),
        ];
    }

    /**
     * A method that builds an email usually names the class more than once. Where the
     * email is sent from is what matters, so each method is listed once, at its first use.
     */
    private function oncePerMethod(array $usages): array
    {
        $unique = [];
        foreach ($usages as $usage) {
            $key = $usage['class'] . '::' . ($usage['method'] !== '' ? $usage['method'] : $usage['line']);
            $unique[$key] ??= $usage;
        }

        return array_values($unique);
    }

    /**
     * Reads one use statement, from just after the keyword, adding what it imports to
     * $imports. Returns the index of its closing semicolon.
     *
     * @param PhpToken[] $tokens
     * @param array<string, string> $imports Lower case alias => class.
     */
    private function readImports(array $tokens, int $i, array &$imports): int
    {
        // use function and use const import functions and constants, not classes.
        $skip = ($tokens[$i] ?? null)?->is([T_FUNCTION, T_CONST]);

        $group = '';
        $name = '';
        $alias = '';
        $expectAlias = false;

        for (; isset($tokens[$i]); $i++) {
            $token = $tokens[$i];

            if ($token->is(T_AS)) {
                $expectAlias = true;
            } elseif ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                if ($expectAlias) {
                    $alias = $token->text;
                    $expectAlias = false;
                } else {
                    $name = ltrim($token->text, '\\');
                }
            } elseif ($token->is('{')) {
                // use A\B\{C, D as E};
                $group = $name . '\\';
                $name = '';
            } elseif ($token->is([',', '}', ';'])) {
                if ($name !== '' && !$skip) {
                    $class = $group . $name;
                    $imports[strtolower($alias !== '' ? $alias : $this->shortName($class))] = $class;
                }
                $name = '';
                $alias = '';
                if ($token->is('}')) {
                    $group = '';
                }
                if ($token->is(';')) {
                    return $i;
                }
            }
        }

        return $i;
    }

    /**
     * @param array<string, string> $imports
     */
    private function resolve(PhpToken $token, string $namespace, array $imports): ?string
    {
        $name = $token->text;

        if ($token->is(T_NAME_FULLY_QUALIFIED)) {
            return ltrim($name, '\\');
        }

        if ($token->is(T_NAME_QUALIFIED)) {
            [$first, $rest] = explode('\\', $name, 2);
            $prefix = $imports[strtolower($first)] ?? ltrim($namespace . '\\' . $first, '\\');

            return $prefix . '\\' . $rest;
        }

        if (in_array(strtolower($name), self::NOT_CLASS_NAMES, true)) {
            return null;
        }

        return $imports[strtolower($name)] ?? ltrim($namespace . '\\' . $name, '\\');
    }

    /**
     * The short name to report a mail class by, or null if the class sends nothing.
     */
    private function mailerName(string $class): ?string
    {
        $key = strtolower($class);
        if (array_key_exists($key, $this->mailers)) {
            return $this->mailers[$key];
        }

        $mailClasses = (array) static::config()->get('mail_classes');

        foreach ($mailClasses as $mailClass) {
            if (strcasecmp($class, $mailClass) === 0) {
                return $this->mailers[$key] = $this->shortName($mailClass);
            }
        }

        // Subclasses are only checked for classes in the manifest, so that nothing is
        // autoloaded on the strength of a name that merely looks like a class.
        if ($this->fileFor($class) !== null) {
            foreach ($mailClasses as $mailClass) {
                try {
                    if (is_a($class, $mailClass, true)) {
                        return $this->mailers[$key] = $this->shortName($class);
                    }
                } catch (Throwable) {
                    break;
                }
            }
        }

        return $this->mailers[$key] = null;
    }

    private function fileFor(string $class): ?string
    {
        $path = ClassLoader::inst()->getItemPath($class);

        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * Anything outside vendor, i.e. the site's own code.
     */
    private function isSiteCode(string $file): bool
    {
        $base = rtrim(BASE_PATH, '/\\') . DIRECTORY_SEPARATOR;

        return str_starts_with($file, $base) && !str_starts_with($file, $base . 'vendor' . DIRECTORY_SEPARATOR);
    }

    private function relativePath(string $file): string
    {
        $base = rtrim(BASE_PATH, '/\\') . DIRECTORY_SEPARATOR;

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }

    /**
     * @param string[] $classes
     */
    private function isOneOf(string $class, array $classes): bool
    {
        foreach ($classes as $candidate) {
            if (strcasecmp(ltrim($class, '\\'), ltrim($candidate, '\\')) === 0) {
                return true;
            }
        }

        return false;
    }

    private function shortName(string $class): string
    {
        $at = strrpos($class, '\\');

        return $at === false ? $class : substr($class, $at + 1);
    }
}
