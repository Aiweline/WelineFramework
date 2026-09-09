<?php

declare(strict_types=1);

namespace Weline\Captcha\Test\Unit\Provider;

use PHPUnit\Framework\TestCase;

/**
 * Contract: Google Enterprise inline bind script must close if(allowDegrade) before fail().
 * Trust badge markup must expose Google mark + privacy/terms attribution.
 */
final class GoogleRecaptchaEnterpriseRenderJsContractTest extends TestCase
{
    public function testRenderSourceClosesAllowDegradeBeforeFailEnd(): void
    {
        $path = \dirname(__DIR__, 3) . '/Provider/GoogleRecaptchaEnterprise.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString(
            'reason:String(error&&error.message||error||"recaptcha_unavailable")}}));}};',
            $source
        );
        self::assertStringNotContainsString(
            'reason:String(error&&error.message||error||"recaptcha_unavailable")}}));};\'',
            $source
        );
    }

    public function testRenderSourceIncludesGoogleTrustBadge(): void
    {
        $path = \dirname(__DIR__, 3) . '/Provider/GoogleRecaptchaEnterprise.php';
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('data-testid="google-recaptcha-trust-badge"', $source);
        self::assertStringContainsString('weline-captcha-google-trust__logo', $source);
        self::assertStringContainsString('fill="#4285F4"', $source);
        self::assertStringContainsString('https://policies.google.com/privacy', $source);
        self::assertStringContainsString('https://policies.google.com/terms', $source);
        self::assertStringContainsString('受 Google 保护', $source);

        $css = (string)file_get_contents(\dirname(__DIR__, 3) . '/view/statics/css/captcha-local.css');
        self::assertStringContainsString('.weline-captcha-google-trust', $css);
        self::assertStringContainsString('--weline-theme-border', $css);
    }
}
