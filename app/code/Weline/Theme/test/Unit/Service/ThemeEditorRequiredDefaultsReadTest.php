<?php
declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class ThemeEditorRequiredDefaultsReadTest extends TestCase
{
    public function testLegacyAutomaticReconcileOnlyReturnsTheExistingWorkspace(): void
    {
        $result = $this->scenario('reconcile');
        self::assertTrue($result['response']['success']);
        self::assertSame(0, $result['writes']);
        self::assertSame(8, $result['revision']);
        self::assertSame($result['before'], $result['payload']);
        self::assertSame(0, $result['response']['applied']);
        self::assertSame(8, $result['response']['scoped_workspace']['content_revision']);
    }

    public function testExplicitApplyStillUsesTheRequestedInstallationAction(): void
    {
        $result = $this->scenario('apply');
        self::assertTrue($result['response']['success']);
        self::assertSame(1, $result['writes']);
        self::assertSame(9, $result['revision']);
        self::assertSame(1, $result['response']['applied']);
    }

    private function scenario(string $action): array
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/theme-editor-required-defaults-read.php')
            . ' ' . escapeshellarg($action) . ' 2>&1', $lines, $exit);
        self::assertSame(0, $exit, implode("\n", $lines));
        return json_decode(implode("\n", $lines), true, flags: JSON_THROW_ON_ERROR);
    }
}
