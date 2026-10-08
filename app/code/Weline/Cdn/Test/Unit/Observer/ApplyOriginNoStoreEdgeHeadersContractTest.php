<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;

final class ApplyOriginNoStoreEdgeHeadersContractTest extends TestCase
{
    public function testObserverListensAndCloudflareWritesVendorHeaders(): void
    {
        $root = dirname(__DIR__, 3);
        $eventXml = (string)file_get_contents($root . '/etc/event.xml');
        $observer = (string)file_get_contents($root . '/Observer/ApplyOriginNoStoreEdgeHeaders.php');
        $cloudflare = (string)file_get_contents($root . '/Adapter/Cloudflare.php');
        $iface = (string)file_get_contents($root . '/Api/OriginNoStoreEdgeHeaderWriterInterface.php');

        self::assertFileExists($root . '/Api/OriginNoStoreEdgeHeaderWriterInterface.php');
        self::assertStringContainsString('Weline_Framework::response::shared_cache_forbidden', $eventXml);
        self::assertStringContainsString('ApplyOriginNoStoreEdgeHeaders', $eventXml);
        self::assertStringContainsString('OriginNoStoreEdgeHeaderWriterInterface', $observer);
        self::assertStringContainsString('\\bno-store\\b', $observer);
        self::assertStringContainsString('OriginNoStoreEdgeHeaderWriterInterface', $cloudflare);
        self::assertStringContainsString('applyOriginNoStoreEdgeHeaders', $cloudflare);
        self::assertStringContainsString("setHeader('CDN-Cache-Control', 'no-store')", $cloudflare);
        self::assertStringContainsString("setHeader('Cloudflare-CDN-Cache-Control', 'no-store')", $cloudflare);
        self::assertStringContainsString('applyOriginNoStoreEdgeHeaders', $iface);
    }
}
