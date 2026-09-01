<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

require_once __DIR__ . '/../bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * 沙箱一键授权配置中心深度链接必须带 guide_locate，且为绝对 http(s) URL。
 */
final class PayPalSandboxAuthorizeGuideUrlContractTest extends TestCase
{
    public function testSandboxAuthorizeGuideUrlContainsLocateParamsAndAbsoluteOrigin(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/PayPalOAuthService.php');
        self::assertStringContainsString("'guide_key' => 'adapter:paypal.sandbox.authorize'", $src);
        self::assertStringContainsString("'guide_locate' => 'adapter:paypal.sandbox.authorize'", $src);
        self::assertStringContainsString("'guide_key' => 'adapter:paypal.sandbox.authorize'", $src);
        self::assertStringContainsString("'search' => 'paypal'", $src);
        self::assertStringContainsString("'q' => 'paypal'", $src);
        self::assertStringContainsString('resolvePublicOrigin', $src);
        self::assertStringContainsString('isAbsoluteHttpUrl', $src);
        self::assertStringContainsString('backendGuideUrlHasPrefix', $src);
        self::assertStringContainsString('getAreaRoutePrefix', $src);
    }
}
