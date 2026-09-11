<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Contract: Delivery address captcha Guard stays FPC-safe (lazy host only).
 */
final class DeliveryAddressCaptchaGuardLazyContractTest extends TestCase
{
    public function testGuardRendersLazyHostInsteadOfChallengeHtml(): void
    {
        $path = \dirname(__DIR__, 3) . '/Service/DeliveryAddressCaptchaGuard.php';
        self::assertFileExists($path);
        $source = (string)\file_get_contents($path);
        self::assertStringContainsString('LazyCaptchaClientRuntime', $source);
        self::assertStringContainsString('LazyCaptchaClientRuntime::hostMarkup', $source);
        self::assertStringNotContainsString('->renderChallenge(', $source);
    }
}
