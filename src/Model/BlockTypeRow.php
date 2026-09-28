<?php

namespace PurpleSpider\PageTypeTester\Model;

use SilverStripe\ORM\DataObject;

/**
 * One Elemental block type, together with the example block chosen to represent it.
 *
 * As with PageTypeRow, rows with no example block have an index of -1, and every other
 * row's index addresses its status placeholders in the markup.
 *
 * The block is typed as a DataObject so this class does not name Elemental, which is
 * optional.
 */
class BlockTypeRow
{
    public function __construct(
        public readonly string $class,
        public readonly string $shortClass,
        public readonly string $singularName,
        public readonly int $liveCount,
        public readonly int $totalCount,
        public readonly int $index = -1,
        public readonly ?DataObject $element = null,
        public readonly string $title = '',
        public readonly string $pageTitle = '',
        public readonly string $pageLink = '',
        public readonly string $pageCmsLink = '',
        public readonly string $editorCheckUrl = '',
        public readonly string $editFormUrl = '',
        public readonly string $frontendUrl = '',
        public readonly bool $frontendNeedsLogin = false
    ) {
    }

    public function hasElement(): bool
    {
        return $this->element !== null;
    }

    public function getDraftOnlyCount(): int
    {
        return max(0, $this->totalCount - $this->liveCount);
    }
}
