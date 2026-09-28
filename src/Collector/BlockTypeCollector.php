<?php

namespace PurpleSpider\PageTypeTester\Collector;

use DNADesign\Elemental\Models\BaseElement;
use PurpleSpider\PageTypeTester\BlockEditorChecker;
use PurpleSpider\PageTypeTester\BlockRenderer;
use PurpleSpider\PageTypeTester\ElementalSupport;
use PurpleSpider\PageTypeTester\Model\BlockTypeRow;
use ReflectionClass;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * Builds the list of Elemental block types, each with a representative block to test
 * against. Only used when Elemental is installed.
 */
class BlockTypeCollector
{
    /**
     * How many blocks of a type to try before giving up on finding one that sits on a
     * page. Blocks left behind by a deleted page have nowhere to be shown, and a site
     * can have a lot of them.
     */
    private const MAX_CANDIDATES = 20;

    public function __construct(private readonly bool $randomise = false)
    {
    }

    /**
     * @return BlockTypeRow[] Ordered by number of blocks, most common first.
     */
    public function collect(): array
    {
        if (!ElementalSupport::isInstalled()) {
            return [];
        }

        $found = [];
        foreach (ClassInfo::subclassesFor(BaseElement::class) as $class) {
            if ($class === BaseElement::class || (new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $found[] = $this->inspect($class);
        }

        usort($found, fn(array $a, array $b) => $b['totalCount'] <=> $a['totalCount']);

        $rows = [];
        $index = 0;
        foreach ($found as $data) {
            $rows[] = $this->buildRow($data, $data['example'] ? $index++ : -1);
        }

        return $rows;
    }

    private function inspect(string $class): array
    {
        $liveCount = (int) Versioned::get_by_stage($class, Versioned::LIVE)->filter('ClassName', $class)->count();
        $totalCount = (int) Versioned::get_by_stage($class, Versioned::DRAFT)->filter('ClassName', $class)->count();

        return [
            'class' => $class,
            'liveCount' => $liveCount,
            'totalCount' => $totalCount,
            'example' => $totalCount > 0 ? $this->selectExample($class, $liveCount) : null,
        ];
    }

    private function buildRow(array $data, int $index): BlockTypeRow
    {
        $class = $data['class'];
        $shortClass = ClassInfo::shortName($class);
        $singularName = (string) singleton($class)->i18n_singular_name();

        $example = $data['example'];
        if (!$example) {
            return new BlockTypeRow($class, $shortClass, $singularName, $data['liveCount'], $data['totalCount']);
        }

        $id = (int) $example['element']->ID;

        return new BlockTypeRow(
            $class,
            $shortClass,
            $singularName,
            $data['liveCount'],
            $data['totalCount'],
            $index,
            $example['element'],
            $example['title'],
            $example['pageTitle'],
            $example['pageLink'],
            $example['pageCmsLink'],
            ElementalSupport::taskEndpointUrl(BlockEditorChecker::PARAM, $id),
            $example['editFormUrl'],
            ElementalSupport::taskEndpointUrl(BlockRenderer::PARAM, $id),
            !$example['published']
        );
    }

    /**
     * Prefers a published block, since that is what visitors actually see, and falls
     * back to a draft one.
     *
     * Each stage is read on its own terms: the block's page is looked up in the same
     * stage as the block, so a published block on a page that is only in draft is not
     * picked as a published example.
     */
    private function selectExample(string $class, int $liveCount): ?array
    {
        $stages = $liveCount > 0 ? [Versioned::LIVE, Versioned::DRAFT] : [Versioned::DRAFT];

        foreach ($stages as $stage) {
            $example = Versioned::withVersionedMode(function () use ($class, $stage) {
                Versioned::set_stage($stage);

                $blocks = DataObject::get($class)->filter(['ClassName' => $class, 'ParentID:GreaterThan' => 0]);
                if ($this->randomise) {
                    $blocks = $blocks->shuffle();
                }

                foreach ($blocks->limit(self::MAX_CANDIDATES) as $block) {
                    $page = $block->getPage();
                    if ($page) {
                        return $this->describe($block, $page, $stage === Versioned::LIVE);
                    }
                }

                return null;
            });

            if ($example) {
                return $example;
            }
        }

        return null;
    }

    /**
     * Resolves everything that depends on the reading stage while it is still set.
     */
    private function describe(DataObject $block, DataObject $page, bool $published): array
    {
        $baseUrl = Director::absoluteBaseURL();

        $pageCmsLink = '';
        if ($page instanceof SiteTree) {
            $pageCmsLink = Controller::join_links($baseUrl, 'admin/pages/edit/show', $page->ID);
        } elseif ($page->hasMethod('CMSEditLink')) {
            $pageCmsLink = (string) Director::absoluteURL((string) $page->CMSEditLink());
        }

        $pageLink = (string) $block->Link();
        $editFormLink = (string) $block->getCMSEditLink(true);

        return [
            'element' => $block,
            'published' => $published,
            'title' => (string) ($block->Title ?: 'Untitled block #' . $block->ID),
            'pageTitle' => (string) $page->getTitle(),
            'pageLink' => $pageLink === '' ? '' : (string) Director::absoluteURL($pageLink),
            'pageCmsLink' => $pageCmsLink,
            'editFormUrl' => $editFormLink === '' ? '' : (string) Director::absoluteURL($editFormLink),
        ];
    }
}
