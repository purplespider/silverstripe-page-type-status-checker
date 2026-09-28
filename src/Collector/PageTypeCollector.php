<?php

namespace PurpleSpider\PageTypeTester\Collector;

use Page;
use PurpleSpider\PageTypeTester\ElementalSupport;
use PurpleSpider\PageTypeTester\ExpectedStatus;
use PurpleSpider\PageTypeTester\Model\PageTypeRow;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * Builds the list of page types, each with a representative page to test against.
 */
class PageTypeCollector
{
    public function __construct(private readonly bool $randomise = false)
    {
    }

    /**
     * @return PageTypeRow[] Ordered by number of pages, most common first.
     */
    public function collect(): array
    {
        $baseUrl = Director::absoluteBaseURL();

        $found = [];
        foreach (ClassInfo::subclassesFor(Page::class) as $class) {
            $found[] = $this->inspect($class);
        }

        usort($found, fn(array $a, array $b) => $b['totalCount'] <=> $a['totalCount']);

        $rows = [];
        $index = 0;
        foreach ($found as $data) {
            $rows[] = $this->buildRow($data, $data['page'] ? $index++ : -1, $baseUrl);
        }

        return $rows;
    }

    /**
     * @return string[]
     */
    public static function allowedActionsFor(string $pageClass): array
    {
        $controllerClass = $pageClass . 'Controller';
        if (!class_exists($controllerClass)) {
            return [];
        }

        // UNINHERITED so that actions defined on a shared base controller are not
        // reported against every page type that happens to extend it.
        $actions = Config::inst()->get($controllerClass, 'allowed_actions', Config::UNINHERITED);
        if (!$actions || !is_array($actions)) {
            return [];
        }

        $allowed = [];
        foreach ($actions as $key => $value) {
            // Both ['action'] and ['action' => 'PERMISSION'] forms are valid.
            $action = is_int($key) ? $value : $key;

            // index is the page itself, which the frontend check already covers.
            // RedirectorPageController lists it, and its page has no HTML to search.
            if (strtolower($action) === 'index') {
                continue;
            }

            $allowed[] = $action;
        }

        return $allowed;
    }

    /**
     * Sorting needs the counts and the row index needs the sort order, so counting is
     * a separate pass from building the rows.
     */
    private function inspect(string $class): array
    {
        $livePages = Versioned::get_by_stage($class, Versioned::LIVE)->filter('ClassName', $class);
        $allPages = DataObject::get($class)->filter('ClassName', $class);

        $liveCount = (int) $livePages->count();
        $totalCount = (int) $allPages->count();

        return [
            'class' => $class,
            'liveCount' => $liveCount,
            'totalCount' => $totalCount,
            'page' => $this->selectPage($livePages, $allPages, $liveCount),
        ];
    }

    private function buildRow(array $data, int $index, string $baseUrl): PageTypeRow
    {
        $class = $data['class'];
        $page = $data['page'];
        $shortClass = ClassInfo::shortName($class);

        $actions = static::allowedActionsFor($class);
        $expected = ExpectedStatus::forShortName($shortClass);

        if (!$page) {
            return new PageTypeRow(
                $class,
                $shortClass,
                $data['liveCount'],
                $data['totalCount'],
                $actions,
                $expected,
                $index
            );
        }

        $frontendLink = $this->frontendLinkFor($page, $shortClass, $baseUrl);

        return new PageTypeRow(
            $class,
            $shortClass,
            $data['liveCount'],
            $data['totalCount'],
            $actions,
            $expected,
            $index,
            $page,
            (string) $page->Title,
            Controller::join_links($baseUrl, 'admin/pages/edit/show', $page->ID),
            $frontendLink,
            '/' . ltrim(str_replace(rtrim($baseUrl, '/'), '', $frontendLink), '/'),
            ElementalSupport::blockListUrlsFor($page)
        );
    }

    private function selectPage(DataList $livePages, DataList $allPages, int $liveCount): ?SiteTree
    {
        // Prefer a published page, since that is what visitors actually see.
        $source = $liveCount > 0 ? $livePages : $allPages;

        return ($this->randomise ? $source->shuffle() : $source)->first();
    }

    /**
     * RedirectorPage::AbsoluteLink() returns the redirect destination rather than the
     * page's own URL, so build its URL from the parent instead.
     */
    private function frontendLinkFor(SiteTree $page, string $shortClass, string $baseUrl): string
    {
        if ($shortClass !== 'RedirectorPage') {
            return (string) $page->AbsoluteLink();
        }

        $parent = $page->Parent();
        $parentLink = $parent && $parent->exists() ? $parent->AbsoluteLink() : $baseUrl;

        return Controller::join_links($parentLink, $page->URLSegment);
    }
}
