<?php

declare(strict_types=1);

namespace Weline\Framework\Router\Test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Adapter\WlsMemoryAdapter;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\Response;
use Weline\Framework\Router\FullPageCacheCoordinator;
use Weline\Framework\Runtime\Runtime;

final class FullPageCacheCoordinatorTest extends TestCase
{
    private array $originalServer = [];

    protected function setUp(): void
    {
        $this->originalServer = $_SERVER;
        $_SERVER = [];
        Runtime::setMode(Runtime::WLS);
        FullPageCacheCoordinator::clearProcessCache();
        WelineEnv::getInstance()->reset();
        WelineEnv::set('full_request_uri', 'https://example.test/', 'unit-test');
        WelineEnv::setServer('WELINE_FULL_REQUEST_URI', 'https://example.test/', 'unit-test');
        WelineEnv::set('request.uri', '/', 'unit-test');
        WelineEnv::set('request.method', 'GET', 'unit-test');
        WelineEnv::set('is_backend', false, 'unit-test');
        WelineEnv::set('is_static_file', false, 'unit-test');
        WelineEnv::set('response.from_cache', false, 'unit-test');
    }

    protected function tearDown(): void
    {
        WelineEnv::getInstance()->reset();
        FullPageCacheCoordinator::clearProcessCache();
        Runtime::resetModeCache();
        $_SERVER = $this->originalServer;
    }

    public function testGetCachedResponseRestoresLegacyHeadersAndStatus(): void
    {
        $pool = new InMemoryCachePool();
        $coordinator = new FullPageCacheCoordinator(null, $pool);
        $pool->set(
            $this->buildCurrentUnifiedFpcCacheKey($coordinator, 'GET'),
            [
                KeyBuilder::UNIFIED_CACHE_STATUS_KEY => 201,
                KeyBuilder::UNIFIED_CACHE_FPC_KEY => '<html><body>cached</body></html>',
                KeyBuilder::UNIFIED_CACHE_HEADERS_KEY => [
                    'Content-Type: text/html; charset=utf-8',
                    'Cache-Control: public, max-age=60',
                    'Content-Length: 999',
                ],
            ]
        );

        $response = $coordinator->getCachedResponse('GET');

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('<html><body>cached</body></html>', $response->getBody());
        self::assertSame('text/html; charset=utf-8', $response->getHeader('Content-Type'));
        self::assertSame('public, max-age=60', $response->getHeader('Cache-Control'));
        self::assertNull($response->getHeader('Content-Length'));
        self::assertSame('HIT', $response->getHeader('X-Weline-FPC'));
        self::assertTrue((bool)WelineEnv::get('response.from_cache', false));
    }

    public function testCooperativeFpcYieldIsOptInForPersistentRequests(): void
    {
        $method = new \ReflectionMethod(FullPageCacheCoordinator::class, 'cooperativeBuildYield');
        $file = $method->getFileName();

        self::assertIsString($file);

        $lines = \file($file);
        self::assertIsArray($lines);

        $source = \implode('', \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        self::assertStringContainsString('wls.performance.fpc_cooperative_yield_enabled', $source);
        self::assertStringContainsString('false', $source);
        self::assertStringContainsString('SchedulerSystem::yield()', $source);
    }

    public function testBuildLockAllowsSinglePublisherAndFollowersReadPublishedResponse(): void
    {
        $adapter = new class extends WlsMemoryAdapter {
            /**
             * @var array<string, mixed>
             */
            private array $lockStore = [];

            public function __construct()
            {
            }

            public function compareAndSet(string $key, mixed $expected, mixed $value, int $ttl = 0): bool
            {
                $current = $this->lockStore[$key] ?? null;
                if ($current !== $expected) {
                    return false;
                }

                if ($value === null) {
                    unset($this->lockStore[$key]);
                } else {
                    $this->lockStore[$key] = $value;
                }

                return true;
            }
        };

        $pool = new InMemoryCachePool($adapter);
        $coordinator = new FullPageCacheCoordinator(null, $pool);

        $lockA = $coordinator->acquireBuildLock('GET');
        $lockB = $coordinator->acquireBuildLock('GET');

        self::assertNotNull($lockA);
        self::assertNull($lockB);

        $response = Response::html('<html><body>fresh</body></html>', 200)
            ->setHeader('Cache-Control', 'public, max-age=30');
        $coordinator->publishResponse($response, '/', ['id' => 'home'], ['module' => 'Test_Module'], [], 'GET');

        $published = $coordinator->waitForPublishedResponse('GET', 1);
        self::assertInstanceOf(Response::class, $published);
        self::assertSame('<html><body>fresh</body></html>', $published->getBody());
        self::assertSame('public, max-age=30', $published->getHeader('Cache-Control'));
        self::assertSame('HIT', $published->getHeader('X-Weline-FPC'));

        $coordinator->releaseBuildLock($lockA);
        $lockC = $coordinator->acquireBuildLock('GET');
        self::assertNotNull($lockC);
        $coordinator->releaseBuildLock($lockC);
    }

    private function buildCurrentUnifiedFpcCacheKey(FullPageCacheCoordinator $coordinator, string $method): string
    {
        $variantMethod = new \ReflectionMethod($coordinator, 'buildCurrentFpcVariant');
        $variantMethod->setAccessible(true);
        $variant = $variantMethod->invoke($coordinator);

        self::assertIsArray($variant);

        $keyMethod = new \ReflectionMethod($coordinator, 'buildUnifiedFpcCacheKey');
        $keyMethod->setAccessible(true);

        return (string)$keyMethod->invoke($coordinator, 'https://example.test/', $method, $variant);
    }
}

final class InMemoryCachePool implements CachePoolInterface
{
    /**
     * @var array<string, mixed>
     */
    private array $store = [];

    public function __construct(private readonly ?object $adapter = null)
    {
    }

    public function get(string $key): mixed
    {
        return $this->store[$key] ?? null;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->store[$key] = $value;
        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->store = [];
        return true;
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->store);
    }

    public function getIdentity(): string
    {
        return 'router';
    }

    public function getTip(): string
    {
        return 'unit-test';
    }

    public function isPermanent(): bool
    {
        return false;
    }

    public function getMultiple(array $keys): array
    {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->get($key);
        }

        return $values;
    }

    public function setMultiple(array $values, int $ttl = 0): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string)$key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function getStats(): array
    {
        return [
            'identity' => 'router',
            'hits' => 0,
            'misses' => 0,
            'hit_ratio' => 0.0,
            'permanent' => false,
        ];
    }

    public function getAdapter(): ?object
    {
        return $this->adapter;
    }
}
