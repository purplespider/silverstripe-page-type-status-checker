<?php

namespace PurpleSpider\PageTypeTester\Compat;

/**
 * Stand-in for Symfony\Component\Console\Command\Command's exit-code constants.
 * symfony/console is not a dependency of silverstripe/framework 5.x.
 */
class Command
{
    public const SUCCESS = 0;
    public const FAILURE = 1;
    public const INVALID = 2;
}
