<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 账户页/侧栏头像：无照片或照片未加载时必须露出首字母，禁止空 src 圆圈盖住 fallback。
 */
final class AccountAvatarFallbackContractTest extends TestCase
{
    public function testAccountIndexSsrKeepsInitialFallbackVisible(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/account/index.phtml'
        );
        self::assertStringContainsString('id="avatarPreview"', $source);
        self::assertStringContainsString('id="avatarFallback"', $source);
        self::assertStringContainsString('htmlspecialchars($avatarInitial)', $source);
        self::assertStringNotContainsString('<var>avatarInitial</var>', $source);
        self::assertDoesNotMatchRegularExpression(
            '/id="avatarPreview"[^>]*class="account-index__avatar-img"<\?= \$avatar \? \'\' : \' hidden\' \?>/',
            $source
        );
        self::assertStringContainsString('class="account-index__avatar-img" hidden', $source);
        self::assertStringNotContainsString("id=\"avatarFallback\"<?= \$avatar ? ' hidden' : '' ?>", $source);
    }

    public function testSidebarSsrKeepsInitialFallbackVisible(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/account/sidebar/side.phtml'
        );
        self::assertStringContainsString('id="sidebarAvatarPreview"', $source);
        self::assertStringContainsString('id="sidebarAvatarFallback"', $source);
        self::assertStringContainsString('htmlspecialchars($avatarInitial)', $source);
        self::assertStringContainsString('class="account-sidebar__avatar-img" hidden', $source);
        self::assertStringNotContainsString("id=\"sidebarAvatarFallback\"<?= \$avatar ? ' hidden' : '' ?>", $source);
    }

    public function testAccountIndexJsDoesNotHideFallbackBeforePhotoLoads(): void
    {
        $js = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/account-index.js'
        );
        self::assertStringContainsString('showAvatarFallback', $js);
        self::assertStringContainsString('target.image.onload', $js);
        self::assertStringContainsString('target.fallback.hidden = false', $js);
        self::assertStringContainsString('2500', $js);
        self::assertStringNotContainsString("target.fallback.hidden = true;\n                target.image.src = avatarUrl", $js);
        // SSR avatar seeds trusted browser session so other signed-in pages paint header locally.
        self::assertStringContainsString('seedSessionAvatarFromPage', $js);
        self::assertStringContainsString('applyFrontendProfileUpdate', $js);
        self::assertStringContainsString('isBrowserSignedInSnapshot', $js);
        self::assertStringContainsString('syncPersonalCenterBrowserSession', $js);
        self::assertStringContainsString('syncSessionAtPersonalCenter', $js);
    }

    public function testAccountIndexConfigEmbedsSessionUserForBrowserSync(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/account/index.phtml'
        );
        self::assertStringContainsString("'sessionUser'", $source);
        self::assertStringContainsString('getDisplayName', $source);
    }
}
