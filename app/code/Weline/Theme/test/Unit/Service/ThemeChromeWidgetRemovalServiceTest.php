<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ThemeChromeWidgetRemovalServiceTest extends TestCase
{
    public static function scenarios(): iterable
    {
        yield 'current draft' => ['draft', 'removed', 10];
        yield 'published current' => ['published', 'removed', 11];
        yield 'inherited published' => ['inherited', 'removed', 11];
        yield 'local header with inherited footer' => ['partial', 'removed', 10];
        yield 'nearer inherited slot owns absence' => ['nearer-slot', null, 10];
        yield 'explicitly empty current owns chrome' => ['empty', null, 10];
        yield 'missing chrome does not block content owner lookup' => ['missing-binding', null, 10];
        yield 'absent in current does not revive published' => ['absent', null, 10];
        yield 'already removed current draft' => ['removed', 'already_absent', 10];
    }

    #[DataProvider('scenarios')]
    public function testRemovalUsesActualChromeDraftAndPreservesPublishedHistory(string $scenario, ?string $status, int $versionId): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/theme-chrome-widget-removal.php') . ' ' . escapeshellarg($scenario) . ' 2>&1', $lines, $exit);
        self::assertSame(0, $exit, implode("\n", $lines));
        $result = json_decode(implode("\n", $lines), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($status, $result['result']['status'] ?? null);
        self::assertSame($result['history_before'], $result['history_after']);
        self::assertSame($versionId, $result['current_id']);
        if ($scenario === 'partial') {
            self::assertSame('local-header', $result['local_header']);
        }
        if ($status !== null) {
            self::assertFalse($result['active']);
            if ($status === 'already_absent') {
                self::assertTrue(
                    str_starts_with((string)$result['source'], 'user_deleted'),
                    'prior uninstall marker must remain target-version uninstall',
                );
            } else {
                self::assertSame('user_deleted@' . $versionId, $result['source']);
            }
            self::assertFalse($result['rebaked_active'], 'Required defaults must not reactivate a user removal.');
            self::assertSame('shop.store.channel', $result['owner_scope']);
            self::assertSame('kept', $result['other_config']);
        }
    }
    public function testControllerDoesNotReportSuccessForAnUnknownUid(): void
    {
        $result = $this->controllerScenario('missing');
        self::assertFalse($result['removed']);
        self::assertSame([], $result['layout_writes']);
    }

    public function testCanonicalChromeOwnerWinsOverLegacyLayoutDuplicate(): void
    {
        $result = $this->controllerScenario('chrome');
        self::assertTrue($result['removed']);
        self::assertSame([], $result['layout_writes']);
        self::assertSame(0, $result['layout_reads']);
    }

    public function testContentNodeStillUsesScopedWriter(): void
    {
        $result = $this->controllerScenario('layout');
        self::assertTrue($result['removed']);
        self::assertSame([['product', str_repeat('a', 32)]], $result['layout_writes']);
    }

    private function controllerScenario(string $scenario): array
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/theme-editor-removal-owner.php') . ' ' . escapeshellarg($scenario) . ' 2>&1', $lines, $exit);
        self::assertSame(0, $exit, implode("\n", $lines));
        return json_decode(implode("\n", $lines), true, flags: JSON_THROW_ON_ERROR);
    }
}
