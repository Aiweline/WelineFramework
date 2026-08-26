<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class LoginTemplateCaptchaTest extends TestCase
{
    public function testFrontendLoginFormExplicitlyRequiresCaptcha(): void
    {
        $source = \file_get_contents(
            \dirname(__DIR__, 3) . '/view/templates/frontend/account/login.phtml'
        );

        self::assertIsString($source);
        self::assertMatchesRegularExpression('/<w:form\b[^>]*\bid="loginForm"[^>]*>/', $source);
        self::assertMatchesRegularExpression('/<w:form\b[^>]*\bcaptcha="required"[^>]*>/', $source);
        self::assertMatchesRegularExpression('/<w:form\b[^>]*\bintent="customer\.login"[^>]*>/', $source);
        self::assertStringContainsString('data-weline-form-captcha-slot', $source);
        self::assertMatchesRegularExpression(
            '/data-weline-form-captcha-slot[\s\S]*<button type="submit" id="loginBtn"/',
            $source
        );
    }
}
