<?php

namespace PurpleSpider\PageTypeTester\Compat;

/**
 * Stand-in for Symfony\Component\Console\Input\InputOption.
 *
 * CMS 5 BuildTasks have no concept of declared options, so getOptions() is not
 * called by the framework on this branch. It is retained purely as in-code
 * documentation of the request vars the task accepts, and to keep this file in
 * step with the CMS 6 (main) branch.
 */
class InputOption
{
    public const VALUE_NONE = 4;
    public const VALUE_REQUIRED = 2;
    public const VALUE_OPTIONAL = 8;

    public function __construct(
        public readonly string $name,
        public readonly ?string $shortcut = null,
        public readonly ?int $mode = null,
        public readonly string $description = '',
        public readonly mixed $default = null,
    ) {
    }
}
