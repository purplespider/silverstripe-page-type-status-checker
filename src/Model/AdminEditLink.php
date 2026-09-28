<?php

namespace PurpleSpider\PageTypeTester\Model;

/**
 * An edit form for one record within a ModelAdmin section.
 */
class AdminEditLink
{
    /**
     * @param EmailUsage[] $emailUsages Where the record's code sends email, e.g. on saving.
     */
    public function __construct(
        public readonly string $modelName,
        public readonly string $url,
        public readonly string $recordTitle,
        public readonly int $index,
        public readonly array $emailUsages = []
    ) {
    }
}
