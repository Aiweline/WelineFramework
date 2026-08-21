<?php

declare(strict_types=1);

namespace Weline\Admin\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class LoginTemplateCaptchaTest extends TestCase
{
    public function testLoginFormUsesNativeValidationAndConditionalVerificationCode(): void
    {
        $relativePath = 'view/templates/Login/index.phtml';
        $source = \file_get_contents(\dirname(__DIR__, 3) . '/' . $relativePath);

        self::assertIsString($source);
        self::assertSame(
            1,
            \preg_match('/<form[^\r\n]*\bdata-w-login-form\b[^\r\n]*>/', $source, $match),
            $relativePath
        );
        self::assertStringContainsString('need_backend_verification_code', $source);
        self::assertStringContainsString('autocomplete="username"', $source);
        self::assertStringContainsString('autocomplete="current-password"', $source);
        self::assertStringNotContainsString('data-bs-', $source);
        self::assertStringNotContainsString('<script>', $source);
    }
}
