<?php

declare(strict_types=1);

namespace Weline\Captcha\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Contract: shared FPC-safe lazy host markup for Guards / Form inject.
 */
final class LazyCaptchaClientRuntimeHostMarkupContractTest extends TestCase
{
    public function testLazyRuntimeExposesHostMarkupHelper(): void
    {
        $path = \dirname(__DIR__, 3) . '/Service/LazyCaptchaClientRuntime.php';
        self::assertFileExists($path);
        $source = (string)\file_get_contents($path);
        self::assertStringContainsString('function hostMarkup(', $source);
        self::assertStringContainsString('data-weline-captcha-lazy="1"', $source);
        self::assertStringContainsString('data-challenge-route="weline_captcha/frontend/challenge"', $source);
        self::assertStringContainsString('onceScriptHtml()', $source);
    }

    public function testInjectCaptchaIntoFormUsesHostMarkup(): void
    {
        $path = \dirname(__DIR__, 3) . '/Observer/InjectCaptchaIntoForm.php';
        $source = (string)\file_get_contents($path);
        self::assertStringContainsString('LazyCaptchaClientRuntime::hostMarkup', $source);
        self::assertStringNotContainsString('weline-captcha-lazy-host', $source);
    }

    public function testGoogleRenderGuardsEmptySiteKey(): void
    {
        $path = \dirname(__DIR__, 3) . '/Provider/GoogleRecaptchaEnterprise.php';
        $source = (string)\file_get_contents($path);
        self::assertMatchesRegularExpression(
            '/\$siteKey\s*=\s*.*googleSiteKey\(\).*?\n.*?if\s*\(\s*\$siteKey\s*===\s*[\'"][\'"]\s*\)/s',
            $source
        );
        self::assertStringContainsString("enterprise.js?render=' . \\rawurlencode(\$siteKey)", $source);
    }
}
