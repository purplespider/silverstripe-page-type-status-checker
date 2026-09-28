<?php

namespace PurpleSpider\PageTypeTester\Model;

/**
 * An edit form for one record within a ModelAdmin section, or its form for adding a
 * new record.
 */
class AdminEditLink
{
    public function __construct(
        public readonly string $modelName,
        public readonly string $url,
        public readonly string $recordTitle,
        public readonly int $index,
        public readonly bool $isNew = false
    ) {
    }
}
