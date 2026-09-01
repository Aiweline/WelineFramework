<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Customer 登录表单由 Theme 布局内嵌 account-login 部件承载；本模块只保留表单与 shell。
 */
final class AccountLoginWidgetContractTest extends TestCase
{
    public function testLoginShellLeavesStageToThemeInlineWidget(): void
    {
        $shell = \dirname(__DIR__, 3) . '/view/templates/frontend/account/login-shell.phtml';
        $loginForm = \dirname(__DIR__, 3) . '/view/templates/frontend/account/login.phtml';
        $customerWidgetPhp = \dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Customer/widget.php';
        $themeWidget = \dirname(__DIR__, 4) . '/Theme/view/theme/frontend/widgets/form/account-login/default.phtml';
        $authLayout = \dirname(__DIR__, 4) . '/Theme/view/theme/frontend/layouts/account/auth.phtml';

        self::assertFileExists($shell);
        self::assertFileExists($loginForm);
        self::assertFileDoesNotExist($customerWidgetPhp);
        self::assertFileExists($themeWidget);
        self::assertFileExists($authLayout);

        $themeSource = (string)\file_get_contents($themeWidget);
        self::assertStringContainsString('@widget.code {account-login}', $themeSource);
        self::assertStringContainsString('type="media_image"', $themeSource);
        self::assertStringContainsString('account-login-widget__backdrop', $themeSource);
        self::assertStringContainsString('account-login-widget__rail', $themeSource);
        self::assertStringContainsString('account-login-widget__dock', $themeSource);
        self::assertDoesNotMatchRegularExpression(
            '/account-login-widget__dock[^>]*data-w-component\\s*=\\s*["\']account-login["\']/',
            $themeSource
        );
        $loginFormSource = (string)\file_get_contents($loginForm);
        self::assertMatchesRegularExpression(
            '/data-w-component\\s*=\\s*["\']account-login["\']/',
            $loginFormSource
        );
        self::assertStringContainsString('data-weline-load="api,account"', $loginFormSource);
        self::assertStringContainsString('promo_title', $themeSource);
        self::assertStringContainsString('promo_subtitle', $themeSource);
        self::assertStringContainsString('promo_eyebrow', $themeSource);
        self::assertStringContainsString('promo_trust', $themeSource);
        self::assertStringContainsString('accent_color', $themeSource);
        self::assertStringNotContainsString('account-login-widget__card', $themeSource);
        self::assertStringNotContainsString('account-login-widget__float', $themeSource);
        self::assertStringNotContainsString('account-login-widget--split', $themeSource);

        $widgetPhp = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Theme/extends/module/Weline_Widget/Weline_Theme/widget.php'
        );
        self::assertStringContainsString('account-login/default.phtml', $widgetPhp);
        self::assertStringContainsString("'promo_title'", $widgetPhp);
        self::assertStringContainsString("'promo_subtitle'", $widgetPhp);
        self::assertStringContainsString("'promo_eyebrow'", $widgetPhp);
        self::assertStringContainsString("'promo_trust'", $widgetPhp);
        self::assertStringNotContainsString('default_injections', $themeSource);
        self::assertStringContainsString('Weline_Customer::templates/frontend/account/login.phtml', $themeSource);

        $authSource = (string)\file_get_contents($authLayout);
        self::assertMatchesRegularExpression(
            '/<w:widget\\s+type="form"\\s+name="account-login"\\s*\\/>/',
            $authSource
        );
        self::assertStringNotContainsString('account-auth-layout__placeholder', $authSource);
        self::assertStringNotContainsString('@widget.default_injections', $authSource);

        $loginController = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Controller/Account/Login.php'
        );
        self::assertStringContainsString('login-shell.phtml', $loginController);
    }
}
