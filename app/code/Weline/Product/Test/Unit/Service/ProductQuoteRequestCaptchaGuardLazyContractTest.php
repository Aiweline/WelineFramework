<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Contract: Product quote captcha must not SSR Google HTML into FPC page shells.
 */
final class ProductQuoteRequestCaptchaGuardLazyContractTest extends TestCase
{
    public function testGuardRendersLazyHostInsteadOfChallengeHtml(): void
    {
        $path = \dirname(__DIR__, 3) . '/Service/ProductQuoteRequestCaptchaGuard.php';
        self::assertFileExists($path);
        $source = (string)\file_get_contents($path);
        self::assertStringContainsString('LazyCaptchaClientRuntime', $source);
        self::assertStringContainsString('LazyCaptchaClientRuntime::hostMarkup', $source);
        self::assertStringContainsString('self::FORM_ID', $source);
        self::assertStringContainsString('self::INTENT', $source);
        self::assertStringNotContainsString('->renderChallenge(', $source);
    }
}
