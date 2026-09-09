<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Doc;

use PHPUnit\Framework\TestCase;

final class Product541StorefrontEnrichmentLogContractTest extends TestCase
{
    public function testDevelopmentLogRecordsApiDemoEnrichment(): void
    {
        $log = (string)file_get_contents(dirname(__DIR__, 3) . '/doc/开发日志.md');
        self::assertStringContainsString('API 演示商品 541 店面 enrichment', $log);
        self::assertStringContainsString('/product/541', $log);
    }
}
