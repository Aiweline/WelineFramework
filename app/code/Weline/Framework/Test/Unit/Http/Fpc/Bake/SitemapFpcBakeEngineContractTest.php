<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http\Fpc\Bake;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Fpc\Bake\FpcConnectOnlyHttpClient;
use Weline\Framework\Http\Fpc\Bake\LoadTargetConcurrencyController;
use Weline\Framework\Http\Fpc\Bake\SitemapFpcBakeEngine;

final class SitemapFpcBakeEngineContractTest extends TestCase
{
    public function testRewriteOriginKeepsPathAndSwapsHost(): void
    {
        $out = FpcConnectOnlyHttpClient::rewriteOrigin(
            'https://p05113ef3.test.weline.com/en_US/product/a',
            'https://www.changanhanfu.com'
        );
        self::assertSame('https://www.changanhanfu.com/en_US/product/a', $out);
    }

    public function testConnectPeerMustNotEqualBakeOriginHost(): void
    {
        $engine = new SitemapFpcBakeEngine();
        self::assertTrue($engine->isSafeConnectPeer('127.0.0.1', 'https://www.changanhanfu.com'));
        self::assertTrue($engine->isSafeConnectPeer('p05113ef3.test.weline.com', 'https://www.changanhanfu.com'));
        self::assertFalse($engine->isSafeConnectPeer('www.changanhanfu.com', 'https://www.changanhanfu.com'));
    }

    public function testDryRunPlansRewrittenUrlsWithoutHttp(): void
    {
        $engine = new SitemapFpcBakeEngine();
        $result = $engine->bake(
            [
                'https://local.test/a',
                'https://local.test/b',
                'https://local.test/a',
            ],
            'https://www.changanhanfu.com',
            '',
            70.0,
            2,
            false,
            true,
        );
        self::assertTrue($result['ok']);
        self::assertSame(
            [
                'https://www.changanhanfu.com/a',
                'https://www.changanhanfu.com/b',
            ],
            $result['planned']
        );
        self::assertSame(0, $result['warmed']);
    }

    public function testEngineSourceHasNoSeoOrWebsitesImports(): void
    {
        $path = \dirname(__DIR__, 5) . '/Http/Fpc/Bake/SitemapFpcBakeEngine.php';
        $src = (string)\file_get_contents($path);
        self::assertStringNotContainsString('Weline\\Seo\\', $src);
        self::assertStringNotContainsString('Weline\\Websites\\', $src);
        $clientPath = \dirname(__DIR__, 5) . '/Http/Fpc/Bake/FpcConnectOnlyHttpClient.php';
        $clientSrc = (string)\file_get_contents($clientPath);
        self::assertStringNotContainsString('Fallback: direct absolute', $clientSrc);
        self::assertStringContainsString('Never falls back', $clientSrc);
        self::assertStringContainsString('X-Forwarded-Proto:', $clientSrc);
        self::assertStringContainsString('X-Forwarded-Port:', $clientSrc);
    }

    public function testLoadTargetControllerFullPowerPinsMax(): void
    {
        $c = new LoadTargetConcurrencyController(0.7, 4, true);
        $c->tick();
        self::assertSame(4, $c->concurrency());
    }

    public function testBusyGateAndLoadTargetAreSeparateClasses(): void
    {
        $busy = \dirname(__DIR__, 5) . '/Cache/Service/StorefrontWarmup/StorefrontWarmupBusyGate.php';
        $load = \dirname(__DIR__, 5) . '/Http/Fpc/Bake/LoadTargetConcurrencyController.php';
        self::assertFileExists($busy);
        self::assertFileExists($load);
        $busySrc = (string)\file_get_contents($busy);
        self::assertStringContainsString('abort', $busySrc);
        $loadSrc = (string)\file_get_contents($load);
        self::assertStringContainsString('targetLoad', $loadSrc);
        self::assertStringNotContainsString('StorefrontWarmupBusyGate', $loadSrc);
    }
}
