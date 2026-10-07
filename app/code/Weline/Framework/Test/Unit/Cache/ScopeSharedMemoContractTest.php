<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;

final class ScopeSharedMemoContractTest extends TestCase
{
    public function testHelperExposesRememberForgetAndGlobalDimension(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Cache/Service/ScopeSharedMemo.php');
        self::assertStringContainsString('function remember', $src);
        self::assertStringContainsString('function forget', $src);
        self::assertStringContainsString('function rememberScoped', $src);
        self::assertStringContainsString('function forgetScoped', $src);
        self::assertStringContainsString('function logicalKey', $src);
        self::assertStringContainsString("'website' => false", $src);
        self::assertStringContainsString('StorefrontScopeHotCache', $src);
        self::assertStringContainsString('canonicalKey()', $src);

        $fn = (string)file_get_contents(dirname(__DIR__, 3) . '/Common/functions.php');
        self::assertStringContainsString('function w_scope_key', $fn);
    }

    public function testPaymentTaxShippingPoolsArePredefined(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Cache/CacheManager.php');
        self::assertStringContainsString("'payment'", $src);
        self::assertStringContainsString("'tax'", $src);
        self::assertStringContainsString("'shipping'", $src);
        self::assertStringContainsString("'seo'", $src);
    }
}
