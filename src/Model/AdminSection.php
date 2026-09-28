<?php

namespace PurpleSpider\PageTypeTester\Model;

/**
 * A ModelAdmin section or the SiteConfig settings screen.
 */
class AdminSection
{
    /**
     * @param AdminEditLink[] $editLinks
     * @param EmailUsage[] $emailUsages Where the section's own code sends email. Its records'
     *                                  usages are on their edit links.
     * @param array<string, EmailUsage[]> $unlinkedEmailUsages Model name => usages, for
     *                                                         records with no edit link to go by,
     *                                                         e.g. because there are none yet.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $url,
        public readonly int $index,
        public readonly array $editLinks = [],
        public readonly array $emailUsages = [],
        public readonly array $unlinkedEmailUsages = []
    ) {
    }
}
