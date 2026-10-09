<?php
declare(strict_types=1);

namespace Weline\Frontend\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class WelineApiWorkerCacheMarkerContractTest extends TestCase
{
    public function testWorkerBuildsWqCacheMarkerFromCatalog(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/view/statics/js/weline-api-worker.js');
        self::assertStringContainsString('async function buildCacheMarker', $source);
        self::assertStringContainsString('async function withCacheMarkerUrl', $source);
        self::assertStringContainsString("__wq_cache", $source);
        self::assertStringContainsString('cacheCatalog', $source);
        self::assertStringContainsString('website_id', $source);
        self::assertStringContainsString('withCacheMarkerUrl(', $source);
    }

    public function testHeaderBaseInjectsCacheCatalogIntoApiConfig(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/view/blocks/header/base.phtml');
        self::assertStringContainsString('BinQueryCacheCatalogExporter', $source);
        self::assertStringContainsString("'cacheCatalog'", $source);
        self::assertStringContainsString("'website_id'", $source);
    }
}
