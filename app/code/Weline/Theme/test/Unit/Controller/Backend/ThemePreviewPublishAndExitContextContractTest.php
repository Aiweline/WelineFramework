<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;

/**
 * publish-and-exit must not feed PreviewContext shell target_type into layout identity asserts.
 * Dirty drafts must go through runStandardLayoutPublish (create_version gate); token cleared only on success.
 */
final class ThemePreviewPublishAndExitContextContractTest extends TestCase
{
    public function testPublishAndExitDoesNotResolveIdentityFromFullPreviewContext(): void
    {
        $path = dirname(__DIR__, 4) . '/Controller/Backend/ThemeEditor.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        $start = strpos($source, 'function postPublishAndExit()');
        self::assertNotFalse($start);
        $end = strpos($source, "\n    public function ", $start + 1);
        self::assertNotFalse($end);
        $fn = substr($source, $start, $end - $start);

        self::assertStringNotContainsString(
            'resolveVersionLayoutIdentity($previewContext)',
            $fn,
            'Full previewContext carries shell target_type=layout and false-fails assertRawLayoutContextMatches'
        );
        self::assertStringContainsString("['editor_context' => \$typedClaims]", $fn);
        self::assertStringContainsString('PreviewContextService shell target_type', $fn);
        self::assertStringContainsString('runStandardLayoutPublish($typedContext', $fn);
        self::assertStringContainsString('Do not clear preview token on version-gate', $fn);
        self::assertStringNotContainsString('createAndPublishScopedLayoutVersion(', $fn);
    }

    public function testAssertRawLayoutContextSkipsPreviewShellTargetTypes(): void
    {
        $path = dirname(__DIR__, 4) . '/Controller/Backend/ThemeEditor.php';
        $source = (string)file_get_contents($path);

        $start = strpos($source, 'function assertRawLayoutContextMatches(');
        self::assertNotFalse($start);
        $end = strpos($source, "\n    private function ", $start + 1);
        if ($end === false) {
            $end = strpos($source, "\n    public function ", $start + 1);
        }
        self::assertNotFalse($end);
        $fn = substr($source, $start, $end - $start);

        self::assertStringContainsString('TARGET_TYPE_LAYOUT', $fn);
        self::assertStringContainsString('TARGET_TYPE_PATH', $fn);
        self::assertStringContainsString('TARGET_TYPE_PAGE', $fn);
        self::assertStringContainsString('continue;', $fn);
    }
}
