<?php

namespace PurpleSpider\PageTypeTester\Report;

use PurpleSpider\PageTypeTester\ActionLinkFinder;
use PurpleSpider\PageTypeTester\ContentProblemFinder;
use PurpleSpider\PageTypeTester\Model\AdminSection;
use PurpleSpider\PageTypeTester\Model\BlockTypeRow;
use PurpleSpider\PageTypeTester\Model\CheckResult;
use PurpleSpider\PageTypeTester\Model\EmailUsage;
use PurpleSpider\PageTypeTester\Model\PageTypeRow;
use PurpleSpider\PageTypeTester\UrlChecker;
use SilverStripe\Control\Director;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Formatter\OutputFormatter;

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
     * @var array<int, array{type: string, subject: string, url: string, status: string, problem: string}>
     */
    private array $failures = [];

    public function __construct(
        private readonly UrlChecker $checker,
        private readonly ActionLinkFinder $actionFinder,
        private readonly bool $skipActions = false,
        private readonly ContentProblemFinder $problemFinder = new ContentProblemFinder()
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
        if (!$this->checker->isVerifyingSsl()) {
            $output->writeForAnsi("<comment>TLS certificate verification is off for this run.</comment>\n");
        }

        $output->writeForAnsi(
            "\n<comment>Checking URLs" . ($this->skipActions ? ' (skipping actions)' : ' and actions') . "...</comment>\n\n"
        );

        foreach ($rows as $row) {
            $this->renderPageType($output, $row);
        }

        if ($blockRows) {
            $output->writeForAnsi("\n<comment>Checking block types...</comment>\n\n");
            foreach ($blockRows as $blockRow) {
                $this->renderBlockType($output, $blockRow);
            }
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
            $output->writeForAnsi("<comment>{$row->shortClass}</comment> (0): no pages to check");
            $this->renderEmailUsages($output, $row->emailUsages);
            $output->writeForAnsi("\n");
            return;
        }

        $output->writeForAnsi("<info>{$row->shortClass}</info> ({$row->liveCount} + {$row->getDraftOnlyCount()}):");
        $this->renderEmailUsages($output, $row->emailUsages);

        $frontendResult = $this->checker->check($row->frontendLink);
        $this->report(
            $output,
            'Frontend',
            $row->shortClass,
            $row->frontendLink,
            $frontendResult,
            $row->expectedStatus,
            null,
            $this->problemFinder->find($frontendResult, true)
        );

        $cmsResult = $this->checker->check($row->cmsLink);
        $this->report($output, 'CMS', $row->shortClass, $row->cmsLink, $cmsResult, [200]);

        // The edit form loads its blocks afterwards, so it answers 200 even when the
        // blocks editor inside it cannot load.
        foreach ($row->blockListUrls as $url) {
            $result = $this->checker->check($url);
            $this->report($output, 'CMS block list', $row->shortClass, $url, $result, [200]);
            $this->reportJsonError($output, $result);
        }

        if ($cmsResult->status === 200) {
            $this->renderCmsScreens($output, $row);
            $this->renderGridForms($output, $row);
        }

        $this->renderActions($output, $row, $frontendResult);

        $output->writeForAnsi("\n");
    }

    /**
     * The page's other CMS screens are only listed when they fail, as the browser
     * report does, so they add nothing to a page type that works.
     */
    private function renderCmsScreens(PolyOutput $output, PageTypeRow $row): void
    {
        $failed = [];
        foreach ($row->cmsScreenChecks as $check) {
            // History is two requests under one label, and one failure says enough.
            if (isset($failed[$check['label']])) {
                continue;
            }

            $result = $this->checker->check($check['url']);
            if ($result->loginRequired || $result->matches([200])) {
                continue;
            }

            $failed[$check['label']] = true;
            $this->report($output, "CMS {$check['label']}", $row->shortClass, $check['url'], $result, [200]);
            $this->reportJsonError($output, $result);
        }
    }

    /**
     * The forms in each GridField on the page's edit form. Only found for a logged-in
     * user, so usually none here.
     */
    private function renderGridForms(PolyOutput $output, PageTypeRow $row): void
    {
        foreach ($row->gridFields as $grid) {
            foreach ($grid['forms'] as $form) {
                $label = "CMS {$grid['title']} " . ($form['isNew'] ? 'add form' : "edit form ({$form['model']})");
                $this->report($output, $label, $row->shortClass, $form['url'], $this->checker->check($form['url']), [200]);
            }
        }
    }

    private function renderBlockType(PolyOutput $output, BlockTypeRow $row): void
    {
        if (!$row->hasElement()) {
            $output->writeForAnsi("<comment>{$row->shortClass}</comment> ({$row->totalCount}): no blocks to check");
            $this->renderEmailUsages($output, $row->emailUsages);
            $output->writeForAnsi("\n");
            return;
        }

        $output->writeForAnsi("<info>{$row->shortClass}</info> ({$row->liveCount} + {$row->getDraftOnlyCount()}):");
        $this->renderEmailUsages($output, $row->emailUsages);

        $checks = [
            'CMS Summary' => $row->editorCheckUrl,
            'CMS Edit Form' => $row->editFormUrl,
            'Frontend' => $row->frontendUrl,
        ];

        foreach ($checks as $label => $url) {
            // A block on something other than a page may have no edit form URL.
            if ($url === '') {
                continue;
            }

            $result = $this->checker->check($url);
            $problem = $label === 'Frontend' ? $this->problemFinder->find($result, false) : '';
            $this->report($output, $label, $row->shortClass, $url, $result, [200], "Block {$label}", $problem);
            $this->reportJsonError($output, $result);
        }

        $output->writeForAnsi("\n");
    }

    /**
     * Not a check, so not counted: a pointer to what to test by hand, since a page can
     * answer 200 while its emails never arrive.
     *
     * @param EmailUsage[] $usages
     */
    private function renderEmailUsages(PolyOutput $output, array $usages, string $indent = '  '): void
    {
        foreach ($usages as $usage) {
            $output->writeForAnsi(
                "\n{$indent}<fg=cyan>✉</> Sends email: " . OutputFormatter::escape($usage->getLocation())
                . ' <comment>(' . OutputFormatter::escape($usage->getDetail()) . ')</comment>'
            );
        }
    }

    /**
     * The block endpoints answer a failure with the exception message in JSON, which
     * says far more than the status code alone.
     */
    private function reportJsonError(PolyOutput $output, CheckResult $result): void
    {
        if ($result->status < 400 || $result->body === '') {
            return;
        }

        $data = json_decode($result->body, true);
        if (!is_array($data) || !isset($data['error']) || !is_string($data['error'])) {
            return;
        }

        $output->writeForAnsi("\n    <comment>" . OutputFormatter::escape($data['error']) . "</comment>");
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
            $this->report(
                $output,
                "Action /{$action}",
                $row->shortClass,
                $actionLinks[$action],
                $result,
                [200],
                null,
                $this->problemFinder->find($result, false)
            );
        }
    }

    private function renderAdminSection(PolyOutput $output, AdminSection $section): void
    {
        $output->writeForAnsi("<info>{$section->name}</info> ({$section->type}):");
        $this->renderEmailUsages($output, $section->emailUsages);

        // Records with no edit form to report them under, such as a model with no records.
        foreach ($section->unlinkedEmailUsages as $usages) {
            $this->renderEmailUsages($output, $usages);
        }

        $result = $this->checker->check($section->url);
        $this->report($output, 'Section', $section->name, $section->url, $result, [200], $section->type);

        foreach ($section->editLinks as $editLink) {
            $editResult = $this->checker->check($editLink->url);
            $this->report(
                $output,
                ($editLink->isNew ? 'Add ' : 'Edit ') . $editLink->modelName,
                $section->name . ' / ' . $editLink->modelName,
                $editLink->url,
                $editResult,
                [200],
                $editLink->isNew ? 'Add Form' : 'Edit Form'
            );
            $this->renderEmailUsages($output, $editLink->emailUsages, '    ');
        }

        $output->writeForAnsi("\n");
    }

    /**
     * @param int[] $expected
     * @param string $problem What is wrong with a response that has the expected status,
     *                        from ContentProblemFinder. Makes the check fail.
     */
    private function report(
        PolyOutput $output,
        string $label,
        string $subject,
        string $url,
        CheckResult $result,
        array $expected,
        ?string $failureType = null,
        string $problem = ''
    ): void {
        $this->checked++;

        if ($result->loginRequired) {
            $this->loginRequired++;
            $output->writeForAnsi("\n  <fg=yellow>⚠</> {$label}: {$url} <comment>[login required]</comment>");
            return;
        }

        if ($result->matches($expected) && $problem === '') {
            $this->passed++;
            $output->writeForAnsi("\n  <fg=green>✓</> {$label}: {$url} [{$result->getLabel()}]");
            return;
        }

        $this->failed++;
        $note = $result->isRedirect() && $result->redirectUrl
            ? ' <comment>-> ' . $result->redirectUrl . '</comment>'
            : '';
        if ($problem !== '') {
            $note = ' <comment>' . OutputFormatter::escape($problem) . '</comment>';
        }
        $output->writeForAnsi("\n  <fg=red>✗</> {$label}: {$url} <fg=red>[{$result->getLabel()}]</>{$note}");

        $this->failures[] = [
            'type' => $failureType ?? $label,
            'subject' => $subject,
            'url' => $url,
            'status' => $result->getLabel(),
            'problem' => $problem,
        ];
    }

    private function renderSummary(PolyOutput $output): void
    {
        if ($this->failures) {
            $output->writeForAnsi("\n<fg=red;options=bold>FAILED URLs ({$this->failed}):</>\n");
            foreach ($this->failures as $failure) {
                $output->writeForAnsi(
                    "  <fg=red>✗</> <options=bold>{$failure['subject']}</> {$failure['type']}: "
                    . "{$failure['url']} <fg=red>[{$failure['status']}]</>"
                    . ($failure['problem'] === '' ? '' : ' <comment>' . OutputFormatter::escape($failure['problem']) . '</comment>')
                    . "\n"
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
