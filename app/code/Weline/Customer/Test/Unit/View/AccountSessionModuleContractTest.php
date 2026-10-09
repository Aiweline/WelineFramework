<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 前台账户会话 JS 归属 Customer（account-session.js），禁止再放 Frontend。
 * 静默浏览：仅 localStorage 画顶栏；account.current 仅 w_auth/认证页/个人中心 account-index。
 */
final class AccountSessionModuleContractTest extends TestCase
{
    private function accountJs(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/account-session.js'
        );
    }

    public function testCustomerRegistersAccountModule(): void
    {
        $modules = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/frontend/weline.modules.js'
        );
        self::assertStringContainsString('account:', $modules);
        self::assertStringContainsString('Weline_Customer::js/account-session.js', $modules);
        self::assertStringContainsString('globalVar: "WelineAccountModule"', $modules);
        self::assertStringContainsString('load: "defer"', $modules);
    }

    public function testFrontendNoLongerOwnsAccountScriptOrRegistration(): void
    {
        self::assertFileDoesNotExist(
            dirname(__DIR__, 4) . '/Frontend/view/statics/js/weline-api-account.js'
        );
        $frontendModules = (string) file_get_contents(
            dirname(__DIR__, 4) . '/Frontend/view/statics/frontend/weline.modules.js'
        );
        self::assertStringNotContainsString('welineApiAccount', $frontendModules);
        self::assertStringNotContainsString('weline-api-account.js', $frontendModules);
        self::assertStringNotContainsString('account: "welineApiAccount"', $frontendModules);
    }

    public function testApplyHeaderSignedInTogglesAvatarImageAndFallback(): void
    {
        $js = $this->accountJs();
        self::assertStringContainsString('data-account-avatar-fallback', $js);
        self::assertStringContainsString('paintHeaderAccountAvatars', $js);
        self::assertStringContainsString('data-account-avatar-wrap', $js);
        self::assertStringContainsString('avatar.hidden = false', $js);
        self::assertStringContainsString('avatarFallback.hidden = true', $js);
        self::assertStringContainsString('avatar.removeAttribute(\'src\')', $js);
        self::assertStringContainsString('setAccountAvatarFallbackLabel', $js);
        self::assertStringContainsString('data-account-avatar-initial', $js);
        self::assertStringContainsString('data-has-initial', $js);
        // Header chrome: eager load + complete cache reveal (lazy+[hidden] never fetches).
        self::assertStringContainsString("setAttribute('loading', 'eager')", $js);
        self::assertStringContainsString('avatar.complete && avatar.naturalWidth > 0', $js);
        self::assertStringContainsString('data:image/', $js);
        self::assertStringNotContainsString("setAttribute('loading', 'lazy')", $js);
        self::assertStringContainsString('maybeStartSocialQuickPrompt', $js);
        self::assertStringContainsString('socialQuickPrompt', $js);
        self::assertStringContainsString('customerSocialQuick', $js);
        self::assertStringContainsString('suppressed_login_widget', $js);
    }

    public function testSilentBrowsePaintsFromCacheWithoutNetworkBootstrap(): void
    {
        $js = $this->accountJs();
        self::assertStringContainsString('frontendSessionUserKey', $js);
        self::assertStringContainsString('weline_frontend_session_user', $js);
        self::assertStringContainsString('paintOnly', $js);
        self::assertStringContainsString("'paint_only'", $js);
        self::assertStringContainsString('isThemeEditorPreview', $js);
        self::assertStringContainsString('handleAuthRefreshSignal()', $js);
        self::assertStringContainsString('ensureLogin', $js);
        self::assertStringContainsString('customer/login-panel', $js);
        // Bootstrap uses handleAuthRefreshSignal (paint-only unless editor/w_auth/auth page).
        self::assertStringContainsString('accountManager.handleAuthRefreshSignal()', $js);
        self::assertStringNotContainsString('bootstrapOnlineKeepalive', $js);
        self::assertStringNotContainsString("reason: 'already_aligned'", $js);
        // Silent keepalive disabled; near-expiry one-shot JS check only.
        self::assertStringContainsString('Silent keepalive disabled', $js);
        self::assertStringContainsString('isNearSessionExpiry', $js);
        self::assertStringContainsString('scheduleNearExpirySessionCheck', $js);
        self::assertStringContainsString('renewSessionNearExpiry', $js);
        self::assertStringContainsString('临近退出', $js);
        self::assertStringContainsString('fromAuthSignal: true', $js);
        self::assertStringContainsString('isLogoutAuthSignal', $js);
        self::assertStringContainsString('skipGuestNegativeCache', $js);
        self::assertStringContainsString('optimistic_keep_login_signal', $js);
        self::assertStringContainsString('isTrustedSignedInCache', $js);
        self::assertStringContainsString('isBrowserSignedInSnapshot', $js);
        self::assertStringContainsString('syncSessionAtPersonalCenter', $js);
        self::assertStringContainsString('个人中心由 account-index.js 同步会话', $js);
        // Seed SSR sessionUser on personal center before paintOnly (parallel-load race belt).
        self::assertStringContainsString('seedPersonalCenterSessionFromSsr', $js);
        self::assertStringContainsString('weline-account-index-config', $js);
        self::assertStringNotContainsString('isStorefrontAccountArea', $js);
        self::assertStringNotContainsString('onAccountArea', $js);
        self::assertStringNotContainsString('bindAccountChromeInteraction', $js);
        self::assertStringNotContainsString('reconcileSignedInChromeNavigation', $js);
        // Signed-in browser snapshot skips account.current; force only at allowed stages.
        self::assertStringContainsString('forceNetwork', $js);
        self::assertStringContainsString('opts.force === true', $js);
        self::assertStringNotContainsString('opts.force !== false', $js);
        self::assertStringContainsString('paintHeaderGuestChrome', $js);
        self::assertStringContainsString('resolveUserIdentity(rawUser)', $js);
        self::assertStringContainsString('guest current returns', $js);
        self::assertStringContainsString('success:true with isLogin:false', $js);
        self::assertStringContainsString('result.isLogin || result.logged_in', $js);
        self::assertStringContainsString('&& this.resolveUserIdentity(rawUser)', $js);
        self::assertStringNotContainsString('result.isLogin || result.logged_in || result.success', $js);
        self::assertStringNotContainsString('guest current uses success:false', $js);
    }

    public function testAuthPagesForceNetworkAndLeaveWhenSignedIn(): void
    {
        $js = $this->accountJs();
        self::assertStringContainsString('isStorefrontAuthPage', $js);
        self::assertStringContainsString('leaveAuthPageIfSignedIn', $js);
        self::assertStringContainsString('resolveSignedInLeaveUrl', $js);
        self::assertStringContainsString('customer/account/login', $js);
        self::assertStringContainsString('customer/account/register', $js);
        self::assertStringContainsString('onAuthPage', $js);
        self::assertStringContainsString('applyFrontendSessionSnapshot', $js);
        self::assertMatchesRegularExpression(
            '/leaveAuthPageIfSignedIn\(true\)/',
            $js
        );
        self::assertStringContainsString('location.replace', $js);
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
