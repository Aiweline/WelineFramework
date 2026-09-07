<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Account center connections group exposes social-login bindings section.
 */
final class AccountSocialBindingsSidebarContractTest extends TestCase
{
    public function testConnectionsGroupAndContentHooksExposeSocialLoginSection(): void
    {
        $moduleRoot = \dirname(__DIR__, 3);
        $nav = $moduleRoot . '/view/hooks/account.sidebar.group.connections.phtml';
        $content = $moduleRoot . '/view/hooks/account.sidebar.content.phtml';
        $bindings = $moduleRoot . '/view/templates/frontend/account/social-bindings.phtml';
        $choose = $moduleRoot . '/view/templates/frontend/account/social-login-choose.phtml';

        self::assertFileExists($nav);
        self::assertFileExists($content);
        self::assertFileExists($bindings);
        self::assertFileExists($choose);

        $navSource = (string) \file_get_contents($nav);
        self::assertStringContainsString('data-section="social-login"', $navSource);
        self::assertStringContainsString('data-account-nav-parent="connections"', $navSource);
        self::assertStringContainsString('#social-login', $navSource);

        $contentSource = (string) \file_get_contents($content);
        self::assertStringContainsString("forSections('social-login')", $contentSource);
        self::assertStringContainsString('data-account-section="social-login"', $contentSource);
        self::assertStringContainsString('social-bindings.phtml', $contentSource);

        $bindingsSource = (string) \file_get_contents($bindings);
        self::assertStringContainsString('customer/account/social-login/unbind', $bindingsSource);
        self::assertStringContainsString('data-customer-social-bindings', $bindingsSource);
        self::assertStringContainsString('data-customer-social-unbind-confirm', $bindingsSource);
        self::assertStringContainsString('getFormKey', $bindingsSource);
        self::assertStringContainsString('@frontend-url{\'customer/account/social-login/unbind\'}', $bindingsSource);
        self::assertStringContainsString('icon_svg', $bindingsSource);
        self::assertStringContainsString('account-social-bindings.css', $bindingsSource);

        $hookSource = (string) \file_get_contents($content);
        self::assertStringContainsString('getData(\'user\')', $hookSource);
        self::assertStringContainsString('Customer', $hookSource);

        $js = (string) \file_get_contents($moduleRoot . '/view/statics/js/account-index.js');
        self::assertStringContainsString('data-customer-social-unbind-confirm', $js);

        $linker = (string) \file_get_contents($moduleRoot . '/Service/SocialLogin/SocialLoginAccountLinker.php');
        self::assertStringContainsString('isKnown($provider)', $linker);
        self::assertStringContainsString("'icon_svg'", $linker);
        self::assertStringContainsString('当前账户未绑定该社媒提供方', $linker);

        $chooseSource = (string) \file_get_contents($choose);
        self::assertStringContainsString('customer/account/social-login/bind-existing', $chooseSource);
        self::assertStringContainsString('customer/account/social-login/create-new', $chooseSource);
        self::assertStringContainsString('绑定已有账户', $chooseSource);
        self::assertStringContainsString('新建账户', $chooseSource);
    }
}
