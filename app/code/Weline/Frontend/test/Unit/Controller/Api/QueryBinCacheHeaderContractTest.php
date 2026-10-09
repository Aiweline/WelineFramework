<?php
declare(strict_types=1);

namespace Weline\Frontend\Test\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;

final class QueryBinCacheHeaderContractTest extends TestCase
{
    public function testQueryBinResolvesBinQueryCacheHeadersForCalls(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 4) . '/Controller/Api/QueryBin.php');
        self::assertStringContainsString('resolveCacheHeaders', $source);
        self::assertStringContainsString('BinQueryCachePolicy', $source);
        self::assertStringContainsString('readCacheMarker', $source);
        self::assertStringContainsString('array $cacheHeaders', $source);
        self::assertDoesNotMatchRegularExpression(
            '/private function binaryResponse\([^\)]*\)\s*:\s*Response\s*\{\s*[^}]*setHeader\(\'Cache-Control\',\s*\'no-store\'\);/s',
            $source,
            'binaryResponse must accept cache headers instead of hardcoding only no-store'
        );
    }
}
