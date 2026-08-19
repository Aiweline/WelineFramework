<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class AuthTemplateLocaleRedirectContractTest extends TestCase
{
    /**
     * @dataProvider authTemplateProvider
     */
    public function testAuthApiRedirectsPreserveTheCurrentStorefrontPrefix(string $relativePath): void
    {
        $template = (string)file_get_contents(dirname(__DIR__, 3) . '/view/templates/frontend/account/' . $relativePath);

        self::assertStringContainsString('resolveStorefrontRedirect', $template);
        self::assertStringContainsString('window.location.pathname', $template);
        self::assertStringContainsString('window.location.assign(resolveStorefrontRedirect(', $template);
        self::assertStringNotContainsString("window.location.href = data.redirect || '/customer/account'", $template);
        self::assertStringNotContainsString("window.location.href = (resp.redirect || '/customer/account')", $template);
        self::assertStringNotContainsString('window.location.href = data.redirect', $template);
    }

    public static function authTemplateProvider(): array
    {
        return [
            'register' => ['register.phtml'],
            'login' => ['login.phtml'],
            'forgot password' => ['forgot-password.phtml'],
        ];
    }

    public function testForgotPasswordTemplateConsumesControllerAssignedStorefrontUrls(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/account/forgot-password.phtml'
        );

        self::assertStringContainsString("\$this->getData('forgot_password_url')", $template);
        self::assertStringContainsString("\$this->getData('reset_password_url')", $template);
        self::assertStringContainsString("\$this->getData('login_url')", $template);
        self::assertStringContainsString('name="reset_url"', $template);
        self::assertStringContainsString('delete payload.redirect_url;', $template);
        self::assertStringNotContainsString('action="/customer/account/forgot-password', $template);
        self::assertStringNotContainsString('href="/customer/account/login"', $template);
    }
}
