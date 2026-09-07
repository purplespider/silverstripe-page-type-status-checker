<?php

namespace PurpleSpider\PageTypeTester\Report;

use PurpleSpider\PageTypeTester\ActionLinkFinder;
use PurpleSpider\PageTypeTester\Model\AdminSection;
use PurpleSpider\PageTypeTester\Model\PageTypeRow;
use PurpleSpider\PageTypeTester\PageCreator;
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

    public function __construct(
        private readonly UrlChecker $checker,
        private readonly string $liveDomain = '',
        private readonly bool $randomise = false
    ) {
    }

    /**
     * @param PageTypeRow[] $rows
     * @param AdminSection[] $adminSections
     */
    public function render(PolyOutput $output, array $rows, array $adminSections, bool $skipAdmin): void
    {
        $output->writeForHtml($this->assets());
        $output->writeForHtml($this->iconSprite());
        $output->writeForHtml("<div class='ptl-wrap'>");
        $output->writeForHtml($this->loginBanner());
        $output->writeForHtml($this->toolbar());
        $output->writeForHtml($this->pageTypeTable($rows));

        if (!$skipAdmin) {
            $output->writeForHtml($this->adminTable($adminSections));
        }

        $output->writeForHtml($this->liveDomainSection());
        $output->writeForHtml($this->helpSection());
        $output->writeForHtml("</div>");
        $output->writeForHtml($this->configPayload($rows, $adminSections));
    }

    public function renderHeader(PolyOutput $output, string $title): void
    {
        $output->writeForHtml(
            "<div class='ptl-header'><div><h1>" . $this->esc($title) . "</h1>"
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
            . "<button type='button' data-ptl-action='open-all' data-ptl-links='cms' class='ptl-btn'>"
            . $this->icon('external') . " Open All CMS</button>"
            . "<button type='button' data-ptl-action='open-all' data-ptl-links='frontend' class='ptl-btn'>"
            . $this->icon('external') . " Open All Frontend</button>"
            . "</div>";

        $html .= "<div class='ptl-divider'></div>";

        $html .= "<button type='button' data-ptl-action='toggle-previews' aria-pressed='false' class='ptl-btn'>"
            . $this->icon('eye') . " Show Previews</button>";

        $html .= "<div class='ptl-divider'></div>";

        $html .= "<div class='ptl-btn-group'>"
            . "<button type='button' data-ptl-action='randomise' class='ptl-btn'>"
            . $this->icon('shuffle') . " Randomise</button>";

        if ($this->randomise) {
            $html .= "<button type='button' data-ptl-action='reset' class='ptl-btn'>"
                . $this->icon('reset') . " Reset</button>";
        }

        $html .= "</div></div>";

        return $html;
    }

    /**
     * @param PageTypeRow[] $rows
     */
    private function pageTypeTable(array $rows): string
    {
        $liveHeader = $this->liveDomain ? "<th scope='col'>Live Site</th>" : '';

        $html = "<table class='ptl-table'>"
            . "<caption class='ptl-sr-only'>Page types with their CMS and frontend status</caption>"
            . "<thead><tr>"
            . "<th scope='col' class='ptl-preview-col'>Preview</th>"
            . "<th scope='col'>Page Type</th>"
            . "<th scope='col'>Count</th>"
            . "<th scope='col'>CMS Edit Form</th>"
            . "<th scope='col'>Frontend</th>"
            . $liveHeader
            . "<th scope='col'>Example Page</th>"
            . "</tr></thead><tbody>";

        foreach ($rows as $row) {
            $html .= $row->hasPage() ? $this->pageRow($row) : $this->emptyRow($row);
        }

        return $html . "</tbody></table>";
    }

    private function pageRow(PageTypeRow $row): string
    {
        $shortClass = $this->esc($row->shortClass);
        $draftOnly = $row->getDraftOnlyCount();

        $count = "<span class='ptl-count'>{$row->liveCount}"
            . ($draftOnly > 0 ? " <span class='ptl-count-draft'>+ {$draftOnly}</span>" : '')
            . "<span class='ptl-tip ptl-tip-above'>{$row->liveCount} live, {$draftOnly} draft only</span></span>";

        $actionsContainer = $row->allowedActions
            ? "<span id='actions-container-{$row->index}' class='ptl-actions-container'></span>"
            : '';

        $liveCell = '';
        if ($this->liveDomain) {
            // Escaped on output. This value comes from the query string.
            $liveCms = $this->esc($this->liveDomain . '/admin/pages/edit/show/' . $row->page->ID);
            $liveFrontend = $this->esc($this->liveDomain . $row->pageUrl);
            $liveCell = "<td class='ptl-live-links'>"
                . "<a href='{$liveCms}' target='_blank' rel='noopener'>Live CMS"
                . "<span class='ptl-sr-only'> for {$shortClass}</span></a>"
                . "<a href='{$liveFrontend}' target='_blank' rel='noopener'>Live Page"
                . "<span class='ptl-sr-only'> for {$shortClass}</span></a></td>";
        }

        return "<tr>"
            . "<td class='ptl-preview-col'><div class='ptl-preview'>"
            . "<iframe title='Preview of " . $this->esc($row->title) . "' data-src='"
            . $this->esc($row->frontendLink) . "'></iframe></div></td>"
            . "<td><span class='ptl-type'>{$shortClass}</span></td>"
            . "<td>{$count}</td>"
            . "<td><span id='cms-status-{$row->index}' class='ptl-status'>"
            . "<span class='ptl-status-placeholder'>?</span></span>"
            . "<a href='" . $this->esc($row->cmsLink) . "' target='_blank' rel='noopener' class='ptl-cms'>"
            . "Edit in CMS<span class='ptl-sr-only'> ({$shortClass})</span></a></td>"
            . "<td><span id='frontend-status-{$row->index}' class='ptl-status'>"
            . "<span class='ptl-status-placeholder'>?</span></span>"
            . "<a href='" . $this->esc($row->frontendLink) . "' target='_blank' rel='noopener' class='ptl-frontend'>"
            . "View Page<span class='ptl-sr-only'> ({$shortClass})</span></a>"
            . "<span id='form-indicator-{$row->index}'></span>{$actionsContainer}</td>"
            . $liveCell
            . "<td class='ptl-example-cell'><span class='ptl-title'>" . $this->esc($row->title) . "</span>"
            . "<span class='ptl-url'>" . $this->esc($row->pageUrl) . "</span></td>"
            . "</tr>";
    }

    private function emptyRow(PageTypeRow $row): string
    {
        $shortClass = $this->esc($row->shortClass);
        $colspan = $this->liveDomain ? 4 : 3;

        $note = $row->allowedActions
            ? "<div class='ptl-action-note'>Has actions: " . $this->esc(implode(', ', $row->allowedActions)) . "</div>"
            : '';

        return "<tr>"
            . "<td class='ptl-preview-col'><div class='ptl-preview-empty'>No preview</div></td>"
            . "<td><span class='ptl-type'>{$shortClass}</span></td>"
            . "<td><span class='ptl-count'>0</span></td>"
            . "<td colspan='{$colspan}' style='text-align:center;'>"
            . "<button type='button' class='ptl-create-btn' data-ptl-action='create-page' "
            . "data-ptl-class='" . $this->esc($row->class) . "' data-ptl-short='{$shortClass}'>"
            . $this->icon('plus') . " Create {$shortClass}</button>{$note}</td>"
            . "</tr>";
    }

    /**
     * @param AdminSection[] $sections
     */
    private function adminTable(array $sections): string
    {
        $html = "<table class='ptl-table'>"
            . "<caption>Admin Sections</caption>"
            . "<thead><tr>"
            . "<th scope='col'>Name</th><th scope='col'>Type</th>"
            . "<th scope='col'>Admin Section</th><th scope='col'>Edit Form</th>"
            . "</tr></thead><tbody>";

        foreach ($sections as $section) {
            $name = $this->esc($section->name);

            $editCell = "<span class='ptl-url'>&mdash;</span>";
            if ($section->editLinks) {
                $editCell = '';
                foreach ($section->editLinks as $link) {
                    $editCell .= "<div class='ptl-edit-link'>"
                        . "<span id='admin-edit-status-{$link->index}' class='ptl-status'>"
                        . "<span class='ptl-status-placeholder'>?</span></span>"
                        . "<span class='ptl-edit-model'>" . $this->esc($link->modelName) . "</span>"
                        . "<a href='" . $this->esc($link->url) . "' target='_blank' rel='noopener' class='ptl-cms'>"
                        . $this->esc($link->recordTitle) . "</a></div>";
                }
            }

            $html .= "<tr>"
                . "<td><strong>{$name}</strong></td>"
                . "<td><span class='ptl-type'>" . $this->esc($section->type) . "</span></td>"
                . "<td><span id='admin-status-{$section->index}' class='ptl-status'>"
                . "<span class='ptl-status-placeholder'>?</span></span>"
                . "<a href='" . $this->esc($section->url) . "' target='_blank' rel='noopener' class='ptl-cms'>"
                . "View<span class='ptl-sr-only'> {$name}</span></a></td>"
                . "<td>{$editCell}</td>"
                . "</tr>";
        }

        return $html . "</tbody></table>";
    }

    private function liveDomainSection(): string
    {
        $clear = $this->liveDomain
            ? "<button type='button' data-ptl-action='clear-live-domain' class='ptl-btn'>Clear</button>"
            : '';

        return "<div class='ptl-live-domain-section'>"
            . "<h2>Compare with Live Site</h2>"
            . "<p>Enter the live site URL to add \"Live CMS\" and \"Live Page\" links for each page type, for "
            . "comparing an upgraded local or staging site against production. These links are not checked.</p>"
            . "<div class='ptl-live-domain-form'>"
            . "<div class='ptl-field'>"
            . "<label for='ptl-live-domain-input'>Live site URL</label>"
            . "<input type='url' id='ptl-live-domain-input' class='ptl-input' placeholder='https://example.com' "
            . "value='" . $this->esc($this->liveDomain) . "'>"
            . "</div>"
            . "<button type='button' data-ptl-action='set-live-domain' class='ptl-btn'>"
            . $this->icon('globe') . " Set Live Domain</button>{$clear}"
            . "</div></div>";
    }

    private function helpSection(): string
    {
        return "<div class='ptl-help'>"
            . "<h2>How does this work?</h2>"
            . "<div class='ptl-help-section'>"
            . "<h3>What it checks</h3>"
            . "<ul>"
            . "<li><strong>CMS edit form</strong> &ndash; the page's CMS edit URL returns HTTP 200</li>"
            . "<li><strong>Frontend</strong> &ndash; the page URL returns its expected status (200, or 404/500 "
            . "for ErrorPage, or a redirect for RedirectorPage)</li>"
            . "<li><strong>Actions</strong> &ndash; where a controller declares <code>\$allowed_actions</code>, "
            . "links beneath the page's own URL are found and checked</li>"
            . "<li><strong>Forms</strong> &ndash; <code>&lt;form&gt;</code> tags in the main content are flagged "
            . "for manual testing</li>"
            . "</ul></div>"
            . "<div class='ptl-help-section'>"
            . "<h3>What it does not check</h3>"
            . "<ul>"
            . "<li>Form submissions or validation</li>"
            . "<li>JavaScript behaviour or console errors</li>"
            . "<li>Visual rendering or layout</li>"
            . "<li>Links within page content</li>"
            . "<li>Data accuracy or database integrity</li>"
            . "<li>Performance and load times</li>"
            . "</ul></div></div>";
    }

    /**
     * @param PageTypeRow[] $rows
     * @param AdminSection[] $sections
     */
    private function configPayload(array $rows, array $sections): string
    {
        $rowData = [];
        foreach ($rows as $row) {
            if (!$row->hasPage()) {
                continue;
            }

            $rowData[] = [
                'index' => $row->index,
                'shortClass' => $row->shortClass,
                'cmsLink' => $row->cmsLink,
                'frontendLink' => $row->frontendLink,
                'expected' => $row->expectedStatus,
                'actions' => array_values($row->allowedActions),
            ];
        }

        $sectionData = [];
        $editData = [];
        foreach ($sections as $section) {
            $sectionData[] = ['index' => $section->index, 'url' => $section->url];
            foreach ($section->editLinks as $link) {
                $editData[] = ['index' => $link->index, 'url' => $link->url];
            }
        }

        $config = [
            'baseUrl' => Director::absoluteBaseURL(),
            'taskUrl' => $this->taskUrl(),
            'loginUrl' => $this->checker->getLoginUrl(),
            'loginPath' => $this->checker->getLoginPath(),
            'directActions' => array_values(ActionLinkFinder::getDirectActions()),
            'createParam' => PageCreator::PARAM,
            'concurrency' => (int) static::config()->get('check_concurrency'),
            'securityToken' => $this->securityToken(),
            'rows' => $rowData,
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
