<?php

declare(strict_types=1);

namespace Weline\Admin\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class LoginTemplateCaptchaTest extends TestCase
{
    public function testFastLoginFormExplicitlyRequiresCaptcha(): void
    {
        $relativePath = 'view/templates/Login/fast.phtml';
        $source = \file_get_contents(\dirname(__DIR__, 3) . '/' . $relativePath);

        self::assertIsString($source);
        self::assertSame(
            1,
            \preg_match('/<w:form[^\r\n]*\bdata-login-form\b[^\r\n]*>/', $source, $match),
            $relativePath
        );
        self::assertStringContainsString('captcha="required"', $match[0], $relativePath);
        self::assertStringContainsString('intent="admin.login"', $match[0], $relativePath);
    }

    public function testFullLoginFormKeepsConditionalLegacyCaptchaWithoutUnifiedChallenge(): void
    {
        $relativePath = 'view/templates/Login/index.phtml';
        $source = \file_get_contents(\dirname(__DIR__, 3) . '/' . $relativePath);

        self::assertIsString($source);
        self::assertSame(
            1,
            \preg_match('/<form[^\r\n]*\bdata-login-form\b[^\r\n]*>/', $source, $match),
            $relativePath
        );
        self::assertStringNotContainsString('captcha="required"', $match[0], $relativePath);
        self::assertStringContainsString('need_backend_verification_code', $source);
    }
}
