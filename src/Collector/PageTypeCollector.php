<?php

namespace PurpleSpider\PageTypeTester\Collector;

use Page;
use PurpleSpider\PageTypeTester\ElementalSupport;
use PurpleSpider\PageTypeTester\EmailUsageFinder;
use PurpleSpider\PageTypeTester\ExpectedStatus;
use PurpleSpider\PageTypeTester\GridFieldFormFinder;
use PurpleSpider\PageTypeTester\Model\PageTypeRow;
use SilverStripe\Admin\AdminRootController;
use SilverStripe\CMS\Controllers\CMSPageSettingsController;
use SilverStripe\CMS\Model\RedirectorPage;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use SilverStripe\VersionedAdmin\Controllers\CMSPageHistoryViewerController;
use SilverStripe\VersionedAdmin\Controllers\HistoryViewerController;

/**
 * Builds the list of page types, each with a representative page to test against.
 */
class PageTypeCollector
{
    private readonly EmailUsageFinder $emailFinder;

    public function __construct(private readonly bool $randomise = false, ?EmailUsageFinder $emailFinder = null)
    {
        $this->emailFinder = $emailFinder ?? new EmailUsageFinder();
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
     * The page's Settings and History screens in the CMS, which are separate requests
     * from its edit form and can fail on their own.
     *
     * History is checked twice under one label, because its screen is an empty shell
     * that answers 200 either way. The list of versions is fetched afterwards, and that
     * is the part that usually fails.
     *
     * @return array<int, array{label: string, url: string, link: string}> Each check's
     *         label, the URL requested, and the screen to open when it fails.
     */
    public static function cmsScreenChecksFor(SiteTree $page): array
    {
        $id = (int) $page->ID;

        $settings = static::adminUrl(CMSPageSettingsController::class, 'show/' . $id);
        $checks = [['label' => 'Settings', 'url' => $settings, 'link' => $settings]];

        // History comes from silverstripe/versioned-admin, which a site can do without.
        if (class_exists(CMSPageHistoryViewerController::class) && class_exists(HistoryViewerController::class)) {
            $history = static::adminUrl(CMSPageHistoryViewerController::class, 'show/' . $id);
            $versions = static::adminUrl(HistoryViewerController::class, 'api/read')
                . '?' . http_build_query(['id' => $id, 'dataClass' => $page->ClassName]);

            $checks[] = ['label' => 'History', 'url' => $history, 'link' => $history];
            $checks[] = ['label' => 'History', 'url' => $versions, 'link' => $history];
        }

        return $checks;
    }

    private static function adminUrl(string $controllerClass, string $action): string
    {
        $segment = (string) Config::inst()->get($controllerClass, 'url_segment');

        return Director::absoluteURL(AdminRootController::admin_url(Controller::join_links($segment, $action)));
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
        $emailUsages = $this->emailFinder->forPageType($class);

        if (!$page) {
            return new PageTypeRow(
                $class,
                $shortClass,
                $data['liveCount'],
                $data['totalCount'],
                $actions,
                $expected,
                $index,
                emailUsages: $emailUsages
            );
        }

        $frontendLink = static::frontendLinkFor($page, $baseUrl);

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
            static::pageUrlFor($frontendLink, $baseUrl),
            ElementalSupport::blockListUrlsFor($page),
            $emailUsages,
            static::cmsScreenChecksFor($page),
            GridFieldFormFinder::gridFieldsFor($page),
            !$page->isPublished()
        );
    }

    private function selectPage(DataList $livePages, DataList $allPages, int $liveCount): ?SiteTree
    {
        // Prefer a published page, since that is what visitors actually see.
        $source = $liveCount > 0 ? $livePages : $allPages;

        return ($this->randomise ? $source->shuffle() : $source)->first();
    }

    /**
     * The page's frontend URL. A page only in draft, such as one the report created,
     * is 404 on the live site, which would hide whatever its draft really does, so its
     * link views the draft stage instead. That needs a CMS login, so it reports as
     * login required rather than failed where there is none.
     */
    public static function frontendLinkFor(SiteTree $page, string $baseUrl): string
    {
        $link = (string) $page->AbsoluteLink();

        // RedirectorPage::AbsoluteLink() returns the redirect destination rather than
        // the page's own URL, so build its URL from the parent instead.
        if ($page instanceof RedirectorPage) {
            $parent = $page->Parent();
            $parentLink = $parent && $parent->exists() ? $parent->AbsoluteLink() : $baseUrl;
            $link = Controller::join_links($parentLink, $page->URLSegment);
        }

        return $page->isPublished() ? $link : Controller::join_links($link, '?stage=Stage');
    }

    /**
     * The page's path, shown under its title, without the draft stage's query string.
     */
    public static function pageUrlFor(string $frontendLink, string $baseUrl): string
    {
        $link = strtok($frontendLink, '?') ?: $frontendLink;

        return '/' . ltrim(str_replace(rtrim($baseUrl, '/'), '', $link), '/');
    }
}
