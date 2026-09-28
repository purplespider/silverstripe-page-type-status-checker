<?php

namespace PurpleSpider\PageTypeTester\Report;

use PurpleSpider\PageTypeTester\ActionLinkFinder;
use PurpleSpider\PageTypeTester\BlockCreator;
use PurpleSpider\PageTypeTester\Model\AdminEditLink;
use PurpleSpider\PageTypeTester\Model\AdminSection;
use PurpleSpider\PageTypeTester\Model\BlockTypeRow;
use PurpleSpider\PageTypeTester\Model\EmailUsage;
use PurpleSpider\PageTypeTester\Model\PageTypeRow;
use PurpleSpider\PageTypeTester\PageCreator;
use PurpleSpider\PageTypeTester\PageDeleter;
use PurpleSpider\PageTypeTester\ReportMeta;
use PurpleSpider\PageTypeTester\UrlChecker;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Security\SecurityToken;

/**
 * Renders the browser report.
 *
 * The stylesheet and script live in client/dist as ordinary files. Only a JSON
 * configuration payload is generated here, so nothing is interpolated into
 * JavaScript and every value taken from the database or the query string is escaped
 * on the way out.
 */
class HtmlReport
{
    use Configurable;

    private const MODULE = 'purplespider/silverstripe-page-type-status-checker';

    /**
     * How many checks the browser runs at once. The checks are independent, so running
     * them strictly one at a time makes a site with many page types slow to report on.
     *
     * @config
     */
    private static int $check_concurrency = 6;

    /**
     * @param int[] $createdPageIds IDs of pages made by the Create buttons this session
     */
    public function __construct(
        private readonly UrlChecker $checker,
        private readonly string $liveDomain = '',
        private readonly bool $randomise = false,
        private readonly array $createdPageIds = []
    ) {
    }

    /**
     * @param PageTypeRow[] $rows
     * @param BlockTypeRow[] $blockRows Empty when Elemental is not installed or blocks are skipped.
     * @param AdminSection[] $adminSections
     */
    public function render(
        PolyOutput $output,
        array $rows,
        array $blockRows,
        array $adminSections,
        bool $skipAdmin
    ): void {
        $output->writeForHtml($this->assets());
        $output->writeForHtml($this->iconSprite());
        $output->writeForHtml("<div class='ptl-wrap" . ($this->liveDomain !== '' ? ' ptl-comparing' : '') . "'>");
        $output->writeForHtml($this->loginBanner());
        $output->writeForHtml($this->toolbar());
        $output->writeForHtml($this->pageTypeTable($rows));

        if ($blockRows) {
            $output->writeForHtml($this->blockTypeTable($blockRows));
        }

        if (!$skipAdmin) {
            $output->writeForHtml($this->adminTable($adminSections));
        }

        $output->writeForHtml($this->liveDomainSection());
        $output->writeForHtml($this->helpSection());
        $output->writeForHtml("</div>");
        $output->writeForHtml($this->configPayload($rows, $blockRows, $adminSections));
    }

    public function renderHeader(PolyOutput $output, string $title): void
    {
        $badges = '';
        foreach ([ReportMeta::siteName(), ReportMeta::cmsVersion()] as $meta) {
            if ($meta !== '') {
                $badges .= "<span class='ptl-meta'>" . $this->esc($meta) . "</span>";
            }
        }

        $output->writeForHtml(
            "<div class='ptl-header'><div>"
            . "<div class='ptl-header-title'><h1>" . $this->esc($title) . "</h1>" . $badges . "</div>"
            . "<p class='ptl-desc'>Checks the HTTP status code of the frontend and CMS edit form for each page "
            . "type.</p></div>"
            . "<output id='ptl-summary' aria-live='polite'></output></div>"
        );
    }

    private function assets(): string
    {
        $css = ModuleResourceLoader::resourceURL(self::MODULE . ':client/dist/page-type-tester.css');
        $js = ModuleResourceLoader::resourceURL(self::MODULE . ':client/dist/page-type-tester.js');

        return "<link rel='stylesheet' href='" . $this->esc($css) . "'>"
            . "<script src='" . $this->esc($js) . "' defer></script>";
    }

    /**
     * Icons are inline SVG rather than a CDN icon font. A blocked or offline CDN would
     * otherwise strip every tick and cross, leaving colour as the only pass/fail cue.
     */
    private function iconSprite(): string
    {
        $paths = [
            'check' => 'M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z',
            'cross' => 'M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41'
                . ' 17.59 19 19 17.59 13.41 12z',
            'lock' => 'M18 8h-1V6A5 5 0 0 0 7 6v2H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V10a2 2 0 0'
                . ' 0-2-2zM9 6a3 3 0 0 1 6 0v2H9zm3 12a2 2 0 1 1 2-2 2 2 0 0 1-2 2z',
            'warning' => 'M1 21h22L12 2zm12-3h-2v-2h2zm0-4h-2v-4h2z',
            'document' => 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zm2 16H8v-2h8zm0-4H8v-2h8z'
                . 'm-3-5V3.5L18.5 9z',
            'plus' => 'M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6z',
            'trash' => 'M6 19a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7H6zM19 4h-3.5l-1-1h-5l-1 1H5v2h14z',
            'compare' => 'M3 5h8v14H3zm10 0h8v14h-8z',
            'desktop' => 'M20 3H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h5v2H7v2h10v-2h-2v-2h5a2 2 0 0 0 2-2V5a2 2 0'
                . ' 0 0-2-2zm0 12H4V5h16z',
            'eye' => 'M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11'
                . '-7.5zm0 12a4.5 4.5 0 1 1 4.5-4.5 4.5 4.5 0 0 1-4.5 4.5zm0-7a2.5 2.5 0 1 0 2.5 2.5A2.5 2.5 0'
                . ' 0 0 12 9.5z',
            'eye-slash' => 'M12 6.5a4.5 4.5 0 0 1 4.5 4.5 4.4 4.4 0 0 1-.32 1.64l2.63 2.63A10.6 10.6 0 0 0 23 11'
                . 'c-1.73-4.39-6-7.5-11-7.5a10.9 10.9 0 0 0-3.65.62l1.94 1.94A4.4 4.4 0 0 1 12 6.5zM2.7 3.4 1.3'
                . ' 4.8l2.2 2.2A10.7 10.7 0 0 0 1 11c1.73 4.39 6 7.5 11 7.5a10.8 10.8 0 0 0 4-.77l3.2 3.2 1.4-1.4z'
                . 'M7.5 11a4.5 4.5 0 0 0 6 4.25l-1.6-1.6A2.5 2.5 0 0 1 9.35 11z',
            'shuffle' => 'M17 3v2h1.59l-4.3 4.29 1.42 1.42L20 6.41V8h2V3zm-11 .59L4.59 5 8.3 8.71l1.41-1.42zM17'
                . ' 16v2h1.59l-14 14h2.82L20 19.41V21h2v-5zM4.59 19 3.17 20.41 6.88 24.12 8.29 22.7z',
            'reset' => 'M12 5V1L7 6l5 5V7a6 6 0 1 1-6 6H4a8 8 0 1 0 8-8z',
            'stop' => 'M6 6h12v12H6z',
            'globe' => 'M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm7 6h-2.9a15.6 15.6 0 0 0-1.4-3.6A8 8 0 0 1 19'
                . ' 8zM12 4a14 14 0 0 1 1.9 4h-3.8A14 14 0 0 1 12 4zM4.3 14a7.8 7.8 0 0 1 0-4h3.3a16.4 16.4 0 0'
                . ' 0 0 4zm.7 2h2.9a15.6 15.6 0 0 0 1.4 3.6A8 8 0 0 1 5 16zm2.9-8H5a8 8 0 0 1 4.3-3.6A15.6 15.6 0'
                . ' 0 0 7.9 8zM12 20a14 14 0 0 1-1.9-4h3.8A14 14 0 0 1 12 20zm2.3-6H9.7a14.6 14.6 0 0 1 0-4h4.6a'
                . '14.6 14.6 0 0 1 0 4zm.4 5.6a15.6 15.6 0 0 0 1.4-3.6H19a8 8 0 0 1-4.3 3.6zM16.4 14a16.4 16.4 0'
                . ' 0 0 0-4h3.3a7.8 7.8 0 0 1 0 4z',
            'login' => 'M11 7 9.6 8.4 12.2 11H2v2h10.2l-2.6 2.6L11 17l5-5zM20 19h-8v2h8a2 2 0 0 0 2-2V5a2 2 0 0'
                . ' 0-2-2h-8v2h8z',
            'spinner' => 'M12 2a10 10 0 0 1 10 10h-2a8 8 0 0 0-8-8z',
            'check-double' => 'M18 7 16.6 5.6 9 13.2l1.4 1.4zM22.2 5.6 10.4 17.4l-4.6-4.6L4.4 14.2l6 6 13.2-13.2z'
                . 'M0 14.2l6 6 1.4-1.4-6-6z',
            'mail' => 'M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 4-8 5-8-5V6l8 5 8-5z',
            'external' => 'M19 19H5V5h7V3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7h-2zM14 3v2h3.6l-9.8'
                . ' 9.8 1.4 1.4L19 6.4V10h2V3z',
        ];

        $symbols = '';
        foreach ($paths as $name => $path) {
            $symbols .= "<symbol id='ptl-i-{$name}' viewBox='0 0 24 24'><path d='{$path}'/></symbol>";
        }

        return "<svg xmlns='http://www.w3.org/2000/svg' style='display:none' aria-hidden='true'>{$symbols}</svg>";
    }

    private function icon(string $name, string $class = ''): string
    {
        return "<svg class='ptl-icon " . $this->esc($class) . "' aria-hidden='true' focusable='false'>"
            . "<use href='#ptl-i-{$name}'></use></svg>";
    }

    private function loginBanner(): string
    {
        return "<div id='ptl-login-banner' class='ptl-login-banner' role='status'>"
            . "<svg class='ptl-icon ptl-login-icon' aria-hidden='true' focusable='false'>"
            . "<use href='#ptl-i-lock'></use></svg>"
            . "<div class='ptl-login-banner-text'>"
            . "<strong>You are not logged in to the CMS</strong>"
            . "<p>CMS and admin URLs are redirecting to the login screen, so they cannot be checked and are "
            . "marked <em>login</em> below. Frontend pages are still being checked as normal.</p>"
            . "</div>"
            . "<div class='ptl-login-banner-actions'>"
            . "<button type='button' data-ptl-action='login' class='ptl-btn ptl-btn-primary' "
            . "style='min-width:0;'>" . $this->icon('login') . " Log in to the CMS</button>"
            . "<button type='button' data-ptl-action='reload' class='ptl-btn'>"
            . $this->icon('reset') . " Re-run checks</button>"
            . "</div></div>";
    }

    private function toolbar(): string
    {
        $html = "<div class='ptl-toolbar'>";

        $html .= "<div class='ptl-btn-group'>"
            . "<button type='button' id='ptl-check-actions-btn' data-ptl-action='check' data-ptl-with-actions='1' "
            . "class='ptl-btn ptl-btn-primary'>" . $this->icon('check-double') . " Check Links &amp; Actions</button>"
            . "<button type='button' id='ptl-check-btn' data-ptl-action='check' data-ptl-with-actions='0' "
            . "class='ptl-btn ptl-btn-secondary'>" . $this->icon('check') . " Check Links Only</button>"
            . "</div>";

        $html .= "<div class='ptl-divider'></div>";

        $html .= "<div class='ptl-btn-group'>"
            . "<button type='button' data-ptl-action='randomise' class='ptl-btn'>"
            . $this->icon('shuffle') . " Randomise</button>";

        if ($this->randomise) {
            $html .= "<button type='button' data-ptl-action='reset' class='ptl-btn'>"
                . $this->icon('reset') . " Reset</button>";
        }

        $html .= "</div>";

        $html .= $this->deleteCreatedButton();

        return $html . "</div>";
    }

    /**
     * A table in a panel, under a heading bar with the controls that act on it.
     *
     * The controls sit with their table rather than in the toolbar, which keeps the
     * toolbar to things that act on the whole report (check, pick, delete) and short
     * enough for one line. Heading, controls and table share one panel so it is plain
     * which table the controls belong to.
     */
    private function tablePanel(string $tableId, string $heading, string $tools, string $table): string
    {
        $headingId = $tableId . '-heading';

        return "<section class='ptl-panel' aria-labelledby='{$headingId}'>"
            . "<div class='ptl-table-bar'>"
            . "<h2 id='{$headingId}'>" . $this->esc($heading) . "</h2>"
            . "<div class='ptl-table-tools'>{$tools}</div>"
            . "</div>{$table}</section>";
    }

    /**
     * Buttons that open every link of one kind in the table they sit above, each in a
     * new tab. The script holds the URLs, keyed by the button's data-ptl-links.
     *
     * @param array<string, string> $buttons Link kind => visible label.
     * @param string $label What the table lists, for the hidden part of the button names.
     */
    private function openAllTools(array $buttons, string $label): string
    {
        $html = "<div class='ptl-btn-group'>";
        foreach ($buttons as $links => $text) {
            $html .= "<button type='button' data-ptl-action='open-all' data-ptl-links='" . $this->esc($links)
                . "' class='ptl-btn'>" . $this->icon('external') . ' ' . $this->esc($text)
                . "<span class='ptl-sr-only'> for " . $this->esc($label) . "</span></button>";
        }

        return $html . "</div>";
    }

    /**
     * @param PageTypeRow[] $rows
     */
    private function pageTypeTable(array $rows): string
    {
        $html = "<table class='ptl-table' id='ptl-page-types'>"
            . "<caption class='ptl-sr-only'>Page types with their CMS and frontend status</caption>"
            . "<thead><tr>"
            . "<th scope='col' class='ptl-tested-col ptl-tested-ui'>Tested</th>"
            . "<th scope='col' class='ptl-preview-col'>Preview</th>"
            . "<th scope='col'>Page Type</th>"
            . "<th scope='col'>Count</th>"
            . "<th scope='col'>CMS Edit Form</th>"
            . "<th scope='col'>Frontend</th>"
            . "<th scope='col'>Example Page</th>"
            . "</tr></thead><tbody>";

        foreach ($rows as $row) {
            $html .= $row->hasPage() ? $this->pageRow($row) : $this->emptyRow($row);
        }

        $tools = $this->openAllTools(['cms' => 'Open All CMS', 'frontend' => 'Open All Frontend'], 'page types')
            . "<button type='button' data-ptl-action='toggle-previews' aria-pressed='false' class='ptl-btn'>"
            . $this->icon('eye') . " Show Previews</button>"
            . $this->testedTools('ptl-page-types', 'page types');

        return $this->tablePanel('ptl-page-types', 'Page Types', $tools, $html . "</tbody></table>");
    }

    private function pageRow(PageTypeRow $row): string
    {
        $shortClass = $this->esc($row->shortClass);
        $draftOnly = $row->getDraftOnlyCount();

        $count = "<span class='ptl-count'>{$row->liveCount}"
            . ($draftOnly > 0 ? " <span class='ptl-count-draft'>+ {$draftOnly}</span>" : '')
            . "<span class='ptl-tip ptl-tip-above'>{$row->liveCount} live, {$draftOnly} draft only</span></span>";

        // Actions and detected forms share one list, each filled in by the script.
        $actionsContainer = "<div class='ptl-actions-container'>"
            . "<span id='actions-container-{$row->index}' class='ptl-actions-part'></span>"
            . "<span id='forms-container-{$row->index}' class='ptl-actions-part'></span>"
            . $this->emailPart($row->emailUsages) . "</div>";

        // Only pages this report created can be deleted again, so only those get a button.
        $deleteButton = in_array((int) $row->page->ID, $this->createdPageIds, true)
            ? $this->deleteButton((int) $row->page->ID, $row->index, $row->title)
            : '';

        $cmsLink = $this->cellLink(
            $row->cmsLink,
            'Edit in CMS',
            $row->shortClass,
            'ptl-cms',
            'desktop',
            ['This CMS', "edit {$row->shortClass} on this site"]
        );

        $frontendLink = $this->cellLink(
            $row->frontendLink,
            'View Page',
            $row->shortClass,
            'ptl-frontend',
            'desktop',
            ['This page', "view {$row->shortClass} on this site"]
        );

        // Rendered whether or not a live domain is set, and shown by the ptl-comparing
        // class, so the script can set or clear the domain without reloading the report.
        $liveCmsPath = '/admin/pages/edit/show/' . $row->page->ID;
        $liveFrontendPath = $row->pageUrl;

        $liveCmsLink = $this->liveLink(
            $liveCmsPath,
            'Live CMS',
            "edit {$row->shortClass} on the live site",
            'ptl-cms'
        );
        $liveFrontendLink = $this->liveLink(
            $liveFrontendPath,
            'Live page',
            "view {$row->shortClass} on the live site",
            'ptl-frontend'
        );

        $compareCms = $this->compareButton($row->cmsLink, $liveCmsPath, $row->title . ' in the CMS');
        $compareFrontend = $this->compareButton($row->frontendLink, $liveFrontendPath, $row->title);

        $cmsCell = $this->linkCell(
            "<span id='cms-status-{$row->index}' class='ptl-status'>"
            . "<span class='ptl-status-placeholder'>?</span></span>",
            $cmsLink,
            $liveCmsLink,
            $compareCms
        );

        $frontendCell = $this->linkCell(
            "<span id='frontend-status-{$row->index}' class='ptl-status'>"
            . "<span class='ptl-status-placeholder'>?</span></span>",
            $frontendLink,
            $liveFrontendLink,
            $compareFrontend
        );

        return "<tr>"
            . $this->testedCell($row->class, $row->shortClass)
            . "<td class='ptl-preview-col'><div class='ptl-preview'>"
            . "<iframe title='Preview of " . $this->esc($row->title) . "' data-src='"
            . $this->esc($row->frontendLink) . "'></iframe></div></td>"
            . "<td><span class='ptl-type'>{$shortClass}</span></td>"
            . "<td>{$count}</td>"
            . "<td>{$cmsCell}{$this->gridCards($row)}</td>"
            . "<td>{$frontendCell}{$actionsContainer}</td>"
            . "<td class='ptl-example-cell'><span class='ptl-title'>" . $this->esc($row->title)
            . $deleteButton . "</span>"
            . "<span class='ptl-url'>" . $this->esc($row->pageUrl) . "</span></td>"
            . "</tr>";
    }

    private function emptyRow(PageTypeRow $row): string
    {
        $shortClass = $this->esc($row->shortClass);

        // The actions to check once a page exists, listed with the email usages as they
        // would be on a row with a page. Mirrored by buildEmptyRowHtml in the script.
        $actions = '';
        foreach ($row->allowedActions as $action) {
            $actions .= "<span class='ptl-status'><span class='ptl-action-badge'>" . $this->icon('warning')
                . " action<span class='ptl-sr-only'>: create a page of this type to check it</span>"
                . "<span class='ptl-tip ptl-tip-above' aria-hidden='true'>Create a page of this type to check"
                . " this action</span></span></span>"
                . "<span class='ptl-action-missing'>/" . $this->esc($action) . "</span>";
        }

        $button = "<button type='button' class='ptl-create-btn' data-ptl-action='create-page' "
            . "data-ptl-class='" . $this->esc($row->class) . "' data-ptl-short='{$shortClass}'>"
            . $this->icon('plus') . " Create {$shortClass}</button>";

        $list = "<div class='ptl-actions-container'><span class='ptl-actions-part'>{$actions}</span>"
            . $this->emailPart($row->emailUsages) . "</div>";

        return "<tr>"
            . $this->testedCell($row->class, $row->shortClass)
            . "<td class='ptl-preview-col'><div class='ptl-preview-empty'>No preview</div></td>"
            . "<td><span class='ptl-type'>{$shortClass}</span></td>"
            . "<td><span class='ptl-count'>0</span></td>"
            . "<td colspan='3'><div class='ptl-empty-content'>{$button}{$list}</div></td>"
            . "</tr>";
    }

    /**
     * The "I have tested this by hand" tick.
     *
     * Page types are keyed by class rather than page ID, because what gets tested is
     * the type: Randomise swaps the example page, and the tick should survive that.
     * Types with no pages get one too, so every type can be signed off, including one
     * that has been checked and found unused. Admin sections are keyed by URL.
     *
     * The ticks themselves live in the browser, so the checkbox is rendered unticked
     * and the script fills it in, along with the date.
     */
    private function testedCell(string $key, string $label): string
    {
        return "<td class='ptl-tested-col ptl-tested-ui'><label class='ptl-tested'>"
            . "<input type='checkbox' data-ptl-tested='" . $this->esc($key) . "'>"
            . "<span class='ptl-sr-only'>" . $this->esc($label) . " tested</span>"
            . "<span class='ptl-tested-date'></span></label></td>";
    }

    /**
     * "3 of 12 tested", Hide Tested and Clear for one table. Each table has its own set,
     * so the controls act on the table they sit above and nothing else. The count is
     * filled in by the script.
     *
     * @param string $label What the table lists, for the hidden part of the button names.
     */
    private function testedTools(string $tableId, string $label): string
    {
        $table = " data-ptl-table='{$tableId}' data-ptl-label='" . $this->esc($label) . "'";

        return "<div class='ptl-tested-tools ptl-tested-ui'>"
            . "<span class='ptl-tested-progress' data-ptl-progress-for='{$tableId}'></span>"
            . "<div class='ptl-btn-group'>"
            . "<button type='button' data-ptl-action='toggle-hide-tested'{$table} aria-pressed='false' class='ptl-btn'>"
            . $this->icon('eye-slash') . " Hide Tested<span class='ptl-sr-only'> " . $this->esc($label)
            . "</span></button>"
            . "<button type='button' data-ptl-action='clear-tested'{$table} class='ptl-btn'>"
            . $this->icon('reset') . " Clear<span class='ptl-sr-only'> tested marks from "
            . $this->esc($label) . "</span></button>"
            . "</div></div>";
    }

    /**
     * Status badge, then the two site links stacked, then Compare.
     *
     * Three items across rather than four in a line that wraps wherever the column runs
     * out. Compare sits beside the stack instead of after the live link because it acts
     * on the pair, not on one of them.
     */
    private function linkCell(string $status, string $localRow, string $liveRow, string $compare): string
    {
        $live = $liveRow === '' ? '' : "<div class='ptl-link-row ptl-when-comparing'>{$liveRow}</div>";

        // The rule marks where the pair ends. Compare belongs to both rows, so it sits
        // the other side of it rather than lining up with either one.
        $compareCell = $compare === ''
            ? ''
            : "<span class='ptl-compare-wrap ptl-when-comparing'>{$compare}</span>";

        return "<div class='ptl-link-cell'>{$status}<div class='ptl-link-stack'>"
            . "<div class='ptl-link-row'>{$localRow}</div>{$live}</div>{$compareCell}</div>";
    }

    /**
     * A link in one of the status cells.
     *
     * With a live domain set the visible labels become "This CMS"/"Live CMS" and
     * "This page"/"Live page", so each pair reads as a pair while still saying which of
     * the row's two pairs it belongs to. The hidden description carries the page name,
     * which the short label on its own no longer says.
     *
     * Both versions are rendered and the ptl-comparing class picks one, so setting a
     * domain in the page does not need the row rebuilt.
     *
     * @param array{0: string, 1: string}|null $comparing Label and description to use
     *        instead while a live domain is set. Null where the link has no live pair.
     */
    private function cellLink(
        string $url,
        string $label,
        string $description,
        string $class,
        string $icon,
        ?array $comparing = null
    ): string {
        // The underline goes on the label rather than the anchor, so it does not run
        // under the icon as well.
        return "<a href='" . $this->esc($url) . "' target='_blank' rel='noopener' class='{$class}'>"
            . $this->icon($icon)
            . "<span class='ptl-link-label'>" . $this->swapText($label, $comparing[0] ?? null) . "</span>"
            . "<span class='ptl-sr-only'> &ndash; " . $this->swapText($description, $comparing[1] ?? null)
            . "</span></a>";
    }

    private function swapText(string $plain, ?string $comparing): string
    {
        if ($comparing === null) {
            return $this->esc($plain);
        }

        return "<span class='ptl-when-plain'>" . $this->esc($plain) . "</span>"
            . "<span class='ptl-when-comparing'>" . $this->esc($comparing) . "</span>";
    }

    /**
     * The live half of a pair. It carries the path rather than a full URL, and only has
     * an href while a domain is set, which the script fills in when one is set later.
     */
    private function liveLink(string $path, string $label, string $description, string $class): string
    {
        // Escaped on output. The domain comes from the query string.
        $href = $this->liveDomain === '' ? '' : " href='" . $this->esc($this->liveDomain . $path) . "'";

        return "<a{$href} data-ptl-live-path='" . $this->esc($path) . "' target='_blank' rel='noopener'"
            . " class='{$class}'>" . $this->icon('globe')
            . "<span class='ptl-link-label'>" . $this->esc($label) . "</span>"
            . "<span class='ptl-sr-only'> &ndash; " . $this->esc($description) . "</span></a>";
    }

    /**
     * Opens the local and live version of the same thing in windows side by side.
     *
     * Windows rather than frames in a dialog, because framing cannot work here:
     * Silverstripe sends X-Frame-Options SAMEORIGIN on the admin, and plenty of sites
     * send it for every response, which leaves the live half blank. A window is a
     * top-level browsing context, so framing rules do not apply and the live CMS stays
     * logged in as normal.
     *
     * The script joins the live path to whichever domain is set when it is clicked.
     */
    private function compareButton(string $localUrl, string $livePath, string $title): string
    {
        return "<button type='button' class='ptl-compare-btn' data-ptl-action='compare'"
            . " data-ptl-local='" . $this->esc($localUrl) . "'"
            . " data-ptl-live-path='" . $this->esc($livePath) . "'"
            . " aria-label='" . $this->esc('Open ' . $title . ' and its live version side by side') . "'>"
            . $this->icon('compare') . " Compare</button>";
    }

    /**
     * Sits inline after the page name, small enough not to compete with it. The tooltip
     * says what deleting does; the label alone would not.
     */
    private function deleteButton(int $pageId, int $rowIndex, string $title): string
    {
        return "<button type='button' class='ptl-delete-btn' data-ptl-action='delete-page'"
            . " data-ptl-page='{$pageId}' data-ptl-row='{$rowIndex}'"
            . " aria-label='" . $this->esc('Delete ' . $title . ', created by this report') . "'>"
            . $this->icon('trash') . " Delete"
            . "<span class='ptl-tip ptl-tip-above'>Created by this report."
            . " Recoverable from the CMS archive.</span></button>";
    }

    /**
     * Hidden until something has been created, rather than sitting in the toolbar
     * permanently reading zero. It sits apart at the far end, away from the buttons
     * that are safe to click freely.
     */
    private function deleteCreatedButton(): string
    {
        $count = count($this->createdPageIds);
        $hidden = $count === 0 ? ' hidden' : '';

        return "<span class='ptl-btn-wrap ptl-toolbar-end' id='ptl-delete-created-wrap'{$hidden}>"
            . "<button type='button' id='ptl-delete-created-btn' data-ptl-action='delete-all-created'"
            . " class='ptl-btn ptl-btn-danger'>" . $this->icon('trash')
            // One span for the label, or the flex gap also lands either side of the count.
            . " <span>Delete Created Pages (<span id='ptl-delete-created-count'>{$count}</span>)</span></button>"
            . "<span class='ptl-tip ptl-tip-below ptl-tip-end'>Deletes every page created here with a Create"
            . " button, including test pages for blocks</span></span>";
    }

    /**
     * One row per Elemental block type, each checked through one example block.
     *
     * The CMS is checked twice because a block appears there twice: as an entry in the
     * blocks editor on its page, and in its own edit form. Either can fail while the
     * other works.
     *
     * @param BlockTypeRow[] $rows
     */
    private function blockTypeTable(array $rows): string
    {
        $html = "<table class='ptl-table' id='ptl-block-types'>"
            . "<caption class='ptl-sr-only'>Elemental block types with their CMS and frontend status</caption>"
            . "<thead><tr>"
            . "<th scope='col' class='ptl-tested-col ptl-tested-ui'>Tested</th>"
            . "<th scope='col'>Block Type</th>"
            . "<th scope='col'>Count</th>"
            . "<th scope='col'>CMS Summary</th>"
            . "<th scope='col'>CMS Edit Form</th>"
            . "<th scope='col'>Frontend</th>"
            . "<th scope='col'>Example Block</th>"
            . "</tr></thead><tbody>";

        foreach ($rows as $row) {
            $html .= $row->hasElement() ? $this->blockRow($row) : $this->emptyBlockRow($row);
        }

        $tools = $this->openAllTools(
            ['block-cms' => 'Open All Edit Forms', 'block-frontend' => 'Open All Frontend'],
            'block types'
        ) . $this->testedTools('ptl-block-types', 'block types');

        return $this->tablePanel('ptl-block-types', 'Block Types', $tools, $html . "</tbody></table>");
    }

    private function blockRow(BlockTypeRow $row): string
    {
        $draftOnly = $row->getDraftOnlyCount();

        $count = "<span class='ptl-count'>{$row->liveCount}"
            . ($draftOnly > 0 ? " <span class='ptl-count-draft'>+ {$draftOnly}</span>" : '')
            . "<span class='ptl-tip ptl-tip-above'>{$row->liveCount} live, {$draftOnly} draft only</span></span>";

        // The editor has no page of its own, so its link goes to the page it sits on.
        $editorCell = $this->linkCell(
            $this->statusPlaceholder("block-editor-status-{$row->index}"),
            $this->cellLink(
                $row->pageCmsLink,
                'Edit Page',
                "{$row->shortClass} summary in its page's block list",
                'ptl-cms',
                'desktop'
            ),
            '',
            ''
        );

        $formCell = $row->editFormUrl === ''
            ? "<span class='ptl-url'>&mdash;</span>"
            : $this->blockFormCell($row);

        $frontendCell = $this->linkCell(
            $this->statusPlaceholder("block-frontend-status-{$row->index}"),
            $this->cellLink(
                $row->frontendUrl,
                'View Block',
                "{$row->shortClass} rendered on its own",
                'ptl-frontend',
                'desktop'
            ),
            '',
            ''
        );

        $title = $row->pageLink === ''
            ? $this->esc($row->title)
            : "<a href='" . $this->esc($row->pageLink) . "' target='_blank' rel='noopener'>"
                . $this->esc($row->title) . "</a>";

        // Blocks made by the Create button sit on a test page of their own, which
        // deleting takes with it.
        $deleteButton = $this->isCreatedBlock($row) ? $this->blockDeleteButton($row) : '';

        return "<tr>"
            . $this->testedCell($row->class, $row->shortClass)
            . "<td>" . $this->blockTypeName($row) . "</td>"
            . "<td>{$count}</td>"
            . "<td>{$editorCell}</td>"
            . "<td>{$formCell}</td>"
            . "<td>{$frontendCell}" . $this->emailList($row->emailUsages) . "</td>"
            . "<td class='ptl-example-cell'><span class='ptl-title'>{$title}{$deleteButton}</span>"
            . "<span class='ptl-subtext'>on " . $this->esc($row->pageTitle) . "</span></td>"
            . "</tr>";
    }

    private function isCreatedBlock(BlockTypeRow $row): bool
    {
        return $row->pageId > 0 && in_array($row->pageId, $this->createdPageIds, true);
    }

    /**
     * Mirrors deleteButton, but for a block. What is deleted is the test page the block
     * was created on, which archives the block with it.
     */
    private function blockDeleteButton(BlockTypeRow $row): string
    {
        return "<button type='button' class='ptl-delete-btn' data-ptl-action='delete-block'"
            . " data-ptl-page='{$row->pageId}' data-ptl-row='{$row->index}'"
            . " aria-label='" . $this->esc('Delete ' . $row->title . ' and its test page, created by this report')
            . "'>" . $this->icon('trash') . " Delete"
            . "<span class='ptl-tip ptl-tip-above'>Deletes its test page too."
            . " Recoverable from the CMS archive.</span></button>";
    }

    /**
     * The block's edit form, with live and Compare links when a live domain is set.
     *
     * As with pages, the live link assumes the live site has the same record IDs, which
     * holds when the local database is a copy of live.
     */
    private function blockFormCell(BlockTypeRow $row): string
    {
        // A block created here does not exist on the live site, so has nothing to compare.
        $hasLive = !$this->isCreatedBlock($row);

        $localLink = $this->cellLink(
            $row->editFormUrl,
            'Edit Block',
            "{$row->shortClass} edit form",
            'ptl-cms',
            'desktop',
            $hasLive ? ['This CMS', "edit {$row->shortClass} on this site"] : null
        );

        $liveLink = '';
        $compare = '';
        if ($hasLive) {
            $livePath = '/' . ltrim(Director::makeRelative($row->editFormUrl), '/');

            $liveLink = $this->liveLink(
                $livePath,
                'Live CMS',
                "edit {$row->shortClass} on the live site",
                'ptl-cms'
            );
            $compare = $this->compareButton($row->editFormUrl, $livePath, $row->title . ' in the CMS');
        }

        return $this->linkCell(
            $this->statusPlaceholder("block-form-status-{$row->index}"),
            $localLink,
            $liveLink,
            $compare
        );
    }

    /**
     * Offers to create a block, on a test page of its own, where some page type can
     * hold one. Mirrored by buildEmptyBlockRowHtml in the script.
     */
    private function emptyBlockRow(BlockTypeRow $row): string
    {
        $shortClass = $this->esc($row->shortClass);

        // Blocks left behind by a deleted page are counted, but cannot be checked.
        $note = $row->totalCount > 0 ? 'Existing blocks of this type are not on a page' : '';

        if ($row->canCreate()) {
            $content = "<button type='button' class='ptl-create-btn' data-ptl-action='create-block' "
                . "data-ptl-class='" . $this->esc($row->class) . "' data-ptl-short='{$shortClass}' "
                . "data-ptl-name='" . $this->esc($row->singularName) . "'>"
                . $this->icon('plus') . " Create {$shortClass}</button>"
                . ($note === '' ? '' : "<span class='ptl-empty-note'>{$note}</span>");
        } else {
            $content = "<span class='ptl-empty-note'>"
                . ($note === '' ? 'No blocks of this type, and no page type you can create allows one' : $note)
                . "</span>";
        }

        return "<tr>"
            . $this->testedCell($row->class, $row->shortClass)
            . "<td>" . $this->blockTypeName($row) . "</td>"
            . "<td><span class='ptl-count'>{$row->totalCount}</span></td>"
            . "<td colspan='4'><div class='ptl-empty-content'>{$content}"
            . $this->emailList($row->emailUsages) . "</div></td>"
            . "</tr>";
    }

    private function blockTypeName(BlockTypeRow $row): string
    {
        $name = $row->singularName !== '' && $row->singularName !== $row->shortClass
            ? "<span class='ptl-subtext'>" . $this->esc($row->singularName) . "</span>"
            : '';

        return "<span class='ptl-type'>" . $this->esc($row->shortClass) . "</span>{$name}";
    }

    /**
     * Where the type's code sends email, as badge and label pairs in the same list as a
     * page's actions and forms, since those are usually what sends it.
     *
     * Rendered here rather than by the script, which has no copy of the usages. The
     * script fills the other parts of the list but never this one, and moves it across
     * as it is when it rebuilds a row. It is always output, even empty, so the script
     * has somewhere to move it to.
     *
     * The label is just the method, as the class is usually the type's own controller.
     * The rest is in the badge's tooltip, which is focusable so the tooltip opens by
     * keyboard too, and is repeated as hidden text for screen readers.
     *
     * @param EmailUsage[] $usages
     */
    private function emailPart(array $usages): string
    {
        $html = '';
        foreach ($usages as $usage) {
            $tip = [$this->esc($usage->getLocation()), $this->esc($usage->file . ':' . $usage->line)];
            $tip[] = 'Sends with ' . $this->esc($usage->mailer);
            if ($usage->via) {
                $tip[] = 'Via ' . $this->esc(implode(' > ', $usage->via));
            }

            $label = $usage->method === '' ? $usage->className : $usage->method . '()';

            $html .= "<span class='ptl-status'><span class='ptl-email-badge' tabindex='0'>" . $this->icon('mail')
                . "<span class='ptl-sr-only'>Sends</span> email"
                . "<span class='ptl-sr-only'>: " . implode('. ', $tip) . ".</span>"
                . "<span class='ptl-tip ptl-tip-above' aria-hidden='true'>" . implode('<br>', $tip) . "</span>"
                . "</span></span>"
                . "<span class='ptl-email-name' aria-hidden='true'>" . $this->esc($label) . "</span>";
        }

        return "<span class='ptl-actions-part ptl-email-part'>{$html}</span>";
    }

    /**
     * The email part in a list of its own, for block types and admin sections, which
     * have no actions list.
     *
     * @param EmailUsage[] $usages
     */
    private function emailList(array $usages): string
    {
        return "<div class='ptl-actions-container'>" . $this->emailPart($usages) . "</div>";
    }

    private function statusPlaceholder(string $id): string
    {
        return "<span id='{$id}' class='ptl-status'><span class='ptl-status-placeholder'>?</span></span>";
    }

    /**
     * A card for each GridField on the page's edit form, under the edit form's own
     * status, laid out like the frontend's actions: status, then the form it checked,
     * then the class it was built for. Mirrored by gridCardsHtml in the script.
     *
     * The cards sit in a disclosure, closed, whose summary gives one status for them
     * all. The script opens it when any of them fails.
     */
    private function gridCards(PageTypeRow $row): string
    {
        if (!$row->gridFields) {
            return '';
        }

        $count = count($row->gridFields);
        $noun = $count === 1 ? 'GridField' : 'GridFields';

        return "<details id='grid-details-{$row->index}' class='ptl-grid-details'>"
            . "<summary class='ptl-grid-summary'>"
            . "<span id='grid-summary-status-{$row->index}' class='ptl-status'>"
            . "<span class='ptl-status-placeholder'>?</span></span>"
            . " {$count} {$noun}</summary>"
            . $this->gridCardList($row) . "</details>";
    }

    private function gridCardList(PageTypeRow $row): string
    {
        $html = '';
        foreach ($row->gridFields as $g => $grid) {
            $items = '';
            foreach ($grid['forms'] as $f => $form) {
                $model = $this->esc($form['model']);

                // Named for what it opens, with a plus, as on the admin sections.
                $label = $form['isNew']
                    ? $this->icon('plus') . "<span class='ptl-add-form-text'>Add form"
                        . "<span class='ptl-sr-only'> for a new {$model}</span></span>"
                    : $this->esc($form['title']);

                $items .= $this->statusPlaceholder("grid-status-{$row->index}-{$g}-{$f}")
                    . "<a href='" . $this->esc($form['url']) . "' target='_blank' rel='noopener' class='"
                    . ($form['isNew'] ? 'ptl-add-form' : 'ptl-grid-record') . "'>{$label}</a>"
                    . "<span class='ptl-edit-model'>{$model}</span>";
            }

            $title = $this->esc($grid['title']);
            $html .= "<div class='ptl-actions-container ptl-grid-card' role='group' aria-label='{$title} GridField'>"
                . "<span class='ptl-grid-title' aria-hidden='true'>{$title}</span>"
                . "<span class='ptl-actions-part'>{$items}</span></div>";
        }

        return $html;
    }

    /**
     * @param AdminSection[] $sections
     */
    private function adminTable(array $sections): string
    {
        $openAll = ['admin' => 'Open All Sections'];
        foreach ($sections as $section) {
            foreach ($section->editLinks as $link) {
                if (!$link->isNew) {
                    $openAll['admin-edit'] = 'Open All Edit Forms';
                    break 2;
                }
            }
        }

        $html = "<table class='ptl-table' id='ptl-admin-sections'>"
            . "<caption class='ptl-sr-only'>Admin sections with their status</caption>"
            . "<thead><tr>"
            . "<th scope='col' class='ptl-tested-col ptl-tested-ui'>Tested</th>"
            . "<th scope='col'>Name</th><th scope='col'>Type</th>"
            . "<th scope='col'>Admin Section</th><th scope='col'>Edit Form</th>"
            . "</tr></thead><tbody>";

        foreach ($sections as $section) {
            $name = $this->esc($section->name);

            // Each record's email usages sit beside its edit form, which is where saving
            // one, and so sending the email, would be tested.
            $editCell = $section->editLinks || $section->unlinkedEmailUsages
                ? $this->adminEditLinks($section->editLinks, $section->unlinkedEmailUsages)
                : "<span class='ptl-url'>&mdash;</span>";

            $html .= "<tr>"
                . $this->testedCell('admin:' . Director::makeRelative($section->url), $section->name)
                . "<td><strong>{$name}</strong></td>"
                . "<td><span class='ptl-type'>" . $this->esc($section->type) . "</span></td>"
                . "<td><span id='admin-status-{$section->index}' class='ptl-status'>"
                . "<span class='ptl-status-placeholder'>?</span></span>"
                . "<a href='" . $this->esc($section->url) . "' target='_blank' rel='noopener' class='ptl-cms'>"
                . "View<span class='ptl-sr-only'> {$name}</span></a>"
                . $this->emailList($section->emailUsages) . "</td>"
                . "<td>{$editCell}</td>"
                . "</tr>";
        }

        $tools = $this->openAllTools($openAll, 'admin sections')
            . $this->testedTools('ptl-admin-sections', 'admin sections');

        return $this->tablePanel('ptl-admin-sections', 'Admin Sections', $tools, $html . "</tbody></table>");
    }

    /**
     * One row per model: its example record's edit form, its add form, then where its
     * code sends email, which is what saving one would test. The rows share a grid, so
     * each kind of link lines up with the same kind in the other rows.
     *
     * @param AdminEditLink[] $links
     * @param array<string, EmailUsage[]> $unlinkedEmailUsages Model name => usages, for
     *                                                         models with neither form.
     */
    private function adminEditLinks(array $links, array $unlinkedEmailUsages): string
    {
        $rows = [];
        foreach ($links as $link) {
            // A model's add form follows its edit form, so they share a row.
            $last = $rows ? array_key_last($rows) : null;
            if ($link->isNew && $last !== null && $rows[$last]['model'] === $link->modelName && !$rows[$last]['new']) {
                $rows[$last]['new'] = $link;
                $rows[$last]['emails'] = $rows[$last]['emails'] ?: $link->emailUsages;
                continue;
            }

            $rows[] = [
                'model' => $link->modelName,
                'edit' => $link->isNew ? null : $link,
                'new' => $link->isNew ? $link : null,
                'emails' => $link->emailUsages,
            ];
        }

        foreach ($unlinkedEmailUsages as $modelName => $usages) {
            $rows[] = ['model' => $modelName, 'edit' => null, 'new' => null, 'emails' => $usages];
        }

        // Columns nothing in the section uses are left out, rather than leaving gaps.
        $hasAddForms = (bool) array_filter(array_column($rows, 'new'));
        $hasEmails = (bool) array_filter(array_column($rows, 'emails'));
        $columns = 2 + (int) $hasAddForms + (int) $hasEmails;

        $html = '';
        foreach ($rows as $row) {
            $edit = '<span></span>';
            if ($row['edit']) {
                $edit = $this->adminEditItem($row['edit'], "class='ptl-cms'", $this->esc($row['edit']->recordTitle));
            } elseif ($row['new']) {
                $edit = "<span class='ptl-edit-none'>No records</span>";
            }

            // Named for what it opens, with a plus rather than a record title.
            $new = $row['new']
                ? $this->adminEditItem(
                    $row['new'],
                    "class='ptl-add-form'",
                    $this->icon('plus') . "<span class='ptl-add-form-text'>Add form"
                        . "<span class='ptl-sr-only'> for a new " . $this->esc($row['model']) . "</span></span>"
                )
                : '<span></span>';

            $emails = $row['emails'] ? $this->emailList($row['emails']) : '<span></span>';

            $html .= "<div class='ptl-edit-link'>"
                . "<span class='ptl-edit-model'>" . $this->esc($row['model']) . "</span>{$edit}"
                . ($hasAddForms ? $new : '') . ($hasEmails ? $emails : '') . "</div>";
        }

        return "<div class='ptl-edit-grid' style='--ptl-edit-columns: {$columns}'>{$html}</div>";
    }

    private function adminEditItem(AdminEditLink $link, string $class, string $label): string
    {
        return "<span class='ptl-edit-item'>"
            . $this->statusPlaceholder("admin-edit-status-{$link->index}")
            . "<a href='" . $this->esc($link->url) . "' target='_blank' rel='noopener' {$class}>{$label}</a></span>";
    }

    /**
     * A real GET form, so Enter submits it. The script applies the domain in place
     * rather than letting it submit, so the checks already run are kept. Without the
     * script it submits as normal and the report is rendered with the domain set.
     */
    private function liveDomainSection(): string
    {
        // Clear is script-only, like the tested ticks. Without the script, emptying the
        // field and submitting does the same.
        $clear = "<button type='button' data-ptl-action='clear-live-domain'"
            . " class='ptl-btn ptl-when-comparing ptl-js-only'>Clear</button>";

        return "<div class='ptl-live-domain-section'>"
            . "<h2>Compare with Live Site</h2>"
            . "<p>Enter the live site URL to add \"Live CMS\" and \"Live Page\" links for each page type, "
            . "and a \"Live CMS\" link for each block's edit form, for comparing an upgraded local or staging site against production. These links are not checked.</p>"
            . "<form class='ptl-live-domain-form' method='get' action='" . $this->esc($this->taskUrl()) . "'>"
            . $this->carriedQueryInputs()
            . "<div class='ptl-field'>"
            . "<label for='ptl-live-domain-input'>Live site URL</label>"
            . "<input type='url' id='ptl-live-domain-input' name='live-domain' class='ptl-input'"
            . " placeholder='https://example.com' value='" . $this->esc($this->liveDomain) . "'>"
            . "</div>"
            . "<button type='submit' class='ptl-btn'>"
            . $this->icon('globe') . " Set Live Domain</button>{$clear}"
            . "<output id='ptl-live-domain-status' class='ptl-live-domain-status' aria-live='polite'></output>"
            . "</form></div>";
    }

    /**
     * A GET form replaces the whole query string, so the other options in use (skip-admin,
     * randomise and so on) go along as hidden fields or submitting would drop them.
     */
    private function carriedQueryInputs(): string
    {
        $request = Controller::curr()?->getRequest();
        if (!$request) {
            return '';
        }

        $html = '';
        foreach ($request->getVars() as $name => $value) {
            if (!is_scalar($value) || in_array($name, ['live-domain', 'flush', 'url'], true)) {
                continue;
            }

            $html .= "<input type='hidden' name='" . $this->esc((string) $name) . "' value='"
                . $this->esc((string) $value) . "'>";
        }

        return $html;
    }

    private function helpSection(): string
    {
        return "<div class='ptl-help'>"
            . "<h2>How does this work?</h2>"
            . "<div class='ptl-help-section'>"
            . "<h3>What it checks</h3>"
            . "<ul>"
            . "<li><strong>CMS edit form</strong> &ndash; the page's CMS edit URL returns HTTP 200. On pages with "
            . "Elemental blocks, the block list the blocks editor loads afterwards must return 200 as well. The "
            . "page's Settings and History screens are checked too, and only shown when they fail. Each "
            . "GridField on the edit form gets a card beneath it, checking the edit form of one record of each "
            . "class the grid lists, and its add form. The cards stay folded away unless one of them fails</li>"
            . "<li><strong>Frontend</strong> &ndash; the page URL returns its expected status (200, or 404/500 "
            . "for ErrorPage, or a redirect for RedirectorPage). A 200 still fails if the page shows PHP error "
            . "or debug output, unrendered template code or shortcodes, a link to the site's own address with "
            . "no / before the path, a link whose text is an http:// address going to https:// or the other "
            . "way round, has no title, or stops before <code>&lt;/html&gt;</code>. Actions and blocks are "
            . "searched for the same error output, code and links. A page type with only draft pages is "
            . "checked on the draft stage, which needs a CMS login</li>"
            . "<li><strong>Actions</strong> &ndash; where a controller declares <code>\$allowed_actions</code>, "
            . "links beneath the page's own URL are found and checked</li>"
            . "<li><strong>Forms</strong> &ndash; <code>&lt;form&gt;</code> tags in the main content are flagged "
            . "for manual testing</li>"
            . "<li><strong>Blocks</strong> &ndash; where Elemental is installed, one block of each type can be "
            . "summarised in its page's block list in the CMS, its CMS edit form returns 200, and it renders on its own "
            . "with a 200</li>"
            . "<li><strong>Admin sections</strong> &ndash; each section returns 200, as does the edit form for "
            . "one record of each model and, where the section has an Add button, the form for a new one</li>"
            . "<li><strong>Sends email</strong> &ndash; page types, block types and admin sections whose code "
            . "sends email are flagged, with where it happens, so you know which forms to submit and which inboxes "
            . "to check. This reads the code rather than running it, so nothing is sent</li>"
            . "</ul></div>"
            . "<div class='ptl-help-section'>"
            . "<h3>What it does not check</h3>"
            . "<ul>"
            . "<li>Form submissions or validation, or whether emails arrive</li>"
            . "<li>JavaScript behaviour or console errors</li>"
            . "<li>Visual rendering or layout</li>"
            . "<li>Links within page content</li>"
            . "<li>Data accuracy or database integrity</li>"
            . "<li>Performance and load times</li>"
            . "</ul></div></div>";
    }

    /**
     * @param PageTypeRow[] $rows
     * @param BlockTypeRow[] $blockRows
     * @param AdminSection[] $sections
     */
    private function configPayload(array $rows, array $blockRows, array $sections): string
    {
        $rowData = [];
        foreach ($rows as $row) {
            if (!$row->hasPage()) {
                continue;
            }

            $rowData[] = [
                'index' => $row->index,
                'class' => $row->class,
                'shortClass' => $row->shortClass,
                'pageId' => (int) $row->page->ID,
                'cmsLink' => $row->cmsLink,
                'frontendLink' => $row->frontendLink,
                'frontendNeedsLogin' => $row->frontendNeedsLogin,
                'expected' => $row->expectedStatus,
                'actions' => array_values($row->allowedActions),
                'blockListUrls' => array_values($row->blockListUrls),
                'cmsScreenChecks' => $row->cmsScreenChecks,
                'gridFields' => $row->gridFields,
            ];
        }

        $blockData = [];
        foreach ($blockRows as $row) {
            if (!$row->hasElement()) {
                continue;
            }

            $blockData[] = [
                'index' => $row->index,
                'class' => $row->class,
                'shortClass' => $row->shortClass,
                'singularName' => $row->singularName,
                'pageId' => $row->pageId,
                'editorCheckUrl' => $row->editorCheckUrl,
                'editFormUrl' => $row->editFormUrl,
                'frontendUrl' => $row->frontendUrl,
                'frontendNeedsLogin' => $row->frontendNeedsLogin,
            ];
        }

        $sectionData = [];
        $editData = [];
        foreach ($sections as $section) {
            $sectionData[] = ['index' => $section->index, 'url' => $section->url];
            foreach ($section->editLinks as $link) {
                $editData[] = ['index' => $link->index, 'url' => $link->url, 'isNew' => $link->isNew];
            }
        }

        $config = [
            'baseUrl' => Director::absoluteBaseURL(),
            'taskUrl' => $this->taskUrl(),
            'loginUrl' => $this->checker->getLoginUrl(),
            'loginPath' => $this->checker->getLoginPath(),
            'directActions' => array_values(ActionLinkFinder::getDirectActions()),
            'createParam' => PageCreator::PARAM,
            'deleteParam' => PageDeleter::PARAM,
            'createBlockParam' => BlockCreator::PARAM,
            'createdPageIds' => array_values($this->createdPageIds),
            'liveDomain' => $this->liveDomain,
            'concurrency' => (int) static::config()->get('check_concurrency'),
            'securityToken' => $this->securityToken(),
            'rows' => $rowData,
            'blocks' => $blockData,
            'adminSections' => $sectionData,
            'adminEditLinks' => $editData,
        ];

        // Written as JSON in a non-executable script block, so no value reaches a
        // JavaScript parsing context and nothing needs escaping by hand.
        $json = json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return "<script type='application/json' id='ptl-config'>{$json}</script>";
    }

    /**
     * The token needs a session, which does not exist under the CLI. The HTML payload
     * is not used there, so an empty token is fine.
     *
     * @return array{name: string, value: string}
     */
    private function securityToken(): array
    {
        if (!Controller::curr()?->getRequest()) {
            return ['name' => '', 'value' => ''];
        }

        $token = SecurityToken::inst();

        return ['name' => (string) $token->getName(), 'value' => (string) $token->getValue()];
    }

    private function taskUrl(): string
    {
        $request = Controller::curr()?->getRequest();

        return $request ? Director::absoluteURL($request->getURL()) : '';
    }

    private function esc(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
