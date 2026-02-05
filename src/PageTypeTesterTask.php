<?php

namespace PurpleSpider\PageTypeTester;

use Page;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataObject;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

class PageTypeTesterTask extends BuildTask
{
    protected static string $commandName = 'page-type-tester';

    protected string $title = 'Silverstripe Page Type Tester';

    protected static string $description = 'Lists CMS edit and frontend links for each page type - useful for testing after upgrades';

    public function run(InputInterface $input, PolyOutput $output): int
    {
        $output->writeForAnsi("<options=bold>{$this->getTitle()}</>", true);
        $output->writeForHtml("<div class='ptl-header'><div><h1>{$this->getTitle()}</h1><p class='ptl-desc'>Checks the HTTP status code of the frontend and CMS edit form for each page type.</p></div><span id='ptl-summary'></span></div>", false);
        return $this->execute($input, $output);
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $baseURL = Director::absoluteBaseURL();
        $pageClasses = ClassInfo::subclassesFor(Page::class);
        $randomise = isset($_GET['randomise']);

        // Collect links for "Open All" buttons
        $cmsLinks = [];
        $frontendLinks = [];
        $expectedStatuses = [];
        $rowAllowedActions = [];
        $rowData = [];

        $output->writeForAnsi("\n<comment>For the best experience, run this task in your browser.</comment>\n\n");

        // Collect all page type data first
        foreach ($pageClasses as $class) {
            // Prioritise live pages, fall back to draft if none published
            $livePages = Versioned::get_by_stage($class, Versioned::LIVE)->filter('ClassName', $class);
            $draftPages = DataObject::get($class)->filter('ClassName', $class);

            $page = null;
            if ($livePages->count() > 0) {
                $page = $randomise ? $livePages->shuffle()->first() : $livePages->first();
            } elseif ($draftPages->count() > 0) {
                $page = $randomise ? $draftPages->shuffle()->first() : $draftPages->first();
            }

            $shortClass = ClassInfo::shortName($class);
            $liveCount = $livePages->count();
            $totalCount = $draftPages->count();

            // Check for allowed_actions on the controller
            $controllerClass = $class . 'Controller';
            $allowedActions = [];
            if (class_exists($controllerClass)) {
                $actions = Config::inst()->get($controllerClass, 'allowed_actions', Config::UNINHERITED);
                if ($actions && is_array($actions)) {
                    // Handle both indexed arrays ['action1', 'action2'] and associative ['action1' => true]
                    foreach ($actions as $key => $value) {
                        $allowedActions[] = is_int($key) ? $value : $key;
                    }
                }
            }

            $rowData[] = [
                'class' => $class,
                'shortClass' => $shortClass,
                'page' => $page,
                'liveCount' => $liveCount,
                'totalCount' => $totalCount,
                'allowedActions' => $allowedActions,
            ];
        }

        // Sort by total count descending
        usort($rowData, fn($a, $b) => $b['totalCount'] <=> $a['totalCount']);

        // Build rows and link arrays
        $rows = [];
        foreach ($rowData as $data) {
            $shortClass = $data['shortClass'];
            $page = $data['page'];
            $liveCount = $data['liveCount'];
            $totalCount = $data['totalCount'];
            $allowedActions = $data['allowedActions'];

            if ($page) {
                $cmsLink = Controller::join_links($baseURL, 'admin/pages/edit/show', $page->ID);
                $frontendLink = $page->AbsoluteLink();

                $cmsLinks[] = $cmsLink;
                $frontendLinks[] = $frontendLink;

                // ErrorPage is expected to return 404 or 500
                $expectedStatus = ($shortClass === 'ErrorPage') ? [404, 500] : [200];
                $expectedStatuses[] = $expectedStatus;
                $rowAllowedActions[] = $allowedActions;

                $rowIndex = count($cmsLinks) - 1;
                $pageUrl = $page->Link();

                // Placeholder for action links - will be populated by JavaScript when checking actions
                $actionsLinksHtml = '';
                if (!empty($allowedActions)) {
                    $actionsLinksHtml = "<span id='actions-container-{$rowIndex}' class='ptl-actions-container'></span>";
                }

                $rows[] = "<tr>"
                    . "<td class='ptl-preview-col'><div class='ptl-preview'><iframe data-src='{$frontendLink}'></iframe></div></td>"
                    . "<td><span class='ptl-type'>{$shortClass}</span></td>"
                    . "<td><span class='ptl-count'>{$liveCount}" . (($totalCount - $liveCount) > 0 ? " <span class='ptl-count-draft'>+ " . ($totalCount - $liveCount) . "</span>" : "") . "<span class='ptl-count-tooltip'>Live pages + Draft-only pages</span></span></td>"
                    . "<td><span id='cms-status-{$rowIndex}' class='ptl-status'><span class='ptl-status-placeholder'>?</span></span><a href='{$cmsLink}' target='_blank' class='ptl-cms'>Edit in CMS</a></td>"
                    . "<td><span id='frontend-status-{$rowIndex}' class='ptl-status'><span class='ptl-status-placeholder'>?</span></span><a href='{$frontendLink}' target='_blank' class='ptl-frontend'>View Page</a><span id='form-indicator-{$rowIndex}'></span>{$actionsLinksHtml}</td>"
                    . "<td><span class='ptl-title'>{$page->Title}</span><span class='ptl-url'>{$pageUrl}</span></td>"
                    . "</tr>";

                // CLI output
                $cliActions = !empty($allowedActions) ? " [has allowed_actions: " . implode(', ', $allowedActions) . "]" : "";
                $draftOnlyCount = $totalCount - $liveCount;
                $output->writeForAnsi("<info>{$shortClass}</info> ({$liveCount} + {$draftOnlyCount}):{$cliActions}\n  Frontend: {$frontendLink}\n  CMS: {$cmsLink}\n");
            } else {
                // Build action links note for pages with no instances
                $actionsNote = '';
                if (!empty($allowedActions)) {
                    $actionsNote = "<div class='ptl-action-note'>Has actions: " . htmlspecialchars(implode(', ', $allowedActions)) . "</div>";
                }

                $rows[] = "<tr>"
                    . "<td class='ptl-preview-col'><div class='ptl-preview-empty'>No preview</div></td>"
                    . "<td><span class='ptl-type'>{$shortClass}</span></td>"
                    . "<td><span class='ptl-count'>0<span class='ptl-count-tooltip'>Live pages + Draft-only pages</span></span></td>"
                    . "<td colspan='3' style='text-align:center;'><span class='ptl-empty'>—</span>{$actionsNote}</td>"
                    . "</tr>";

                $cliActions = !empty($allowedActions) ? " [has allowed_actions: " . implode(', ', $allowedActions) . "]" : "";
                $output->writeForAnsi("<comment>{$shortClass}</comment> (0):{$cliActions} (none)\n");
            }
        }

        // HTML output with Open All buttons
        $cmsLinksJson = json_encode($cmsLinks);
        $frontendLinksJson = json_encode($frontendLinks);
        $expectedStatusesJson = json_encode($expectedStatuses);
        $rowAllowedActionsJson = json_encode($rowAllowedActions);

        $output->writeForHtml("<link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css'>");
        $output->writeForHtml("<style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif; max-width: 1400px; margin: 0 auto; padding: 20px; background: #f5f7fa; min-height: 100vh; }
            .ptl-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 2px solid #e9ecef; }
            h1 { color: #212529; font-weight: 600; font-size: 22px; margin: 0 0 8px 0; }
            #ptl-summary { font-size: 18px; font-weight: 600; padding: 8px 16px; border-radius: 6px; background: #f8f9fa; white-space: nowrap; }
            p.ptl-desc { color: #6c757d; margin: 0; font-size: 13px; background: none !important; }
            .ptl-wrap { overflow-x: auto; }
            .ptl-toolbar { margin-bottom: 20px; display: flex; gap: 20px; flex-wrap: wrap; align-items: center; position: relative; z-index: 10; }
            .ptl-btn-group { display: flex; gap: 0; }
            .ptl-btn-group > .ptl-btn, .ptl-btn-group > .ptl-btn-wrap > .ptl-btn { border-radius: 0; margin-left: -1px; }
            .ptl-btn-group > .ptl-btn:first-child, .ptl-btn-group > .ptl-btn-wrap:first-child > .ptl-btn { border-radius: 6px 0 0 6px; margin-left: 0; }
            .ptl-btn-group > .ptl-btn:last-child, .ptl-btn-group > .ptl-btn-wrap:last-child > .ptl-btn { border-radius: 0 6px 6px 0; }
            .ptl-btn-group > .ptl-btn:only-child, .ptl-btn-group > .ptl-btn-wrap:only-child > .ptl-btn { border-radius: 6px; }
            .ptl-btn { padding: 10px 16px; cursor: pointer; border: 1px solid #ccc; border-radius: 6px; font-size: 13px; background: #fff; transition: all 0.2s; white-space: nowrap; }
            .ptl-btn:hover { background: #f8f9fa; border-color: #adb5bd; }
            .ptl-btn-wrap { position: relative; display: inline-block; }
            .ptl-btn-tooltip { display: none; position: absolute; left: 50%; transform: translateX(-50%); top: 100%; margin-top: 8px; background: #343a40; color: #fff; padding: 8px 12px; border-radius: 6px; font-size: 12px; font-weight: normal; white-space: nowrap; z-index: 100; box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
            .ptl-btn-tooltip::after { content: ''; position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%); border: 6px solid transparent; border-bottom-color: #343a40; }
            .ptl-btn-wrap:hover .ptl-btn-tooltip { display: block; }
            .ptl-btn-primary { background: linear-gradient(135deg, #0071bc, #005a96); color: #fff; border: none; font-weight: 600; padding: 12px 20px; font-size: 14px; box-shadow: 0 2px 4px rgba(0,113,188,0.3); min-width: 180px; }
            .ptl-btn-primary:hover { background: linear-gradient(135deg, #005a96, #004a7c); box-shadow: 0 4px 8px rgba(0,113,188,0.4); }
            .ptl-btn-secondary { background: linear-gradient(135deg, #5a9bd4, #4a8bc4); color: #fff; border: none; font-weight: 600; padding: 12px 20px; font-size: 14px; box-shadow: 0 2px 4px rgba(90,155,212,0.3); min-width: 150px; }
            .ptl-btn-secondary:hover { background: linear-gradient(135deg, #4a8bc4, #3a7bb4); box-shadow: 0 4px 8px rgba(90,155,212,0.4); }
            .ptl-btn-primary:disabled, .ptl-btn-secondary:disabled { background: #6c757d; box-shadow: none; cursor: wait; }
            .ptl-btn-primary.ptl-checking, .ptl-btn-secondary.ptl-checking { cursor: pointer; }
            .ptl-btn-primary.ptl-checking:hover, .ptl-btn-secondary.ptl-checking:hover { background: linear-gradient(135deg, #dc3545, #c82333) !important; box-shadow: 0 2px 4px rgba(220,53,69,0.3); }
            .ptl-divider { width: 1px; height: 24px; background: #dee2e6; }
            .ptl-table { width: 100%; min-width: 900px; border-collapse: collapse; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-radius: 8px; overflow: hidden; background: #fff; font-size: 14px; }
            .ptl-table th { background: #343a40; color: #fff; padding: 14px 20px; text-align: left; font-weight: 600; border-right: 1px solid #495057; font-size: 13px; }
            .ptl-table th:last-child { border-right: none; }
            .ptl-table td { padding: 16px 20px; border-bottom: 1px solid #e9ecef; border-right: 1px solid #f0f0f0; vertical-align: middle; }
            .ptl-table td:last-child { border-right: none; }
            .ptl-table tr:hover td { background: #f8f9fa; }
            .ptl-table tr:last-child td { border-bottom: none; }
            .ptl-table a { text-decoration: none; font-size: 14px; transition: all 0.15s; }
            .ptl-table a span { text-decoration: underline; }
            .ptl-table a:hover span { text-decoration: none; }
            .ptl-table a i { margin-left: 4px; opacity: 0.7; }
            .ptl-table a.ptl-cms { color: #6c757d; font-weight: 600; display: inline-block; margin-left: 5px; text-decoration: underline; }
            .ptl-table a.ptl-cms:hover { color: #495057; }
            .ptl-table a.ptl-frontend { color: #0071bc; display: inline-block; font-weight: 600; margin-left: 5px; text-decoration: underline; }
            .ptl-table a.ptl-frontend:hover { color: #005a96; }
            .ptl-type { color: #212529; font-weight: 600; font-family: 'SF Mono', Monaco, 'Courier New', monospace; font-size: 14px; }
            .ptl-title { color: #495057; font-size: 14px; font-weight: 500; }
            .ptl-url { color: #adb5bd; font-size: 12px; font-family: 'SF Mono', Monaco, 'Courier New', monospace; display: block; margin-top: 4px; word-break: break-all; }
            .ptl-status { display: inline-block; margin-right: 4px; text-align: center; }
            .ptl-status-placeholder { padding: 3px 6px; border-radius: 4px; background: #e9ecef; color: #adb5bd; font-size: 12px; font-weight: 500; display: inline-block; min-width: 46px; text-align: center; box-sizing: border-box; }
            .ptl-status-badge { padding: 3px 6px; border-radius: 4px; font-size: 12px; font-weight: 500; display: inline-block; min-width: 46px; text-align: center; cursor: pointer; box-sizing: border-box; }
            .ptl-count { color: #6c757d; font-size: 14px; text-align: center; display: block; font-weight: 500; cursor: help; position: relative; }
            .ptl-count-draft { color: #adb5bd; }
            .ptl-count-tooltip { display: none; position: absolute; left: 50%; transform: translateX(-50%); bottom: 100%; margin-bottom: 8px; background: #343a40; color: #fff; padding: 8px 12px; border-radius: 6px; font-size: 12px; font-weight: normal; white-space: nowrap; z-index: 100; box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
            .ptl-count-tooltip::after { content: ''; position: absolute; top: 100%; left: 50%; transform: translateX(-50%); border: 6px solid transparent; border-top-color: #343a40; }
            .ptl-count:hover .ptl-count-tooltip { display: block; }
            .ptl-empty { color: #6c757d; font-style: italic; }
            .ptl-preview { width: 200px; height: 150px; overflow: hidden; border-radius: 4px; border: 1px solid #dee2e6; background: #fff; position: relative; }
            .ptl-preview iframe { width: 1200px; height: 900px; transform: scale(0.167); transform-origin: top left; border: none; pointer-events: none; }
            .ptl-preview-empty { width: 200px; height: 150px; background: #e9ecef; border-radius: 4px; display: flex; align-items: center; justify-content: center; color: #adb5bd; font-size: 12px; }
            .ptl-preview-col { display: none; }
            .ptl-previews-visible .ptl-preview-col { display: table-cell; }
            .ptl-actions-container { display: grid; grid-template-columns: auto auto; gap: 4px 6px; margin-top: 8px; align-items: center; justify-content: start; justify-items: start; }
            .ptl-table a.ptl-action { color: #6c757d; font-family: 'SF Mono', Monaco, 'Courier New', monospace; font-size: 12px; text-decoration: none; }
            .ptl-table a.ptl-action:hover { color: #495057; }
            .ptl-action-missing { color: #adb5bd; font-family: 'SF Mono', Monaco, 'Courier New', monospace; font-size: 12px; }
            .ptl-action-alert { color: #f0ad4e; margin-left: 8px; }
            .ptl-action-note { margin-top: 8px; font-size: 11px; color: #856404; background: #fff3cd; padding: 4px 8px; border-radius: 4px; display: inline-block; }
            .ptl-actions-warning { display: inline-block; margin-left: 8px; vertical-align: middle; position: relative; }
            .ptl-btn-actions { padding: 3px 8px; font-size: 12px; cursor: pointer; border: none; border-radius: 4px; background: #f0ad4e; color: #fff; font-weight: 500; position: relative; }
            .ptl-btn-actions:hover { background: #ec971f; }
            .ptl-btn-actions span.action-name { font-family: 'SF Mono', Monaco, 'Courier New', monospace; }
            .ptl-actions-tooltip { display: none; position: absolute; left: 50%; transform: translateX(-50%); bottom: 100%; margin-bottom: 8px; background: #343a40; color: #fff; padding: 10px 14px; border-radius: 6px; font-size: 12px; font-weight: normal; white-space: nowrap; z-index: 100; box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
            .ptl-actions-tooltip::after { content: ''; position: absolute; top: 100%; left: 50%; transform: translateX(-50%); border: 6px solid transparent; border-top-color: #343a40; }
            .ptl-btn-actions:hover .ptl-actions-tooltip { display: block; }
            .ptl-form-indicator { display: inline-block; margin-left: 8px; padding: 3px 8px; font-size: 12px; border-radius: 4px; background: #fff3cd; color: #856404; border: 1px solid #ffc107; font-weight: 500; cursor: help; vertical-align: middle; position: relative; }
            .ptl-form-indicator .ptl-form-tooltip { display: none; position: absolute; left: 50%; transform: translateX(-50%); bottom: 100%; margin-bottom: 8px; background: #343a40; color: #fff; padding: 8px 12px; border-radius: 6px; font-size: 12px; font-weight: normal; white-space: nowrap; z-index: 100; box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
            .ptl-form-indicator .ptl-form-tooltip::after { content: ''; position: absolute; top: 100%; left: 50%; transform: translateX(-50%); border: 6px solid transparent; border-top-color: #343a40; }
            .ptl-form-indicator:hover .ptl-form-tooltip { display: block; }
            .ptl-check-badge { padding: 3px 8px; border-radius: 4px; background: #fff3cd; color: #856404; font-size: 12px; font-weight: 500; cursor: help; position: relative; display: inline-block; }
            .ptl-check-tooltip { display: none; position: absolute; left: 50%; transform: translateX(-50%); bottom: 100%; margin-bottom: 8px; background: #343a40; color: #fff; padding: 8px 12px; border-radius: 6px; font-size: 12px; font-weight: normal; white-space: nowrap; z-index: 100; box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
            .ptl-check-tooltip::after { content: ''; position: absolute; top: 100%; left: 50%; transform: translateX(-50%); border: 6px solid transparent; border-top-color: #343a40; }
            .ptl-check-badge:hover .ptl-check-tooltip { display: block; }
            .ptl-form-badge { margin-left: 6px; padding: 3px 8px; border-radius: 4px; background: #fff3cd; color: #856404; font-size: 12px; font-weight: 500; cursor: help; position: relative; display: inline-block; }
            .ptl-form-badge-tooltip { display: none; position: absolute; left: 50%; transform: translateX(-50%); bottom: 100%; margin-bottom: 8px; background: #343a40; color: #fff; padding: 8px 12px; border-radius: 6px; font-size: 12px; font-weight: normal; white-space: nowrap; z-index: 100; box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
            .ptl-form-badge-tooltip::after { content: ''; position: absolute; top: 100%; left: 50%; transform: translateX(-50%); border: 6px solid transparent; border-top-color: #343a40; }
            .ptl-form-badge:hover .ptl-form-badge-tooltip { display: block; }
            .ptl-help { margin-top: 24px; padding: 20px 24px; background: linear-gradient(135deg, #f8f9fa 0%, #fff 100%); border: 1px solid #dee2e6; border-radius: 8px; font-size: 13px; color: #495057; display: flex; gap: 40px; }
            .ptl-help h3 { margin: 0 0 6px 0; font-size: 13px; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
            .ptl-help strong { display: block; margin-bottom: 8px; }
            .ptl-help ul { margin: 0; padding-left: 18px; }
            .ptl-help li { margin-bottom: 5px; line-height: 1.5; }
            .ptl-help-section { flex: 1; }
            .ptl-help code { background: #e9ecef; padding: 2px 5px; border-radius: 3px; font-size: 11px; font-family: 'SF Mono', Monaco, 'Courier New', monospace; }
            @media (max-width: 1100px) {
                .ptl-table td { padding: 10px 12px; }
                .ptl-table th { padding: 10px 12px; }
                .ptl-table { font-size: 13px; }
                .ptl-type { font-size: 12px; }
                .ptl-title { font-size: 13px; }
                .ptl-url { font-size: 11px; }
                .ptl-table a { font-size: 13px; }
                .ptl-table a.ptl-action, .ptl-action-missing { font-size: 11px; }
                .ptl-help { flex-direction: column; gap: 20px; }
            }
        </style>");

        $output->writeForHtml("<div class='ptl-wrap'>");
        $output->writeForHtml("<div class='ptl-toolbar'>");

        // Primary actions
        $output->writeForHtml("<div class='ptl-btn-group'>");
        $output->writeForHtml("<button onclick='checkAllLinks(true)' id='check-actions-btn' class='ptl-btn ptl-btn-primary'><i class='fa-solid fa-check-double'></i> Check Links & Actions</button>");
        $output->writeForHtml("<button onclick='checkAllLinks(false)' id='check-btn' class='ptl-btn ptl-btn-secondary'><i class='fa-solid fa-check'></i> Check Links Only</button>");
        $output->writeForHtml("</div>");

        $output->writeForHtml("<div class='ptl-divider'></div>");

        // Open links group
        $output->writeForHtml("<div class='ptl-btn-group'>");
        $linkCount = count($cmsLinks);
        $output->writeForHtml("<span class='ptl-btn-wrap'><button onclick='openAll({$cmsLinksJson})' class='ptl-btn'><i class='fa-solid fa-pen-to-square'></i> Open All CMS ({$linkCount})</button><span class='ptl-btn-tooltip'>Opens {$linkCount} CMS tabs</span></span>");
        $output->writeForHtml("<span class='ptl-btn-wrap'><button onclick='openAll({$frontendLinksJson})' class='ptl-btn'><i class='fa-solid fa-arrow-up-right-from-square'></i> Open All Frontend ({$linkCount})</button><span class='ptl-btn-tooltip'>Opens {$linkCount} frontend tabs</span></span>");
        $output->writeForHtml("</div>");

        $output->writeForHtml("<div class='ptl-divider'></div>");

        // View options
        $output->writeForHtml("<button onclick='togglePreviews()' id='preview-btn' class='ptl-btn'><i class='fa-solid fa-eye'></i> Show Previews</button>");

        $output->writeForHtml("<div class='ptl-divider'></div>");

        // Randomise group
        if ($randomise) {
            $output->writeForHtml("<div class='ptl-btn-group'>");
            $output->writeForHtml("<button onclick='randomisePages()' class='ptl-btn'><i class='fa-solid fa-shuffle'></i> Randomise</button>");
            $output->writeForHtml("<button onclick='resetPages()' class='ptl-btn'><i class='fa-solid fa-rotate-left'></i> Reset</button>");
            $output->writeForHtml("</div>");
        } else {
            $output->writeForHtml("<button onclick='randomisePages()' class='ptl-btn'><i class='fa-solid fa-shuffle'></i> Randomise</button>");
        }

        $output->writeForHtml("</div>");

        $output->writeForHtml("<table class='ptl-table'>");
        $output->writeForHtml("<tr><th class='ptl-preview-col'>Preview</th><th>Page Type</th><th>Count</th><th>CMS Edit Form</th><th>Frontend</th><th>Example Page</th></tr>");
        foreach ($rows as $row) {
            $output->writeForHtml($row);
        }
        $output->writeForHtml("</table>");

        $output->writeForHtml("<div class='ptl-help'>
            <h3>How Does This Work?</h3>
            <div class='ptl-help-section'>
                <strong>What it checks:</strong>
                <ul>
                    <li><strong>CMS Edit Form</strong> – Verifies the page's CMS edit URL returns HTTP 200</li>
                    <li><strong>Frontend</strong> – Verifies the page's live URL returns HTTP 200 (or 404/500 for ErrorPages)</li>
                    <li><strong>Actions</strong> – If a page controller has <code>\$allowed_actions</code>, it scrapes the page HTML to find matching URLs and checks their status</li>
                    <li><strong>Forms</strong> – Detects <code>&lt;form&gt;</code> tags in the main page content (excluding header/footer)</li>
                </ul>
            </div>
            <div class='ptl-help-section'>
                <strong>What it does NOT check:</strong>
                <ul>
                    <li>Form submissions or validation</li>
                    <li>JavaScript functionality or errors</li>
                    <li>Visual rendering or layout issues</li>
                    <li>Broken links within page content</li>
                    <li>Database integrity or data accuracy</li>
                    <li>Performance or page load times</li>
                </ul>
            </div>
        </div>");

        $output->writeForHtml("</div>");

        $output->writeForHtml("<script>
var cmsLinks = {$cmsLinksJson};
var frontendLinks = {$frontendLinksJson};
var expectedStatuses = {$expectedStatusesJson};
var rowAllowedActions = {$rowAllowedActionsJson};
var baseURL = '{$baseURL}';
var foundActionLinks = {};

function openAll(links) {
    links.forEach(function(url) { window.open(url, '_blank'); });
}

function randomisePages() {
    var url = new URL(window.location.href);
    url.searchParams.set('randomise', '1');
    window.location.href = url.toString();
}

function resetPages() {
    var url = new URL(window.location.href);
    url.searchParams.delete('randomise');
    window.location.href = url.toString();
}


var previewsLoaded = false;
function togglePreviews() {
    var table = document.querySelector('.ptl-table');
    var btn = document.getElementById('preview-btn');
    table.classList.toggle('ptl-previews-visible');

    if (table.classList.contains('ptl-previews-visible')) {
        btn.innerHTML = '<i class=\"fa-solid fa-eye-slash\"></i> Hide Previews';
        if (!previewsLoaded) {
            document.querySelectorAll('.ptl-preview iframe[data-src]').forEach(function(iframe) {
                iframe.src = iframe.dataset.src;
            });
            previewsLoaded = true;
        }
    } else {
        btn.innerHTML = '<i class=\"fa-solid fa-eye\"></i> Show Previews';
    }
}

async function checkLink(url, getHtml = false) {
    try {
        const response = await fetch(url, { method: 'GET', credentials: 'include' });
        var result = { status: response.status, ok: response.ok };
        if (getHtml) {
            result.html = await response.text();
        }
        return result;
    } catch (e) {
        return { status: 'ERR', ok: false, html: '' };
    }
}

function detectForms(html) {
    // Remove header and footer content before checking for forms
    var cleanHtml = html.replace(/<header[^>]*>[\s\S]*?<\/header>/gi, '');
    cleanHtml = cleanHtml.replace(/<footer[^>]*>[\s\S]*?<\/footer>/gi, '');
    // Find all form tags
    var formMatch = cleanHtml.match(/<form[^>]*>/gi);
    if (!formMatch) return 0;
    // Exclude BetterNavigator forms
    var count = 0;
    for (var i = 0; i < formMatch.length; i++) {
        if (formMatch[i].indexOf('BetterNavigator') === -1) {
            count++;
        }
    }
    return count;
}

function findActionLinks(html, actions, frontendUrl) {
    var found = {};
    var hrefRegex = /href=[\"']([^\"']+)[\"']/gi;
    var match;
    var links = [];
    while ((match = hrefRegex.exec(html)) !== null) {
        links.push(match[1]);
    }

    // Actions that can be checked directly without needing an ID
    var directActions = ['rss', 'index'];

    actions.forEach(function(action) {
        var pattern = new RegExp('/' + action + '(/|[?]|\$)', 'i');
        for (var i = 0; i < links.length; i++) {
            if (pattern.test(links[i])) {
                var link = links[i];
                if (link.indexOf('http') !== 0) {
                    if (link.indexOf('/') === 0) {
                        link = baseURL.replace(/\\/$/, '') + link;
                    } else {
                        link = frontendUrl.replace(/\\/$/, '') + '/' + link;
                    }
                }
                found[action] = link;
                break;
            }
        }
        // If not found and it's a direct action, construct URL directly
        if (!found[action] && directActions.indexOf(action.toLowerCase()) !== -1) {
            found[action] = frontendUrl.replace(/\\/$/, '') + '/' + action;
        }
    });
    return found;
}

function renderActionLinks(rowIndex, actions, foundLinks) {
    var container = document.getElementById('actions-container-' + rowIndex);
    if (!container) return;

    var html = '';
    actions.forEach(function(action) {
        if (foundLinks[action]) {
            var url = foundLinks[action];
            var actionId = 'action-' + rowIndex + '-' + action;
            html += \"<span id='\" + actionId + \"' class='ptl-status'><span class='ptl-status-placeholder'>...</span></span><a href='\" + url + \"' target='_blank' class='ptl-action' title='\" + url + \"'>/\" + action + \"</a>\";
        } else {
            html += \"<span class='ptl-status'><span class='ptl-check-badge'><i class='fa-solid fa-triangle-exclamation'></i> check<span class='ptl-check-tooltip'>No link found on page for this action. Visit the page and manually verify it works.</span></span></span><span class='ptl-action-missing'>/\" + action + \"</span>\";
        }
    });
    container.innerHTML = html;
    return foundLinks;
}

async function checkActionLink(rowIndex, action, url) {
    var spanId = 'action-' + rowIndex + '-' + action;
    var span = document.getElementById(spanId);
    if (!span) return { passed: false };

    var result = await checkLink(url);
    var isExpected = result.status === 200;
    var color = isExpected ? '#28a745' : '#dc3545';
    var bgColor = isExpected ? '#d4edda' : '#f8d7da';
    var icon = isExpected ? ' <i class=\"fa-solid fa-check\"></i>' : ' <i class=\"fa-solid fa-xmark\"></i>';
    span.innerHTML = '<span class=\"ptl-status-badge\" style=\"background:' + bgColor + ';color:' + color + ';border:1px solid ' + color + ';\" title=\"Click to recheck\">' + result.status + icon + '</span>';
    return { passed: isExpected };
}

async function checkRowActions(rowIndex) {
    var actions = rowAllowedActions[rowIndex];
    if (!actions || actions.length === 0) return;

    var container = document.getElementById('actions-container-' + rowIndex);
    if (!container) return;

    container.innerHTML = '<span class=\"ptl-actions-warning\"><span class=\"ptl-btn-actions\" style=\"opacity:0.7;cursor:wait;\">...</span></span>';

    // Fetch the page to find action links
    var frontendResult = await checkLink(frontendLinks[rowIndex], true);
    if (!frontendResult.html) {
        container.innerHTML = '<span class=\"ptl-actions-warning\"><span class=\"ptl-btn-actions\" style=\"background:#dc3545;\">error</span></span>';
        return;
    }

    var foundLinks = findActionLinks(frontendResult.html, actions, frontendLinks[rowIndex]);
    foundActionLinks[rowIndex] = foundLinks;
    renderActionLinks(rowIndex, actions, foundLinks);

    // Check each found action link
    var rowPassed = 0;
    var rowFailed = 0;
    var rowNotFound = 0;
    for (var a = 0; a < actions.length; a++) {
        var action = actions[a];
        if (foundLinks[action]) {
            var result = await checkActionLink(rowIndex, action, foundLinks[action]);
            if (result.passed) { rowPassed++; } else { rowFailed++; }
        } else {
            rowNotFound++;
        }
    }

}

function statusBadge(result, expectedStatuses, type, index) {
    var isExpected = expectedStatuses.indexOf(result.status) !== -1;
    var color, textColor, icon;
    if (isExpected) {
        color = '#28a745';
        textColor = '#fff';
        icon = ' <i class=\"fa-solid fa-check\"></i>';
    } else if (result.status >= 300 && result.status < 400) {
        color = '#ffc107';
        textColor = '#000';
        icon = ' <i class=\"fa-solid fa-xmark\"></i>';
    } else {
        color = '#dc3545';
        textColor = '#fff';
        icon = ' <i class=\"fa-solid fa-xmark\"></i>';
    }
    return '<span onclick=\"recheckLink(\\'' + type + '\\', ' + index + ')\" class=\"ptl-status-badge\" style=\"background:' + color + ';color:' + textColor + ';\" title=\"Click to recheck\">' + result.status + icon + '</span>';
}

async function recheckLink(type, index) {
    var span = document.getElementById(type + '-status-' + index);
    span.innerHTML = '<span class=\"ptl-status-placeholder\">...</span>';

    var url, expected;
    if (type === 'cms') {
        url = cmsLinks[index];
        expected = [200];
    } else {
        url = frontendLinks[index];
        expected = expectedStatuses[index];
    }

    var result = await checkLink(url);
    span.innerHTML = statusBadge(result, expected, type, index);
}

var isChecking = false;
var stopChecking = false;
var checkingText = '';
var isHoveringCheck = false;

function updateCheckButton() {
    var btn = document.getElementById('check-btn');
    if (isChecking && !isHoveringCheck) {
        btn.innerHTML = checkingText;
    }
}

async function checkAllLinks(includeActions) {
    var btn = document.getElementById('check-btn');
    var actionsBtn = document.getElementById('check-actions-btn');
    var summary = document.getElementById('ptl-summary');

    if (isChecking) {
        stopChecking = true;
        return;
    }

    isChecking = true;
    stopChecking = false;
    var activeBtn = includeActions ? actionsBtn : btn;
    var inactiveBtn = includeActions ? btn : actionsBtn;
    inactiveBtn.disabled = true;
    activeBtn.classList.add('ptl-checking');
    summary.innerHTML = '';
    summary.style.background = '#f8f9fa';

    // Clear action containers and show warnings if not checking actions
    if (!includeActions) {
        for (var idx = 0; idx < rowAllowedActions.length; idx++) {
            var container = document.getElementById('actions-container-' + idx);
            if (container && rowAllowedActions[idx].length > 0) {
                var actions = rowAllowedActions[idx];
                var btnText = actions.length === 1 ? '<span class=\"action-name\">' + actions[0] + '</span>' : actions.length + ' actions';
                container.innerHTML = '<span class=\"ptl-actions-warning\"><button onclick=\"checkRowActions(' + idx + ')\" class=\"ptl-btn-actions\">' + btnText + '<span class=\"ptl-actions-tooltip\"><em>Click to detect and test:</em><br><strong>' + actions.join('</strong>, <strong>') + '</strong></span></button></span>';
            } else if (container) {
                container.innerHTML = '';
            }
        }
    }

    activeBtn.onmouseenter = function() { if (isChecking) { isHoveringCheck = true; activeBtn.innerHTML = '<i class=\"fa-solid fa-stop\"></i> Stop'; } };
    activeBtn.onmouseleave = function() { if (isChecking) { isHoveringCheck = false; activeBtn.innerHTML = checkingText; } };

    var passed = 0;
    var failed = 0;
    var actionsPassed = 0;
    var actionsFailed = 0;
    var actionsNotFound = 0;
    var total = cmsLinks.length * 2;
    var checked = 0;

    // First pass: count total including potential action links
    var actionCount = 0;
    var totalActionsDetected = 0;
    rowAllowedActions.forEach(function(actions) { totalActionsDetected += actions.length; });
    if (includeActions) {
        actionCount = totalActionsDetected;
    }

    for (var i = 0; i < cmsLinks.length; i++) {
        if (stopChecking) break;

        var cmsSpan = document.getElementById('cms-status-' + i);
        var frontendSpan = document.getElementById('frontend-status-' + i);
        var actions = rowAllowedActions[i] || [];

        if (cmsSpan) {
            checked++;
            checkingText = '<i class=\"fa-solid fa-spinner fa-spin\"></i> Checking ' + checked + '/' + (total + actionCount) + '...';
            if (!isHoveringCheck) activeBtn.innerHTML = checkingText;
            cmsSpan.innerHTML = '<span class=\"ptl-status-placeholder\">...</span>';
            var cmsResult = await checkLink(cmsLinks[i]);
            if (stopChecking) { cmsSpan.innerHTML = ''; break; }
            cmsSpan.innerHTML = statusBadge(cmsResult, [200], 'cms', i);
            if (cmsResult.status === 200) { passed++; } else { failed++; }
        }

        if (stopChecking) break;

        if (frontendSpan) {
            checked++;
            checkingText = '<i class=\"fa-solid fa-spinner fa-spin\"></i> Checking ' + checked + '/' + (total + actionCount) + '...';
            if (!isHoveringCheck) activeBtn.innerHTML = checkingText;
            frontendSpan.innerHTML = '<span class=\"ptl-status-placeholder\">...</span>';

            // Always fetch HTML to detect forms and optionally check actions
            var needsHtml = true;
            var frontendResult = await checkLink(frontendLinks[i], needsHtml);
            if (stopChecking) { frontendSpan.innerHTML = ''; break; }
            frontendSpan.innerHTML = statusBadge(frontendResult, expectedStatuses[i], 'frontend', i);
            if (expectedStatuses[i].indexOf(frontendResult.status) !== -1) { passed++; } else { failed++; }

            // Detect forms on page
            var formIndicator = document.getElementById('form-indicator-' + i);
            if (formIndicator && frontendResult.html) {
                var formCount = detectForms(frontendResult.html);
                if (formCount > 0) {
                    formIndicator.innerHTML = '<span class=\"ptl-form-badge\"><i class=\"fa-solid fa-file-lines\"></i> form<span class=\"ptl-form-badge-tooltip\">A form was detected on this page. Check it manually to ensure it works correctly.</span></span>';
                }
            }

            // Parse and check action links if we're including actions
            if (includeActions && actions.length > 0 && frontendResult.html) {
                var foundLinks = findActionLinks(frontendResult.html, actions, frontendLinks[i]);
                foundActionLinks[i] = foundLinks;
                renderActionLinks(i, actions, foundLinks);

                // Now check each found action link
                for (var a = 0; a < actions.length; a++) {
                    if (stopChecking) break;
                    var action = actions[a];
                    if (foundLinks[action]) {
                        checked++;
                        checkingText = '<i class=\"fa-solid fa-spinner fa-spin\"></i> Checking ' + checked + '/' + (total + actionCount) + '...';
                        if (!isHoveringCheck) activeBtn.innerHTML = checkingText;
                        var actionResult = await checkActionLink(i, action, foundLinks[action]);
                        if (actionResult.passed) { actionsPassed++; } else { actionsFailed++; }
                    } else {
                        actionsNotFound++;
                    }
                }
            }
        }
    }

    isChecking = false;
    isHoveringCheck = false;
    inactiveBtn.disabled = false;
    activeBtn.classList.remove('ptl-checking');
    activeBtn.onmouseenter = null;
    activeBtn.onmouseleave = null;
    btn.innerHTML = '<i class=\"fa-solid fa-check\"></i> Check Links Only';
    actionsBtn.innerHTML = '<i class=\"fa-solid fa-check-double\"></i> Check Links & Actions';

    var totalPassed = passed + actionsPassed;
    var totalFailed = failed + actionsFailed;
    var totalManual = actionsNotFound;

    if (stopChecking) {
        summary.style.background = '#fff3cd';
        var msg = '<i class=\"fa-solid fa-triangle-exclamation\"></i> Stopped: ' + totalFailed + ' failed, ' + totalPassed + ' passed';
        if (totalManual > 0) msg += ', ' + totalManual + ' manual';
        summary.innerHTML = '<span style=\"color:#856404;\">' + msg + '</span>';
    } else if (totalFailed === 0) {
        summary.style.background = '#d4edda';
        var msg = '✓ ' + totalPassed + ' passed';
        if (totalManual > 0) msg += ', ' + totalManual + ' manual';
        if (!includeActions && totalActionsDetected > 0) {
            msg += ' <span style=\"color:#856404;\">(' + totalActionsDetected + ' actions not checked)</span>';
        }
        summary.innerHTML = '<span style=\"color:#155724;\">' + msg + '</span>';
    } else {
        summary.style.background = '#f8d7da';
        var msg = '✗ ' + totalFailed + ' failed, ' + totalPassed + ' passed';
        if (totalManual > 0) msg += ', ' + totalManual + ' manual';
        summary.innerHTML = '<span style=\"color:#721c24;\">' + msg + '</span>';
    }
}

</script>");

        return Command::SUCCESS;
    }
}
