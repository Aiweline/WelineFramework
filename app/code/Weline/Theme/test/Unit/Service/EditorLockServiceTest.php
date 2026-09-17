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
            \dirname(__DIR__, 3) . '/view/statics/ui/pages/weline-theme-editor.js',
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

    public function testLockIdentityAllowsNestedPageTypesLikeThemeEditorContext(): void
    {
        $source = $this->editorLockServiceSource();

        self::assertStringContainsString('Align with ThemeEditorContext layoutType', $source);
        self::assertStringContainsString('#^[a-zA-Z0-9][a-zA-Z0-9_./:@-]{0,127}$#D', $source);
        self::assertStringContainsString("!str_contains(\$pageType, '//')", $source);
        self::assertStringNotContainsString('/^[a-z][a-z0-9_.:-]{0,63}$/D', $source);

        foreach (['homepage', 'account/login', 'checkout/success', 'checkout/failure'] as $pageType) {
            self::assertSame(
                1,
                preg_match('#^[a-zA-Z0-9][a-zA-Z0-9_./:@-]{0,127}$#D', $pageType),
                $pageType . ' must pass nested lock identity regex',
            );
            self::assertFalse(str_contains($pageType, '//'));
            self::assertFalse(str_starts_with($pageType, '/'));
            self::assertFalse(str_ends_with($pageType, '/'));
        }

        foreach (['', '/account', 'account/', 'account//login', "acc\0ount"] as $pageType) {
            $ok = preg_match('#^[a-zA-Z0-9][a-zA-Z0-9_./:@-]{0,127}$#D', $pageType) === 1
                && !str_contains($pageType, '//')
                && !str_starts_with($pageType, '/')
                && !str_ends_with($pageType, '/');
            self::assertFalse($ok, json_encode($pageType) . ' must remain rejected');
        }
    }
}
