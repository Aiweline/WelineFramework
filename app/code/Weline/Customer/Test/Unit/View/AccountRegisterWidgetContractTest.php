<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 注册表单归属 Customer；Theme 舞台部件可选，不得作为店面必装注入。
 */
final class AccountRegisterWidgetContractTest extends TestCase
{
    public function testRegisterOwnedByCustomerLayout(): void
    {
        $registerForm = \dirname(__DIR__, 3) . '/view/templates/frontend/account/register.phtml';
        $themeWidget = \dirname(__DIR__, 4) . '/Theme/view/theme/frontend/widgets/form/account-register/default.phtml';
        $authLayout = \dirname(__DIR__, 3) . '/view/theme/frontend/layouts/account/auth.phtml';
        $registerLayout = \dirname(__DIR__, 3) . '/view/theme/frontend/layouts/account/register/default.phtml';
        $widgetPhp = \dirname(__DIR__, 4) . '/Theme/extends/module/Weline_Widget/Weline_Theme/widget.php';

        self::assertFileExists($registerForm);
        self::assertFileExists($themeWidget);
        self::assertFileExists($authLayout);
        self::assertFileExists($registerLayout);
        self::assertFileExists($widgetPhp);

        $formSource = (string) \file_get_contents($registerForm);
        self::assertStringContainsString('w-auth-login--amazon', $formSource);
        self::assertStringContainsString('data-w-component="account-register"', $formSource);
        self::assertStringContainsString('data-customer-register-form', $formSource);
        self::assertStringNotContainsString('auth-form__icon', $formSource);

        foreach ([$authLayout, $registerLayout] as $layoutPath) {
            $layoutSource = (string) \file_get_contents($layoutPath);
            self::assertStringNotContainsString('foreign-theme-account-register', $layoutSource);
            self::assertStringNotContainsString('<w:widget type="form" name="account-register"', $layoutSource);
            self::assertStringContainsString(
                "Weline_Customer::templates/frontend/account/register.phtml",
                $layoutSource
            );
            self::assertStringContainsString('{{meta.content}}', $layoutSource);
        }

        $themeSource = (string) \file_get_contents($themeWidget);
        self::assertStringContainsString('@widget.code {account-register}', $themeSource);
        self::assertStringContainsString(
            'Weline_Customer::templates/frontend/account/register.phtml',
            $themeSource
        );

        $widgetSource = (string) \file_get_contents($widgetPhp);
        self::assertStringContainsString('account-register/default.phtml', $widgetSource);
        self::assertStringNotContainsString('foreign-theme-account-register', $widgetSource);
    }
}
