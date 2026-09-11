<?php

declare(strict_types=1);

namespace Weline\Dashboard\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Contract: live Dashboard reads published Scope releases only.
 * Default injections write draft; synchronizeLegacyPublishedLayout must publish
 * when draft exists and published_release_id is still 0 (not project empty payload).
 */
final class SynchronizeLegacyPublishedLayoutContractTest extends TestCase
{
    public function testSynchronizePublishesDraftWhenPublishedReleaseMissing(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/DashboardViewService.php'
        );

        self::assertStringContainsString(
            'Live page reads published only',
            $source,
            'synchronizeLegacyPublishedLayout must document publish-vs-draft contract'
        );
        self::assertMatchesRegularExpression(
            '/\$publishedReleaseId\s*=\s*\(int\)\(\$state\[[\'"]published_release_id[\'"]\]/',
            $source
        );
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*\$publishedReleaseId\s*>\s*0\s*\)/',
            $source,
            'Must only projectPublished when a release already exists'
        );
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*\$draftRevisionId\s*>\s*0\s*\|\|\s*\$revision\s*>\s*0\s*\)[\s\S]*?scopedWorkspace->publish\(/',
            $source,
            'Draft without published release must be promoted via publish()'
        );
        self::assertStringNotContainsString(
            "|| (int)(\$state['published_release_id'] ?? 0) > 0\n                || (int)(\$state['draft_revision_id'] ?? 0) > 0",
            $source,
            'Must not treat draft presence as enough to skip publish'
        );
    }

    public function testDashboardControllerInstallsLayoutIdentityIntoRequestContext(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Backend/Dashboard.php'
        );

        self::assertStringContainsString('RequestContext::set(LayoutIdentity::REQUEST_CONTEXT_KEY', $source);
        self::assertStringContainsString('layoutIdentity($view)', $source);
    }
}
