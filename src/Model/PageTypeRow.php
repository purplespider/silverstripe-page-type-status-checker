<?php

namespace PurpleSpider\PageTypeTester\Model;

use SilverStripe\CMS\Model\SiteTree;

/**
 * One page type, together with the example page chosen to represent it.
 *
 * Rows with no example page have an index of -1 and are rendered as a "create one"
 * prompt. Every other row's index addresses its status placeholders in the markup.
 */
class PageTypeRow
{
    /**
     * @param string[] $allowedActions
     * @param int[] $expectedStatus
     */
    public function __construct(
        public readonly string $class,
        public readonly string $shortClass,
        public readonly int $liveCount,
        public readonly int $totalCount,
        public readonly array $allowedActions,
        public readonly array $expectedStatus,
        public readonly int $index = -1,
        public readonly ?SiteTree $page = null,
        public readonly string $title = '',
        public readonly string $cmsLink = '',
        public readonly string $frontendLink = '',
        public readonly string $pageUrl = ''
    ) {
    }

    public function hasPage(): bool
    {
        return $this->page !== null;
    }

    public function getDraftOnlyCount(): int
    {
        return max(0, $this->totalCount - $this->liveCount);
    }
}
