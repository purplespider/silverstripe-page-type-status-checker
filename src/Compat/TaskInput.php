<?php

namespace PurpleSpider\PageTypeTester\Compat;

use SilverStripe\Control\HTTPRequest;

/**
 * Stand-in for Symfony\Component\Console\Input\InputInterface.
 *
 * symfony/console is not a dependency of silverstripe/framework 5.x, and CMS 5
 * BuildTasks receive an HTTPRequest rather than console input. Sake passes CLI
 * arguments through as request vars, so reading from the request covers both
 * the browser and the command line.
 */
class TaskInput
{
    private array $options;

    public function __construct(array $options = [])
    {
        $this->options = $options;
    }

    /**
     * Build the input from a CMS 5 BuildTask request.
     */
    public static function fromRequest(?HTTPRequest $request, array $names): self
    {
        $options = [];
        foreach ($names as $name => $isFlag) {
            $value = $request ? $request->getVar($name) : null;
            if ($value === null) {
                $options[$name] = $isFlag ? false : null;
                continue;
            }
            $options[$name] = $isFlag ? filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true : $value;
        }
        return new self($options);
    }

    public function getOption(string $name): mixed
    {
        return $this->options[$name] ?? null;
    }

    public function hasOption(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }
}
