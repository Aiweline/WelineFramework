<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http\Security;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Security\SecurityHeaderDefaults;

/**
 * Env Global 安全响应头必须有非空安全默认（后台可用 CSP）。
 */
final class SecurityHeaderDefaultsContractTest extends TestCase
{
    public function testSafeCspDefaultIsNonEmptyAndAdminCompatible(): void
    {
        $csp = SecurityHeaderDefaults::CSP;
        self::assertNotSame('', \trim($csp));
        self::assertStringContainsString("default-src 'self'", $csp);
        self::assertStringContainsString("img-src 'self' blob: data: https:", $csp);
        self::assertStringContainsString("script-src 'self' 'unsafe-inline'", $csp);
        self::assertStringContainsString('https://www.google.com', $csp);
        self::assertStringContainsString('https://www.gstatic.com', $csp);
        self::assertStringNotContainsString('https://turing.captcha.qcloud.com', $csp);
        self::assertStringNotContainsString('https://www.recaptcha.net', $csp);
        self::assertStringContainsString('https://www.facebook.com', $csp);
        self::assertStringContainsString('https://www.youtube.com', $csp);
        self::assertStringContainsString('https://platform.twitter.com', $csp);
        self::assertStringContainsString('https://www.instagram.com', $csp);
        self::assertStringContainsString('https://www.tiktok.com', $csp);
        self::assertStringContainsString('https://www.linkedin.com', $csp);
        self::assertStringContainsString('https://open.weixin.qq.com', $csp);
        self::assertStringContainsString('https://js.stripe.com', $csp);
        self::assertStringContainsString('https://cdn.jsdelivr.net', $csp);
        self::assertStringContainsString("img-src 'self' blob: data: https:", $csp);
        self::assertStringContainsString('font-src', $csp);
        self::assertStringContainsString('media-src', $csp);
        self::assertStringContainsString('frame-src', $csp);
        self::assertStringContainsString('connect-src', $csp);
        self::assertStringContainsString("style-src 'self' 'unsafe-inline'", $csp);
        self::assertStringContainsString("worker-src 'self' blob:", $csp);
    }

    public function testReportOnlyDefaultMatchesEnforcingBaseline(): void
    {
        self::assertNotSame('', \trim(SecurityHeaderDefaults::CSP_REPORT_ONLY));
        self::assertSame(SecurityHeaderDefaults::CSP, SecurityHeaderDefaults::CSP_REPORT_ONLY);
    }

    public function testCorsDefaultDeniesCrossOriginEcho(): void
    {
        self::assertSame('', SecurityHeaderDefaults::CORS_ORIGINS);
    }

    public function testEnvSampleAndTemplateCarrySameSafeDefaults(): void
    {
        // .../Framework/Test/Unit/Http/Security
        $frameworkRoot = \dirname(__DIR__, 4);
        $repoRoot = \dirname($frameworkRoot, 4);

        $envClass = (string)\file_get_contents($frameworkRoot . '/App/Env.php');
        $sample = (string)\file_get_contents($repoRoot . '/app/etc/env.sample.php');
        $phtml = (string)\file_get_contents(
            $frameworkRoot . '/Extends/module/Weline_SystemConfig/Config/backend/security-headers.phtml'
        );
        $service = (string)\file_get_contents(
            $frameworkRoot . '/Http/Security/SecurityHeaderPolicyService.php'
        );

        foreach ([$envClass, $sample, $phtml] as $src) {
            self::assertNotSame('', $src);
            self::assertStringContainsString(SecurityHeaderDefaults::CSP, $src);
            self::assertStringContainsString(SecurityHeaderDefaults::CSP_REPORT_ONLY, $src);
        }
        self::assertStringContainsString('SecurityHeaderDefaults::CSP', $service);
        self::assertStringContainsString('SecurityHeaderDefaults::CSP_REPORT_ONLY', $service);
        self::assertStringContainsString("\$csp !== '' ? \$csp : SecurityHeaderDefaults::CSP", $service);
    }
}
