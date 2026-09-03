<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class AccountModuleFullContractTest extends TestCase
{
    public function testAccountModulePublishesTheFullLoaderContract(): void
    {
        $moduleFile = dirname(__DIR__, 4) . '/Frontend/view/statics/js/weline-api-account.js';

        self::assertFileExists($moduleFile);
        $content = (string)file_get_contents($moduleFile);

        self::assertStringContainsString("const AccountModule = {\n        __full: true,", $content);
        self::assertStringContainsString('window.WelineAccountModule = AccountModule;', $content);
        self::assertStringContainsString('handleAuthRefreshSignal: () => accountManager.handleAuthRefreshSignal()', $content);
        self::assertStringContainsString('startOnlineKeepalive: () => accountManager.startOnlineKeepalive()', $content);
        self::assertStringContainsString('bootstrapHeaderAuthRefresh', $content);
        self::assertStringContainsString('bootstrapOnlineKeepalive', $content);
        self::assertStringContainsString('navigator.onLine', $content);
        self::assertStringContainsString("data-auth-state') === 'guest'", $content);
        self::assertStringContainsString('applyHeaderSignedIn', $content);
        self::assertStringContainsString('resolveHeaderDisplayName', $content);
        self::assertStringContainsString('user.display_name', $content);
        self::assertStringContainsString('applyHeaderMenuAuth', $content);
        self::assertStringContainsString('data-account-menu-auth', $content);
        self::assertStringContainsString('syncHeaderAccountChrome', $content);
        self::assertStringContainsString('headerMenuAuthMismatch', $content);
        self::assertStringContainsString('weline:account:frontend:login', $content);
    }

    public function testFrontendWelineAccountProxyExposesAuthRefreshSignal(): void
    {
        $welineFile = dirname(__DIR__, 4) . '/Frontend/view/statics/js/weline.js';
        self::assertFileExists($welineFile);
        $content = (string)file_get_contents($welineFile);
        self::assertStringContainsString('handleAuthRefreshSignal: async () => {', $content);
        self::assertStringContainsString("moduleLoader.loadModule('account')", $content);
    }
}
