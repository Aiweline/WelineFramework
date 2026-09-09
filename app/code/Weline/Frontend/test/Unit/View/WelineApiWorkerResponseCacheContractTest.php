<?php

declare(strict_types=1);

namespace Weline\Frontend\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class WelineApiWorkerResponseCacheContractTest extends TestCase
{
    public function testWorkerDeclaresTtlCacheAndInflightDedupe(): void
    {
        $script = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/weline-api-worker.js',
        );
        self::assertStringContainsString('RESPONSE_CACHE_TTL_MS', $script);
        self::assertStringContainsString("'region.list': 4 * 60 * 60 * 1000", $script);
        self::assertStringContainsString("'region.country_profile': 12 * 60 * 60 * 1000", $script);
        self::assertStringContainsString("'consent.status': 60 * 60 * 1000", $script);
        self::assertStringContainsString('responseCacheInflight', $script);
        self::assertStringContainsString('dispatchCachedOrNetwork', $script);
        self::assertStringContainsString('canonicalizeResponseCacheParams', $script);
        self::assertStringContainsString('X-Weline-Worker-Response-Cache', $script);
        self::assertStringContainsString('weline-querybin-response-cache', $script);
        self::assertStringContainsString("cache: 'no-store'", $script);
    }

    public function testWorkerInvalidatesConsentStatusAfterAccept(): void
    {
        $script = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/weline-api-worker.js',
        );
        self::assertStringContainsString("'consent.accept': ['consent.status']", $script);
        self::assertStringContainsString("'consent.withdraw': ['consent.status']", $script);
    }

    public function testDevConsoleSurfacesLocalCacheFlag(): void
    {
        $console = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/weline-dev-console.js',
        );
        $api = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/weline-api.js',
        );
        $worker = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/weline-api-worker.js',
        );
        self::assertStringContainsString('localCacheBadge', $console);
        self::assertStringContainsString('LOCAL ', $console);
        self::assertStringContainsString('NET live', $console);
        self::assertStringContainsString('ms wall · ', $console);
        self::assertStringContainsString('workerElapsedMs', $console);
        self::assertStringContainsString('resolveWorkerResponseCacheState', $api);
        self::assertStringContainsString('responseCacheL1', $api);
        self::assertStringContainsString("localCache: 'l1'", $api);
        self::assertStringContainsString('workerElapsedMs', $worker);
        self::assertStringContainsString("'live'", $worker);
    }
}
