<?php

namespace PurpleSpider\PageTypeTester\Report;

use PurpleSpider\PageTypeTester\ActionLinkFinder;
use PurpleSpider\PageTypeTester\Model\AdminSection;
use PurpleSpider\PageTypeTester\Model\CheckResult;
use PurpleSpider\PageTypeTester\Model\PageTypeRow;
use PurpleSpider\PageTypeTester\UrlChecker;
use SilverStripe\Control\Director;
use SilverStripe\PolyExecution\PolyOutput;

/**
 * Runs the checks and writes the terminal report.
 */
class CliReport
{
    private int $passed = 0;

    private int $failed = 0;

    private int $checked = 0;

    private int $loginRequired = 0;

    /**
     * @var array<int, array{type: string, subject: string, url: string, status: string}>
     */
    private array $failures = [];

    public function __construct(
        private readonly UrlChecker $checker,
        private readonly ActionLinkFinder $actionFinder,
        private readonly bool $skipActions = false
    ) {
    }

    /**
     * @param PageTypeRow[] $rows
     * @param AdminSection[] $adminSections
     */
    public function render(PolyOutput $output, array $rows, array $adminSections, bool $skipAdmin): void
    {
        if (!$this->checker->isVerifyingSsl()) {
            $output->writeForAnsi("<comment>TLS certificate verification is off for this run.</comment>\n");
        }

        $output->writeForAnsi(
            "\n<comment>Checking URLs" . ($this->skipActions ? ' (skipping actions)' : ' and actions') . "...</comment>\n\n"
        );

        foreach ($rows as $row) {
            $this->renderPageType($output, $row);
        }

        if ($skipAdmin) {
            $output->writeForAnsi("\n<comment>Skipping admin sections.</comment>\n");
        } else {
            $output->writeForAnsi("\n<comment>Checking admin sections...</comment>\n\n");
            foreach ($adminSections as $section) {
                $this->renderAdminSection($output, $section);
            }
        }

        $this->renderSummary($output);
    }

    private function renderPageType(PolyOutput $output, PageTypeRow $row): void
    {
        if (!$row->hasPage()) {
            $output->writeForAnsi("<comment>{$row->shortClass}</comment> (0): no pages to check\n");
            return;
        }

        $output->writeForAnsi("<info>{$row->shortClass}</info> ({$row->liveCount} + {$row->getDraftOnlyCount()}):");

        $frontendResult = $this->checker->check($row->frontendLink);
        $this->report($output, 'Frontend', $row->shortClass, $row->frontendLink, $frontendResult, $row->expectedStatus);

        $cmsResult = $this->checker->check($row->cmsLink);
        $this->report($output, 'CMS', $row->shortClass, $row->cmsLink, $cmsResult, [200]);

        $this->renderActions($output, $row, $frontendResult);

        $output->writeForAnsi("\n");
    }

    private function renderActions(PolyOutput $output, PageTypeRow $row, CheckResult $frontendResult): void
    {
        if (empty($row->allowedActions)) {
            return;
        }

        if ($this->skipActions) {
            $output->writeForAnsi("\n  <comment>Actions skipped: " . implode(', ', $row->allowedActions) . "</comment>");
            return;
        }

        if ($frontendResult->body === '') {
            $output->writeForAnsi("\n  <fg=yellow>?</> Actions: <comment>could not fetch page HTML to find action links</comment>");
            return;
        }

        $actionLinks = $this->actionFinder->find(
            $frontendResult->body,
            $row->allowedActions,
            $row->frontendLink,
            Director::absoluteBaseURL()
        );

        foreach ($row->allowedActions as $action) {
            if (!isset($actionLinks[$action])) {
                $output->writeForAnsi("\n  <fg=yellow>?</> Action /{$action}: <comment>not found on page</comment>");
                continue;
            }

            $result = $this->checker->check($actionLinks[$action]);
            $this->report($output, "Action /{$action}", $row->shortClass, $actionLinks[$action], $result, [200]);
        }
    }

    private function renderAdminSection(PolyOutput $output, AdminSection $section): void
    {
        $output->writeForAnsi("<info>{$section->name}</info> ({$section->type}):");

        $result = $this->checker->check($section->url);
        $this->report($output, 'Section', $section->name, $section->url, $result, [200], $section->type);

        foreach ($section->editLinks as $editLink) {
            $editResult = $this->checker->check($editLink->url);
            $this->report(
                $output,
                "Edit {$editLink->modelName}",
                $section->name . ' / ' . $editLink->modelName,
                $editLink->url,
                $editResult,
                [200],
                'Edit Form'
            );
        }

        $output->writeForAnsi("\n");
    }

    /**
     * @param int[] $expected
     */
    private function report(
        PolyOutput $output,
        string $label,
        string $subject,
        string $url,
        CheckResult $result,
        array $expected,
        ?string $failureType = null
    ): void {
        $this->checked++;

        if ($result->loginRequired) {
            $this->loginRequired++;
            $output->writeForAnsi("\n  <fg=yellow>⚠</> {$label}: {$url} <comment>[login required]</comment>");
            return;
        }

        if ($result->matches($expected)) {
            $this->passed++;
            $output->writeForAnsi("\n  <fg=green>✓</> {$label}: {$url} [{$result->getLabel()}]");
            return;
        }

        $this->failed++;
        $note = $result->isRedirect() && $result->redirectUrl
            ? ' <comment>-> ' . $result->redirectUrl . '</comment>'
            : '';
        $output->writeForAnsi("\n  <fg=red>✗</> {$label}: {$url} <fg=red>[{$result->getLabel()}]</>{$note}");

        $this->failures[] = [
            'type' => $failureType ?? $label,
            'subject' => $subject,
            'url' => $url,
            'status' => $result->getLabel(),
        ];
    }

    private function renderSummary(PolyOutput $output): void
    {
        if ($this->failures) {
            $output->writeForAnsi("\n<fg=red;options=bold>FAILED URLs ({$this->failed}):</>\n");
            foreach ($this->failures as $failure) {
                $output->writeForAnsi(
                    "  <fg=red>✗</> <options=bold>{$failure['subject']}</> {$failure['type']}: "
                    . "{$failure['url']} <fg=red>[{$failure['status']}]</>\n"
                );
            }
        }

        if ($this->loginRequired > 0) {
            $loginUrl = $this->checker->getLoginUrl();
            $output->writeForAnsi(
                "\n<fg=yellow;options=bold>NOT LOGGED IN - {$this->loginRequired} CMS URLs could not be checked</>\n"
            );
            $output->writeForAnsi("  These URLs redirect to {$loginUrl}, so there is nothing useful to check.\n");
            $output->writeForAnsi("  The CLI has no CMS session. Run the task in a browser while logged in.\n");
        }

        $output->writeForAnsi(
            "\n<options=bold>Results:</> <fg=green>{$this->passed} passed</>, <fg=red>{$this->failed} failed</>"
            . ($this->loginRequired > 0 ? ", <fg=yellow>{$this->loginRequired} need login</>" : '')
            . " ({$this->checked} checked)\n"
        );
    }

    public function hasFailures(): bool
    {
        return $this->failed > 0;
    }
}
