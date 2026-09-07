<?php

declare(strict_types=1);

namespace Weline\Frontend\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Header account chrome must paint real avatar from account.current when present,
 * and must reuse localStorage session cache instead of forcing account.current each page.
 */
final class HeaderAccountAvatarSyncContractTest extends TestCase
{
    private function accountJs(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/weline-api-account.js'
        );
    }

    public function testApplyHeaderSignedInTogglesAvatarImageAndFallback(): void
    {
        $js = $this->accountJs();
        self::assertStringContainsString('data-account-avatar-fallback', $js);
        self::assertStringContainsString('avatar.hidden = false', $js);
        self::assertStringContainsString('avatarFallback.hidden = true', $js);
        self::assertStringContainsString('avatar.removeAttribute(\'src\')', $js);
        self::assertStringContainsString('user.avatar', $js);
        self::assertStringContainsString('maybeStartSocialQuickPrompt', $js);
        self::assertStringContainsString('socialQuickPrompt', $js);
        self::assertStringContainsString('customerSocialQuick', $js);
        self::assertStringContainsString('suppressed_login_widget', $js);
    }

    public function testFrontendSessionCacheSkipsPerPageForceCurrent(): void
    {
        $js = $this->accountJs();
        self::assertStringContainsString('frontendSessionUserKey', $js);
        self::assertStringContainsString('weline_frontend_session_user', $js);
        self::assertStringContainsString('renewAt', $js);
        self::assertStringContainsString('isFrontendSessionCacheFresh', $js);
        self::assertStringContainsString('writeFrontendSessionCache', $js);
        self::assertStringContainsString('fromCache: true', $js);
        self::assertStringContainsString('fromAuthSignal: true', $js);
        self::assertStringContainsString('isLogoutAuthSignal', $js);
        self::assertStringContainsString('force: false', $js);
        self::assertStringNotContainsString('syncHeaderAccountChrome({ force: true })', $js);
        self::assertStringContainsString('sessionTtlMs', $js);
        self::assertStringContainsString('guestRecheckMs', $js);
        self::assertStringContainsString('checkFrontendUserLogin({ force: true })', $js);
        self::assertStringContainsString('not signed in', $js);
        self::assertStringContainsString('writeFrontendSessionCache(status)', $js);
        self::assertStringContainsString('const hasSignal = this.hasAuthRefreshSignal()', $js);
        // Guest negative-cache must still start the visible quick-login bar.
        self::assertStringContainsString('skip account.current network only', $js);
        self::assertMatchesRegularExpression(
            '/Guest cache hit:[\s\S]{0,240}this\.maybeStartSocialQuickPrompt\(\)/',
            $js
        );
        self::assertMatchesRegularExpression(
            '/reason: \x27already_aligned\x27[\s\S]{0,80}|already_aligned[\s\S]{0,120}maybeStartSocialQuickPrompt/',
            $js
        );
        self::assertStringContainsString("reason: 'already_aligned'", $js);
        $alignedPos = strpos($js, "reason: 'already_aligned'");
        self::assertNotFalse($alignedPos);
        $alignedWindow = substr($js, max(0, $alignedPos - 160), 200);
        self::assertStringContainsString('maybeStartSocialQuickPrompt()', $alignedWindow);
    }

    public function testLogoutAuthSignalClearsSessionCacheBeforeNetwork(): void
    {
        $js = $this->accountJs();
        self::assertStringContainsString('isLogoutAuthSignal()', $js);
        self::assertStringContainsString('isLoginAuthSignal()', $js);
        self::assertStringContainsString('beginAuthBoundary', $js);
        self::assertStringContainsString('msUntilSessionRenew', $js);
        self::assertStringContainsString('resolveUserIdentity', $js);
        self::assertStringContainsString('prevId === nextId', $js);
        self::assertStringContainsString("get('w_auth') === '0'", $js);
        self::assertStringContainsString("get('w_auth') === '1'", $js);
        self::assertStringContainsString('bindFrontendSessionStorageSync', $js);
        self::assertStringContainsString('applyFrontendProfileUpdate', $js);
        self::assertStringContainsString('applyFrontendSessionSnapshot', $js);
        self::assertStringContainsString("addEventListener('storage'", $js);
    }
}