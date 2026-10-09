<?php
declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class CdnBinQueryDualPathRuleContractTest extends TestCase
{
    public function testCollectorEmitsDistinctIdentitiesForBinQueryAndQueryBin(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/CdnRuleCollector.php');
        self::assertStringContainsString('/bin/query', $source);
        self::assertStringContainsString('/api/framework/query-bin', $source);
        self::assertStringContainsString('pathBindings', $source);
        self::assertStringContainsString('BinQuery::', $source);
        self::assertStringContainsString('QueryBin::', $source);
        self::assertStringContainsString('__wq_cache', $source);
        self::assertStringContainsString('cache_key', $source);
        self::assertStringContainsString('Weline\\\\Frontend\\\\Controller\\\\Api\\\\BinQuery::', $source);
        self::assertStringContainsString('Weline\\\\Frontend\\\\Controller\\\\Api\\\\QueryBin::', $source);
    }

    public function testCloudflareForwardsCacheKeyActionParameter(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/Adapter/Cloudflare.php');
        self::assertStringContainsString("\$params['cache_key'] = \$cfg['cache_key']", $source);
    }
}
