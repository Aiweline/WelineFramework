<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Service\StorefrontWarmup\StorefrontWarmupHttpClient;

/** 具名站预热：loopback 连接 + 该站 Host 头，写各自 FPC 叶。 */
final class StorefrontWarmupHttpClientScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!\defined('BP')) {
            \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);
        }
    }

    public function testHostOverrideReturnsSingleLoopbackTarget(): void
    {
        $http = new StorefrontWarmupHttpClient('grocery.test.weline.com');
        $targets = $http->resolveTargets();

        self::assertCount(1, $targets);
        self::assertSame('127.0.0.1', $targets[0]['connect']);
        self::assertSame('grocery.test.weline.com', $targets[0]['host']);
        self::assertSame(9555, $targets[0]['port']);
        self::assertSame('http', $targets[0]['scheme']);
    }

    public function testHostOverrideNormalizesCaseAndPort(): void
    {
        $http = new StorefrontWarmupHttpClient('https://GROCERY.Test.Weline.com:443/shop/');
        $targets = $http->resolveTargets();

        self::assertCount(1, $targets);
        self::assertSame('grocery.test.weline.com', $targets[0]['host']);
        self::assertSame('127.0.0.1', $targets[0]['connect']);
    }

    public function testEmptyOverrideKeepsLegacyTargets(): void
    {
        $http = new StorefrontWarmupHttpClient();
        $targets = $http->resolveTargets();

        self::assertNotEmpty($targets);
        self::assertGreaterThanOrEqual(2, \count($targets));
        self::assertSame('p05113ef3.test.weline.com', $targets[0]['host']);
    }

    public function testLoopbackOverrideFallsBackToLegacyTargets(): void
    {
        $http = new StorefrontWarmupHttpClient('http://127.0.0.1:8800');
        $targets = $http->resolveTargets();

        self::assertNotEmpty($targets);
        self::assertSame('p05113ef3.test.weline.com', $targets[0]['host']);
    }

    public function testGetManyUsesInjectedHostForEveryPath(): void
    {
        // WLS 停机时 curl 会失败，但失败原因必须是「连不上 loopback 目标」，
        // 而不是 no_target —— 证明注入的站点 Host 目标被真正用于每个请求。
        $http = new StorefrontWarmupHttpClient('grocery.test.weline.com');
        $results = $http->getMany(['/a', '/b'], false);

        self::assertCount(2, $results);
        foreach ($results as $row) {
            self::assertNotSame('no_target', $row['reason']);
        }
    }
}
