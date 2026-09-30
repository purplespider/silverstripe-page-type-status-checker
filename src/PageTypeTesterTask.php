<?php

namespace PurpleSpider\PageTypeTester;

use PurpleSpider\PageTypeTester\Collector\AdminSectionCollector;
use PurpleSpider\PageTypeTester\Collector\BlockTypeCollector;
use PurpleSpider\PageTypeTester\Collector\PageTypeCollector;
use PurpleSpider\PageTypeTester\Report\CliReport;
use PurpleSpider\PageTypeTester\Report\HtmlReport;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Lists every page type with its CMS edit and frontend URLs, and checks that each one
 * responds as expected. Intended for verifying a site after an upgrade.
 *
 * This class only wires things together. The work lives in:
 *  - Collector\PageTypeCollector      finding page types and an example page for each
 *  - Collector\BlockTypeCollector     finding Elemental block types and an example block for each
 *  - Collector\AdminSectionCollector  finding ModelAdmin sections and their edit forms
 *  - UrlChecker                       performing the HTTP checks
 *  - ActionLinkFinder                 locating URLs for $allowed_actions
 *  - EmailUsageFinder                 finding the code that sends email for each type
 *  - Report\CliReport                 the terminal report
 *  - Report\HtmlReport                the browser report
 *  - PageCreator                      the "create a page of this type" endpoint
 *  - PageDeleter                      the "delete a page this report created" endpoint
 *  - BlockCreator                     the "create a block of this type" endpoint
 *  - BlockEditorChecker              the "can this block be listed in the editor" endpoint
 *  - BlockRenderer                    the "render this block on its own" endpoint
 */
class PageTypeTesterTask extends BuildTask
{
    protected static string $commandName = 'check-page-type-statuses';

    protected string $title = 'Check Page Type Statuses';

    protected static string $description =
        'Lists CMS edit and frontend links for each page type - useful for testing after upgrades';

    public function getOptions(): array
    {
        return [
            new InputOption(
                'skip-actions',
                null,
                InputOption::VALUE_NONE,
                'Skip checking allowed_actions URLs'
            ),
            new InputOption(
                'skip-admin',
                null,
                InputOption::VALUE_NONE,
                'Skip checking ModelAdmin sections and SiteConfig'
            ),
            new InputOption(
                'skip-blocks',
                null,
                InputOption::VALUE_NONE,
                'Skip checking Elemental block types'
            ),
            new InputOption(
                'randomise',
                null,
                InputOption::VALUE_NONE,
                'Pick a random example page per type instead of the first'
            ),
            new InputOption(
                'live-domain',
                null,
                InputOption::VALUE_REQUIRED,
                'Live site URL to add comparison links for, e.g. https://example.com'
            ),
            new InputOption(
                'verify-ssl',
                null,
                InputOption::VALUE_REQUIRED,
                'Verify TLS certificates: 1 or 0. Defaults to off in dev mode, on elsewhere.'
            ),
        ];
    }

    public function run(InputInterface $input, PolyOutput $output): int
    {
        $request = $this->getHttpRequest();

        // These write to the database, so they are handled before anything is rendered
        // and terminate the request with a JSON response.
        if ($request && $request->requestVar(PageCreator::PARAM)) {
            (new PageCreator())->handle($request, (string) $request->requestVar(PageCreator::PARAM));
        }

        if ($request && $request->requestVar(PageDeleter::PARAM)) {
            (new PageDeleter())->handle($request, (string) $request->requestVar(PageDeleter::PARAM));
        }

        // These name Elemental classes, so are only reachable where it is installed.
        // Creating writes, like the page endpoints; the other two only read.
        if ($request && ElementalSupport::isInstalled()) {
            if ($request->requestVar(BlockCreator::PARAM)) {
                (new BlockCreator())->handle($request, (string) $request->requestVar(BlockCreator::PARAM));
            }

            if ($request->getVar(BlockEditorChecker::PARAM)) {
                (new BlockEditorChecker())->handle($request, (string) $request->getVar(BlockEditorChecker::PARAM));
            }

            if ($request->getVar(BlockRenderer::PARAM)) {
                (new BlockRenderer())->handle($request, (string) $request->getVar(BlockRenderer::PARAM));
            }
        }

        // The site name is author-supplied, so escape it before it reaches the console
        // formatter or a title containing angle brackets would be read as a style tag.
        $meta = array_map(
            OutputFormatter::escape(...),
            array_filter([ReportMeta::siteName(), ReportMeta::cmsVersion()])
        );
        $output->writeForAnsi(
            "<options=bold>{$this->getTitle()}</>"
            . ($meta === [] ? '' : ' <fg=gray>(' . implode(', ', $meta) . ')</>'),
            true
        );

        return $this->execute($input, $output);
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $skipActions = (bool) $input->getOption('skip-actions');
        $skipAdmin = (bool) $input->getOption('skip-admin');
        $skipBlocks = (bool) $input->getOption('skip-blocks');
        $randomise = (bool) $input->getOption('randomise');
        $liveDomain = $this->sanitiseLiveDomain((string) ($input->getOption('live-domain') ?? ''));

        $checker = new UrlChecker($this->resolveVerifySsl($input));
        // Shared, so a file reached from several types is only read once.
        $emailFinder = new EmailUsageFinder();

        $rows = (new PageTypeCollector($randomise, $emailFinder))->collect();
        $blockRows = $skipBlocks ? [] : (new BlockTypeCollector($randomise, $emailFinder))->collect();
        $adminSections = $skipAdmin ? [] : (new AdminSectionCollector($emailFinder))->collect();

        if ($output->getOutputFormat() === PolyOutput::FORMAT_HTML) {
            $createdPageIds = CreatedPageRegistry::forCurrentRequest()->existing();
            $htmlReport = new HtmlReport($checker, $liveDomain, $randomise, $createdPageIds);
            $htmlReport->renderHeader($output, $this->getTitle());
            $htmlReport->render($output, $rows, $blockRows, $adminSections, $skipAdmin);

            return Command::SUCCESS;
        }

        $cliReport = new CliReport($checker, new ActionLinkFinder(), $skipActions);
        $cliReport->render($output, $rows, $blockRows, $adminSections, $skipAdmin);

        // A checking tool that always succeeds is not much use in a pipeline.
        return $cliReport->hasFailures() ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Only absolute http(s) URLs are accepted. This value comes from the query string
     * and is rendered into links.
     */
    private function sanitiseLiveDomain(string $value): string
    {
        $value = trim($value);
        if ($value === '' || !filter_var($value, FILTER_VALIDATE_URL)) {
            return '';
        }

        $parts = parse_url($value);
        if (!$parts || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return '';
        }

        // Rebuilt from validated components rather than echoed back, so nothing from
        // the query string reaches the markup verbatim even before escaping.
        if (!preg_match('/^[a-z0-9.\-]+$/i', $parts['host'])) {
            return '';
        }

        $url = strtolower($parts['scheme']) . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $url .= ':' . $parts['port'];
        }
        if (isset($parts['path'])) {
            $url .= rtrim($parts['path'], '/');
        }

        return rtrim($url, '/');
    }

    private function resolveVerifySsl(InputInterface $input): ?bool
    {
        $value = $input->getOption('verify-ssl');
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private function getHttpRequest(): ?HTTPRequest
    {
        // Null when running under the CLI, where there is no controller.
        return Controller::curr()?->getRequest();
    }
}
