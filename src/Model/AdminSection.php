<?php

namespace PurpleSpider\PageTypeTester\Model;

/**
 * A ModelAdmin section or the SiteConfig settings screen.
 */
class AdminSection
{
    /**
     * @param AdminEditLink[] $editLinks
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $url,
        public readonly int $index,
        public readonly array $editLinks = []
    ) {
    }
}
