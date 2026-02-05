<?php

namespace PurpleSpider\PageTypeTester;

use Page;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataObject;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Core\ClassInfo;
use SilverStripe\PolyExecution\PolyOutput;
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
        $output->writeForHtml("<div class='ptl-header'><div><h1>{$this->getTitle()}</h1><p class='ptl-desc'>Checks the HTTP status code of the frontend and CMS edit page for each page type.</p></div><span id='ptl-summary'></span></div>", false);
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
        $rowData = [];

        $output->writeForAnsi("\n<comment>For the best experience, run this task in your browser.</comment>\n\n");

        // Collect all page type data first
        foreach ($pageClasses as $class) {
            $pages = DataObject::get($class)->filter('ClassName', $class);
            $page = $randomise ? $pages->shuffle()->first() : $pages->first();
            $shortClass = ClassInfo::shortName($class);
            $count = $pages->count();

            $rowData[] = [
                'class' => $class,
                'shortClass' => $shortClass,
                'page' => $page,
                'count' => $count,
            ];
        }

        // Sort by count descending
        usort($rowData, fn($a, $b) => $b['count'] <=> $a['count']);

        // Build rows and link arrays
        $rows = [];
        foreach ($rowData as $data) {
            $shortClass = $data['shortClass'];
            $page = $data['page'];
            $count = $data['count'];

            if ($page) {
                $cmsLink = Controller::join_links($baseURL, 'admin/pages/edit/show', $page->ID);
                $frontendLink = $page->AbsoluteLink();

                $cmsLinks[] = $cmsLink;
                $frontendLinks[] = $frontendLink;

                // ErrorPage is expected to return 404 or 500
                $expectedStatus = ($shortClass === 'ErrorPage') ? [404, 500] : [200];
                $expectedStatuses[] = $expectedStatus;

                $rowIndex = count($cmsLinks) - 1;
                $pageUrl = $page->Link();
                $rows[] = "<tr>"
                    . "<td class='ptl-preview-col'><div class='ptl-preview'><iframe data-src='{$frontendLink}'></iframe></div></td>"
                    . "<td><span class='ptl-type'>{$shortClass}</span></td>"
                    . "<td><span class='ptl-count'>{$count}</span></td>"
                    . "<td><a href='{$cmsLink}' target='_blank' class='ptl-cms'>Edit in CMS</a><span id='cms-status-{$rowIndex}' class='ptl-status'></span></td>"
                    . "<td><a href='{$frontendLink}' target='_blank' class='ptl-frontend'>View Page</a><span id='frontend-status-{$rowIndex}' class='ptl-status'></span></td>"
                    . "<td><span class='ptl-title'>{$page->Title}</span><span class='ptl-url'>{$pageUrl}</span></td>"
                    . "</tr>";

                // CLI output
                $output->writeForAnsi("<info>{$shortClass}</info> ({$count}):\n  Frontend: {$frontendLink}\n  CMS: {$cmsLink}\n");
            } else {
                $rows[] = "<tr>"
                    . "<td class='ptl-preview-col'><div class='ptl-preview-empty'>No preview</div></td>"
                    . "<td><span class='ptl-type'>{$shortClass}</span></td>"
                    . "<td><span class='ptl-count'>0</span></td>"
                    . "<td colspan='3' style='text-align:center;'><span class='ptl-empty'>—</span></td>"
                    . "</tr>";

                $output->writeForAnsi("<comment>{$shortClass}</comment> (0): (none)\n");
            }
        }

        // HTML output with Open All buttons
        $cmsLinksJson = json_encode($cmsLinks);
        $frontendLinksJson = json_encode($frontendLinks);
        $expectedStatusesJson = json_encode($expectedStatuses);

        $output->writeForHtml("<style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif; max-width: 1400px; margin: 0 auto; padding: 20px; background: #f5f7fa; min-height: 100vh; }
            .ptl-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 2px solid #e9ecef; }
            h1 { color: #212529; font-weight: 600; font-size: 22px; margin: 0 0 8px 0; }
            #ptl-summary { font-size: 18px; font-weight: 600; padding: 8px 16px; border-radius: 6px; background: #f8f9fa; white-space: nowrap; }
            p.ptl-desc { color: #6c757d; margin: 0; font-size: 13px; background: none !important; }
            .ptl-wrap { }
            .ptl-toolbar { margin-bottom: 20px; display: flex; gap: 20px; flex-wrap: wrap; align-items: center; }
            .ptl-btn-group { display: flex; gap: 0; }
            .ptl-btn-group .ptl-btn { border-radius: 0; margin-left: -1px; }
            .ptl-btn-group .ptl-btn:first-child { border-radius: 6px 0 0 6px; margin-left: 0; }
            .ptl-btn-group .ptl-btn:last-child { border-radius: 0 6px 6px 0; }
            .ptl-btn-group .ptl-btn:only-child { border-radius: 6px; }
            .ptl-btn { padding: 10px 16px; cursor: pointer; border: 1px solid #ccc; border-radius: 6px; font-size: 13px; background: #fff; transition: all 0.2s; white-space: nowrap; }
            .ptl-btn:hover { background: #f8f9fa; border-color: #adb5bd; }
            .ptl-btn-primary { background: linear-gradient(135deg, #0071bc, #005a96); color: #fff; border: none; font-weight: 600; padding: 12px 20px; font-size: 14px; box-shadow: 0 2px 4px rgba(0,113,188,0.3); }
            .ptl-btn-primary:hover { background: linear-gradient(135deg, #005a96, #004a7c); box-shadow: 0 4px 8px rgba(0,113,188,0.4); }
            .ptl-btn-primary:disabled { background: #6c757d; box-shadow: none; cursor: wait; }
            .ptl-btn-primary.ptl-checking { cursor: pointer; }
            .ptl-btn-primary.ptl-checking:hover { background: linear-gradient(135deg, #dc3545, #c82333) !important; box-shadow: 0 2px 4px rgba(220,53,69,0.3); }
            .ptl-divider { width: 1px; height: 24px; background: #dee2e6; }
            .ptl-table { width: 100%; border-collapse: collapse; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-radius: 8px; overflow: hidden; background: #fff; }
            .ptl-table th { background: #343a40; color: #fff; padding: 14px 16px; text-align: left; font-weight: 600; }
            .ptl-table td { padding: 12px 16px; border-bottom: 1px solid #e9ecef; }
            .ptl-table tr:hover td { background: #f8f9fa; }
            .ptl-table tr:last-child td { border-bottom: none; }
            .ptl-table a { text-decoration: underline; font-size: 13px; transition: all 0.15s; }
            .ptl-table a:hover { text-decoration: none; }
            .ptl-table a.ptl-cms { color: #6c757d; }
            .ptl-table a.ptl-cms:hover { color: #495057; }
            .ptl-table a.ptl-frontend { color: #0071bc; }
            .ptl-table a.ptl-frontend:hover { color: #005a96; }
            .ptl-type { color: #212529; font-weight: 600; font-family: 'SF Mono', Monaco, 'Courier New', monospace; font-size: 14px; }
            .ptl-title { color: #6c757d; font-size: 13px; }
            .ptl-url { color: #adb5bd; font-size: 11px; font-family: 'SF Mono', Monaco, 'Courier New', monospace; display: block; margin-top: 2px; }
            .ptl-status { display: inline-block; min-width: 42px; margin-left: 10px; text-align: center; }
            .ptl-count { color: #6c757d; font-size: 13px; text-align: center; display: block; }
            .ptl-empty { color: #6c757d; font-style: italic; }
            .ptl-preview { width: 200px; height: 150px; overflow: hidden; border-radius: 4px; border: 1px solid #dee2e6; background: #fff; position: relative; }
            .ptl-preview iframe { width: 1200px; height: 900px; transform: scale(0.167); transform-origin: top left; border: none; pointer-events: none; }
            .ptl-preview-empty { width: 200px; height: 150px; background: #e9ecef; border-radius: 4px; display: flex; align-items: center; justify-content: center; color: #adb5bd; font-size: 12px; }
            .ptl-preview-col { display: none; }
            .ptl-previews-visible .ptl-preview-col { display: table-cell; }
        </style>");

        $output->writeForHtml("<div class='ptl-wrap'>");
        $output->writeForHtml("<div class='ptl-toolbar'>");

        // Primary action
        $output->writeForHtml("<button onclick='checkAllLinks()' id='check-btn' class='ptl-btn ptl-btn-primary'>✓ Check All Links</button>");

        $output->writeForHtml("<div class='ptl-divider'></div>");

        // Open links group
        $output->writeForHtml("<div class='ptl-btn-group'>");
        $linkCount = count($cmsLinks);
        $output->writeForHtml("<button onclick='openAll({$cmsLinksJson})' class='ptl-btn'>✎ Open All CMS ({$linkCount})</button>");
        $output->writeForHtml("<button onclick='openAll({$frontendLinksJson})' class='ptl-btn'>↗ Open All Frontend ({$linkCount})</button>");
        $output->writeForHtml("</div>");

        $output->writeForHtml("<div class='ptl-divider'></div>");

        // View options
        $output->writeForHtml("<button onclick='togglePreviews()' id='preview-btn' class='ptl-btn'>◉ Show Previews</button>");

        $output->writeForHtml("<div class='ptl-divider'></div>");

        // Randomise group
        if ($randomise) {
            $output->writeForHtml("<div class='ptl-btn-group'>");
            $output->writeForHtml("<button onclick='randomisePages()' class='ptl-btn'>⟳ Randomise Pages</button>");
            $output->writeForHtml("<button onclick='resetPages()' class='ptl-btn'>↺ Reset</button>");
            $output->writeForHtml("</div>");
        } else {
            $output->writeForHtml("<button onclick='randomisePages()' class='ptl-btn'>⟳ Randomise Pages</button>");
        }

        $output->writeForHtml("</div>");

        $output->writeForHtml("<table class='ptl-table'>");
        $output->writeForHtml("<tr><th class='ptl-preview-col'>Preview</th><th>Page Type</th><th>Count</th><th>CMS Edit Form</th><th>Frontend</th><th>Example Page</th></tr>");
        foreach ($rows as $row) {
            $output->writeForHtml($row);
        }
        $output->writeForHtml("</table>");
        $output->writeForHtml("</div>");

        $output->writeForHtml("<script>
var cmsLinks = {$cmsLinksJson};
var frontendLinks = {$frontendLinksJson};
var expectedStatuses = {$expectedStatusesJson};

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
        btn.textContent = '◉ Hide Page Previews';
        if (!previewsLoaded) {
            document.querySelectorAll('.ptl-preview iframe[data-src]').forEach(function(iframe) {
                iframe.src = iframe.dataset.src;
            });
            previewsLoaded = true;
        }
    } else {
        btn.textContent = '◉ Show Page Previews';
    }
}

async function checkLink(url) {
    try {
        const response = await fetch(url, { method: 'GET', credentials: 'include' });
        return { status: response.status, ok: response.ok };
    } catch (e) {
        return { status: 'ERR', ok: false };
    }
}

function statusBadge(result, expectedStatuses, type, index) {
    var isExpected = expectedStatuses.indexOf(result.status) !== -1;
    var color, textColor, icon;
    if (isExpected) {
        color = '#28a745';
        textColor = '#fff';
        icon = ' ✓';
    } else if (result.status >= 300 && result.status < 400) {
        color = '#ffc107';
        textColor = '#000';
        icon = ' ✗';
    } else {
        color = '#dc3545';
        textColor = '#fff';
        icon = ' ✗';
    }
    return '<span onclick=\"recheckLink(\\'' + type + '\\', ' + index + ')\" style=\"padding:3px 8px;border-radius:4px;background:' + color + ';color:' + textColor + ';font-size:12px;font-weight:500;cursor:pointer;\" title=\"Click to recheck\">' + result.status + icon + '</span>';
}

async function recheckLink(type, index) {
    var span = document.getElementById(type + '-status-' + index);
    span.innerHTML = '...';

    var url = (type === 'cms') ? cmsLinks[index] : frontendLinks[index];
    var expected = (type === 'cms') ? [200] : expectedStatuses[index];

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
        btn.textContent = checkingText;
    }
}

async function checkAllLinks() {
    var btn = document.getElementById('check-btn');
    var summary = document.getElementById('ptl-summary');

    if (isChecking) {
        stopChecking = true;
        return;
    }

    isChecking = true;
    stopChecking = false;
    btn.disabled = false;
    btn.classList.add('ptl-checking');
    summary.innerHTML = '';
    summary.style.background = '#f8f9fa';

    btn.onmouseenter = function() { if (isChecking) { isHoveringCheck = true; btn.textContent = '⏹ Stop Checking'; } };
    btn.onmouseleave = function() { if (isChecking) { isHoveringCheck = false; btn.textContent = checkingText; } };

    var passed = 0;
    var failed = 0;
    var total = cmsLinks.length * 2;
    var checked = 0;

    for (var i = 0; i < cmsLinks.length; i++) {
        if (stopChecking) break;

        var cmsSpan = document.getElementById('cms-status-' + i);
        var frontendSpan = document.getElementById('frontend-status-' + i);

        if (cmsSpan) {
            checked++;
            checkingText = '⏳ Checking ' + checked + '/' + total + '...';
            if (!isHoveringCheck) btn.textContent = checkingText;
            cmsSpan.innerHTML = '...';
            var cmsResult = await checkLink(cmsLinks[i]);
            if (stopChecking) { cmsSpan.innerHTML = ''; break; }
            cmsSpan.innerHTML = statusBadge(cmsResult, [200], 'cms', i);
            if (cmsResult.status === 200) { passed++; } else { failed++; }
        }

        if (stopChecking) break;

        if (frontendSpan) {
            checked++;
            checkingText = '⏳ Checking ' + checked + '/' + total + '...';
            if (!isHoveringCheck) btn.textContent = checkingText;
            frontendSpan.innerHTML = '...';
            var frontendResult = await checkLink(frontendLinks[i]);
            if (stopChecking) { frontendSpan.innerHTML = ''; break; }
            frontendSpan.innerHTML = statusBadge(frontendResult, expectedStatuses[i], 'frontend', i);
            if (expectedStatuses[i].indexOf(frontendResult.status) !== -1) { passed++; } else { failed++; }
        }
    }

    isChecking = false;
    isHoveringCheck = false;
    btn.disabled = false;
    btn.classList.remove('ptl-checking');
    btn.onmouseenter = null;
    btn.onmouseleave = null;
    btn.textContent = '✓ Check All Links';

    if (stopChecking) {
        summary.style.background = '#fff3cd';
        summary.innerHTML = '<span style=\"color:#856404;\">⚠ Stopped: ' + passed + ' passed, ' + failed + ' failed, ' + (total - checked) + ' skipped</span>';
    } else if (failed === 0) {
        summary.style.background = '#d4edda';
        summary.innerHTML = '<span style=\"color:#155724;\">✓ All ' + passed + ' checks passed</span>';
    } else {
        summary.style.background = '#f8d7da';
        summary.innerHTML = '<span style=\"color:#721c24;\">✗ ' + failed + ' failed, ' + passed + ' passed</span>';
    }
}

// Auto-run on page load
document.addEventListener('DOMContentLoaded', checkAllLinks);
</script>");

        return Command::SUCCESS;
    }
}
