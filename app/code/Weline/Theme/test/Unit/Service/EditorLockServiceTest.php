<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class EditorLockServiceTest extends TestCase
{
    private function editorLockServiceSource(): string
    {
        return (string) \file_get_contents(
            \dirname(__DIR__, 3) . '/Service/EditorLockService.php',
        );
    }

    private function themeEditorJsSource(): string
    {
        return (string) \file_get_contents(
            \dirname(__DIR__, 3) . '/view/statics/js/theme-editor.js',
        );
    }

    public function testSameUserRefreshIsExplicitInService(): void
    {
        $source = $this->editorLockServiceSource();

        self::assertStringContainsString('function describeAcquireResult(', $source);
        self::assertStringContainsString('isSameEditorUser((int)$currentLock[\'user_id\'], $userId)', $source);
        self::assertStringContainsString('同一管理员（多 Tab / 刷新 / 心跳续期）直接续锁', $source);
    }

    public function testSingleFlightBusyNoLongerBlocksAcquire(): void
    {
        $source = $this->editorLockServiceSource();

        self::assertStringContainsString('协调锁超时时直接执行业务', $source);
        self::assertStringNotContainsString('编辑锁服务正忙，请稍后重试', $source);
    }

    public function testFrontendDistinguishesOtherUserFromUnavailable(): void
    {
        $source = $this->themeEditorJsSource();

        self::assertStringContainsString('currentUserId', $source);
        self::assertStringContainsString('function isEditorLockHeldByCurrentUser(', $source);
        self::assertStringContainsString('function isEditorLockBlockedByOther(', $source);
        self::assertStringContainsString('is_locked_by_other', $source);
        self::assertStringContainsString('dataset.editorUserId', $source);
    }
}
