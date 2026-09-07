<?php

namespace PurpleSpider\PageTypeTester;

/**
 * The HTTP status codes each page type is expected to return.
 */
class ExpectedStatus
{
    /**
     * @return int[]
     */
    public static function forShortName(string $shortName): array
    {
        return match ($shortName) {
            'ErrorPage' => [404, 500],
            'RedirectorPage' => [301, 302, 303, 307, 308],
            default => [200],
        };
    }
}
