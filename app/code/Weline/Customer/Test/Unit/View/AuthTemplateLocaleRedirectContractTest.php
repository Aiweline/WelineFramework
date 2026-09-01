<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class AuthTemplateLocaleRedirectContractTest extends TestCase
{
    /**
     * @dataProvider authTemplateProvider
     */
    public function testAuthTemplatesUseUrlTagsInsteadOfHardcodedCustomerPaths(string $relativePath): void
    {
        $template = (string)file_get_contents(dirname(__DIR__, 3) . '/view/templates/frontend/account/' . $relativePath);

        self::assertStringContainsString("@url{'customer/account/", $template);
        self::assertStringNotContainsString("?? '/customer/account/", $template);
        self::assertStringNotContainsString('href="/customer/account/', $template);
        self::assertStringNotContainsString('action="/customer/account/', $template);
        self::assertStringNotContainsString("action=\"@var(\$", $template);
    }

    public static function authTemplateProvider(): array
    {
        return [
            'register' => ['register.phtml'],
            'login' => ['login.phtml'],
            'forgot password' => ['forgot-password.phtml'],
        ];
    }

    public function testForgotPasswordTemplateUsesUrlTagsForAllAddresses(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/account/forgot-password.phtml'
        );

        self::assertStringContainsString("@url{'customer/account/login'}", $template);
        self::assertStringContainsString("@url{'customer/account/forgot-password'}", $template);
        self::assertStringContainsString("@url{'customer/account/forgot-password/reset-password'}", $template);
        self::assertStringContainsString('name="reset_url"', $template);
        self::assertStringContainsString('data-login-url="@url{\'customer/account/login\'}"', $template);
        self::assertStringNotContainsString('action="/customer/account/forgot-password', $template);
        self::assertStringNotContainsString('href="/customer/account/login"', $template);
    }

    public function testLoginTemplateUsesUrlTagsForRegisterForgotAndSubmit(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/account/login.phtml'
        );

        self::assertStringContainsString("action=\"@url{'customer/account/login'}\"", $template);
        self::assertStringContainsString("@url{'customer/account/register'}", $template);
        self::assertStringContainsString("@url{'customer/account/forgot-password'}", $template);
        self::assertStringContainsString("getFormKey('customer/account/login')", $template);
    }
}
