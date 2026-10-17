<?php

declare(strict_types=1);

namespace Weline\Dashboard\Test\Unit\Service;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllDashboardServiceContractsPass().
 */
final class DashboardServiceContractsTest extends TestCase
{
    public function testAllDashboardServiceContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'SynchronizeLegacyPublishedLayout::testSynchronizePublishesDraftWhenPublishedReleaseMissing' => function () {
            $this->assert_SynchronizeLegacyPublishedLayout_testSynchronizePublishesDraftWhenPublishedReleaseMissing();
        },
            'SynchronizeLegacyPublishedLayout::testDashboardControllerInstallsLayoutIdentityIntoRequestContext' => function () {
            $this->assert_SynchronizeLegacyPublishedLayout_testDashboardControllerInstallsLayoutIdentityIntoRequestContext();
        },
        ];
    }

private function assert_SynchronizeLegacyPublishedLayout_testSynchronizePublishesDraftWhenPublishedReleaseMissing(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Dashboard' . '/Service/DashboardViewService.php');
    self::assertStringContainsString('Live page reads published only', $source, 'synchronizeLegacyPublishedLayout must document publish-vs-draft contract');
    self::assertMatchesRegularExpression('/\$publishedReleaseId\s*=\s*\(int\)\(\$state\[[\'"]published_release_id[\'"]\]/', $source);
    self::assertMatchesRegularExpression('/if\s*\(\s*\$publishedReleaseId\s*>\s*0\s*\)/', $source, 'Must only projectPublished when a release already exists');
    self::assertMatchesRegularExpression('/if\s*\(\s*\$draftRevisionId\s*>\s*0\s*\|\|\s*\$revision\s*>\s*0\s*\)[\s\S]*?scopedWorkspace->publish\(/', $source, 'Draft without published release must be promoted via publish()');
    self::assertStringNotContainsString("|| (int)(\$state['published_release_id'] ?? 0) > 0\n                || (int)(\$state['draft_revision_id'] ?? 0) > 0", $source, 'Must not treat draft presence as enough to skip publish');
}

private function assert_SynchronizeLegacyPublishedLayout_testDashboardControllerInstallsLayoutIdentityIntoRequestContext(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Dashboard' . '/Controller/Backend/Dashboard.php');
    self::assertStringContainsString('RequestContext::set(LayoutIdentity::REQUEST_CONTEXT_KEY', $source);
    self::assertStringContainsString('layoutIdentity($view)', $source);
}
}
