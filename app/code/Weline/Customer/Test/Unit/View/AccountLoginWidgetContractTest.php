<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 登录表单归属 Customer：控制器 fetch login.phtml；布局走 content，不依赖 Theme 必装注入。
 */
final class AccountLoginWidgetContractTest extends TestCase
{
    public function testLoginOwnedByCustomerLayoutAndController(): void
    {
        $loginForm = \dirname(__DIR__, 3) . '/view/templates/frontend/account/login.phtml';
        $loginLayout = \dirname(__DIR__, 3) . '/view/theme/frontend/layouts/account/login/default.phtml';
        $authLayout = \dirname(__DIR__, 3) . '/view/theme/frontend/layouts/account/auth.phtml';
        $customerWidgetPhp = \dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Customer/widget.php';
        $themeWidget = \dirname(__DIR__, 4) . '/Theme/view/theme/frontend/widgets/form/account-login/default.phtml';

        self::assertFileExists($loginForm);
        self::assertFileExists($loginLayout);
        self::assertFileExists($authLayout);
        self::assertFileExists($customerWidgetPhp);
        self::assertFileExists($themeWidget);

        $loginFormSource = (string) \file_get_contents($loginForm);
        self::assertMatchesRegularExpression(
            '/data-w-component\\s*=\\s*["\']account-login["\']/',
            $loginFormSource
        );
        self::assertStringContainsString('data-weline-load="api,account"', $loginFormSource);
        self::assertStringContainsString('account-login-social-providers', $loginFormSource);

        $customerWidgetSource = (string) \file_get_contents($customerWidgetPhp);
        self::assertStringContainsString("'account-social-login'", $customerWidgetSource);
        self::assertStringContainsString('account-login-social-providers', $customerWidgetSource);

        foreach ([$loginLayout, $authLayout] as $layoutPath) {
            $layoutSource = (string) \file_get_contents($layoutPath);
            self::assertStringNotContainsString('foreign-theme-account-login', $layoutSource);
            self::assertStringNotContainsString('<w:widget type="form" name="account-login"', $layoutSource);
            self::assertStringContainsString(
                "Weline_Customer::templates/frontend/account/login.phtml",
                $layoutSource
            );
            self::assertStringContainsString('{{meta.content}}', $layoutSource);
        }

        $loginLayoutSource = (string) \file_get_contents($loginLayout);
        self::assertStringContainsString('account/login', $loginLayoutSource);
        self::assertStringContainsString('__force_login_stage', $loginLayoutSource);

        // Theme 舞台部件可保留作编辑器可选，但不得再声明 required default_injections。
        $themeSource = (string) \file_get_contents($themeWidget);
        self::assertStringContainsString('@widget.code {account-login}', $themeSource);
        self::assertStringContainsString(
            'Weline_Customer::templates/frontend/account/login.phtml',
            $themeSource
        );

        $widgetPhp = (string) \file_get_contents(
            \dirname(__DIR__, 4) . '/Theme/extends/module/Weline_Widget/Weline_Theme/widget.php'
        );
        self::assertStringContainsString('account-login/default.phtml', $widgetPhp);
        self::assertStringNotContainsString('foreign-theme-account-login', $widgetPhp);

        $loginController = (string) \file_get_contents(
            \dirname(__DIR__, 3) . '/Controller/Account/Login.php'
        );
        self::assertStringContainsString("layoutType = 'account/login'", $loginController);
        self::assertStringContainsString(
            'Weline_Customer::templates/frontend/account/login.phtml',
            $loginController
        );
        self::assertStringNotContainsString('login-shell.phtml', $loginController);
    }
}
