<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Customer 注册表单由 Theme 布局内嵌 account-register 部件承载。
 */
final class AccountRegisterWidgetContractTest extends TestCase
{
    public function testRegisterStageUsesThemeInlineWidget(): void
    {
        $registerForm = \dirname(__DIR__, 3) . '/view/templates/frontend/account/register.phtml';
        $themeWidget = \dirname(__DIR__, 4) . '/Theme/view/theme/frontend/widgets/form/account-register/default.phtml';
        $authLayout = \dirname(__DIR__, 4) . '/Theme/view/theme/frontend/layouts/account/auth.phtml';
        $widgetPhp = \dirname(__DIR__, 4) . '/Theme/extends/module/Weline_Widget/Weline_Theme/widget.php';

        self::assertFileExists($registerForm);
        self::assertFileExists($themeWidget);
        self::assertFileExists($authLayout);
        self::assertFileExists($widgetPhp);

        $themeSource = (string) \file_get_contents($themeWidget);
        self::assertStringContainsString('@widget.code {account-register}', $themeSource);
        self::assertStringContainsString('account-login-widget__rail', $themeSource);
        self::assertStringContainsString('account-login-widget__dock', $themeSource);
        self::assertStringContainsString('Weline_Customer::templates/frontend/account/register.phtml', $themeSource);
        self::assertStringContainsString('promo_title', $themeSource);

        $formSource = (string) \file_get_contents($registerForm);
        self::assertStringContainsString('w-auth-login--amazon', $formSource);
        self::assertStringContainsString('data-w-component="account-register"', $formSource);
        self::assertStringContainsString('data-customer-register-form', $formSource);
        self::assertStringNotContainsString('auth-form__icon', $formSource);

        $authSource = (string) \file_get_contents($authLayout);
        self::assertMatchesRegularExpression(
            '/<w:widget\\s+type="form"\\s+name="account-register"\\s*\\/>/',
            $authSource
        );

        $widgetSource = (string) \file_get_contents($widgetPhp);
        self::assertStringContainsString('account-register/default.phtml', $widgetSource);
    }
}
