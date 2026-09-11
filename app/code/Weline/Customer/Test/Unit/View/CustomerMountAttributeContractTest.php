<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Contract: Account provides login-panel (+ social-quick); orchestration is Weline.mount.
 */
final class CustomerMountAttributeContractTest extends TestCase
{
    public function testAccountProvidesLoginPanelAndSocialQuickSurfaces(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertFileDoesNotExist($root . '/Taglib/CustomerMount.php');
        self::assertFileExists($root . '/doc/挂载面.md');
        self::assertFileExists($root . '/view/statics/js/account-login-panel.js');

        $doc = (string) file_get_contents($root . '/doc/挂载面.md');
        self::assertStringContainsString('customer/login-panel', $doc);
        self::assertStringContainsString('customer/social-quick', $doc);
        self::assertStringContainsString('Weline.mount', $doc);

        $session = (string) file_get_contents($root . '/view/statics/js/account-session.js');
        self::assertStringContainsString("provide('customer/login-panel'", $session);
        self::assertStringContainsString("provide('customer/social-quick'", $session);
        self::assertStringContainsString('[data-weline-mount^="customer/"]', $session);

        $panel = (string) file_get_contents($root . '/view/statics/js/account-login-panel.js');
        self::assertStringContainsString("MOUNT_LOGIN_PANEL = 'customer/login-panel'", $panel);
        self::assertStringContainsString('data-w-login-form', $panel);
        self::assertStringContainsString('customer/social-quick', $panel);
        self::assertStringContainsString('weline-login-panel', $panel);

        $modules = (string) file_get_contents($root . '/view/statics/frontend/weline.modules.js');
        self::assertStringContainsString('customerLoginPanel', $modules);
        self::assertStringContainsString('account-login-panel.js', $modules);
    }
}
