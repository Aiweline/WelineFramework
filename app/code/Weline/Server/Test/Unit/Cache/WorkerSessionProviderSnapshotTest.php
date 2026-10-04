<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Contract\SharedCacheStateInterface;
use Weline\Framework\Service\Query\FrontendQueryException;
use Weline\Framework\Service\Query\FrontendWorkerSessionService;
use Weline\Framework\Service\Query\Store\AtomicCacheFrontendWorkerStateStore;
use Weline\Server\Cache\Adapter\WlsMemoryAdapter;
use Weline\Server\Cache\Adapter\WlsMemoryAdapterCreator;

final class WorkerSessionProviderSnapshotTest extends TestCase
{
    public function testProviderAdapterValidatesTokensIssuedByAnotherWorker(): void
    {
        self::assertInstanceOf(WlsMemoryAdapter::class, (new WlsMemoryAdapterCreator())->create('isolated_provider_class'));
        $state = [];
        $shared = $this->createMock(SharedCacheStateInterface::class);
        $shared->method('get')->willReturn(0);
        // Capture the shared snapshot by reference so each worker sees successful CAS writes.
        $shared->method('getCache')->willReturnCallback(static function (string $pool, string $key) use (&$state): mixed {
            return $state[$pool][$key] ?? null;
        });
        $shared->method('compareAndSetCache')->willReturnCallback(static function (string $pool, string $key, mixed $expected, mixed $value) use (&$state): bool {
            if (($state[$pool][$key] ?? null) !== $expected) {
                return false;
            }
            $state[$pool][$key] = $value;
            return true;
        });
        $services = [];
        foreach (range(1, 2) as $worker) {
            $adapter = new WlsMemoryAdapter('isolated_provider_session', ['local_cache_memory_pressure_threshold' => 0.99], $shared);
            $services[] = new FrontendWorkerSessionService(new AtomicCacheFrontendWorkerStateStore($adapter, 'cache:wls_memory'));
        }
        $first = $services[0]->createSession('test-deploy', 'test-build');
        $services[1]->validateSession($first['worker_session_token'], 'test-deploy', 'test-build');
        $second = $services[0]->createSession('test-deploy', 'test-build');
        $services[1]->validateSession($second['worker_session_token'], 'test-deploy', 'test-build');
        $services[0]->validateSession($first['worker_session_token'], 'test-deploy', 'test-build');
        self::assertCount(2, $state['isolated_provider_session']['worker_state.v1']['weline_frontend_worker_sessions']);
        try {
            $services[1]->validateSession('not-issued', 'test-deploy', 'test-build');
            self::fail('An unissued token was accepted.');
        } catch (FrontendQueryException $error) {
            self::assertSame('Invalid worker session token.', $error->getMessage());
        }
    }
}
