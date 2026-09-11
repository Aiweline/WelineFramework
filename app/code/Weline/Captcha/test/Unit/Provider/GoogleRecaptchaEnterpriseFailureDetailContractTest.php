<?php

declare(strict_types=1);

namespace Weline\Captcha\Test\Unit\Provider;

use PHPUnit\Framework\TestCase;

/** Soft-fail paths must throw diagnostic codes instead of silent false. */
final class GoogleRecaptchaEnterpriseFailureDetailContractTest extends TestCase
{
    public function testVerifyThrowsDiagnosticCodesInsteadOfSilentFalse(): void
    {
        $path = dirname(__DIR__, 3) . '/Provider/GoogleRecaptchaEnterprise.php';
        $source = (string)file_get_contents($path);
        self::assertStringContainsString("throw new \\RuntimeException('google_empty_token')", $source);
        self::assertStringContainsString('google_hostname_mismatch:', $source);
        self::assertStringContainsString('google_token_invalid:', $source);
        self::assertStringContainsString('siteKey is invalid', $source);
        self::assertStringContainsString('Google Enterprise Site Key 无效', $source);
    }
}
