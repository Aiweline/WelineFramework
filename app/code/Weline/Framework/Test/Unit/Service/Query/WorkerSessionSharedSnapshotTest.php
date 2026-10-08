<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Service\Query;

use PHPUnit\Framework\TestCase;
use Weline\Server\Cache\Adapter\WlsMemoryAdapter;
use Weline\Framework\Service\Query\FrontendWorkerSessionService;
use Weline\Framework\Service\Query\FrontendQueryException;
use Weline\Framework\Service\Query\Store\LockedCacheFrontendWorkerStateStore;
use Weline\Server\Service\MemoryStateFacade;

final class WorkerSessionSharedSnapshotTest extends TestCase
{
    public function testCredentialStateRemainsAuthoritativeUnderLocalMemoryPressure(): void
    {
        $previousLimit = ini_get('memory_limit');
        ini_set('memory_limit', '512M');
        $remote = new SharedSnapshotMemoryFacade();
        $lock = tempnam(sys_get_temp_dir(), 'worker-pressure-');
        try {
            $adapter = new WlsMemoryAdapter('isolated_worker_pressure', ['local_cache_memory_pressure_threshold' => 0.99]);
            (new \ReflectionProperty($adapter, 'memoryFacade'))->setValue($adapter, $remote);
            $service = new FrontendWorkerSessionService(new LockedCacheFrontendWorkerStateStore($adapter, $lock));
            $first = $service->createSession('test-deploy', 'test-build');
            (new \ReflectionProperty($adapter, 'localCachePressureThreshold'))->setValue($adapter, 0.0001);
            $service->validateSession($first['worker_session_token'], 'test-deploy', 'test-build');
            $second = $service->createSession('test-deploy', 'test-build');
            $service->validateSession($second['worker_session_token'], 'test-deploy', 'test-build');
            self::assertCount(2, $remote->values['worker_state.v1']['weline_frontend_worker_sessions']);
            try {
                $service->validateSession('not-issued', 'test-deploy', 'test-build');
                self::fail('An unissued token was accepted under memory pressure.');
            } catch (FrontendQueryException $error) {
                self::assertSame('Invalid worker session token.', $error->getMessage());
            }
        } finally {
            ini_set('memory_limit', $previousLimit);
            @unlink($lock);
        }
    }

    public function testSessionsRemainValidAcrossWorkerLocalSnapshots(): void
    {
        $remote = new SharedSnapshotMemoryFacade();
        $lock = tempnam(sys_get_temp_dir(), 'worker-snapshot-');
        try {
            $services = [];
            foreach (range(1, 2) as $worker) {
                $adapter = new WlsMemoryAdapter('isolated_worker_snapshot', ['local_cache_memory_pressure_threshold' => 0.99]);
                (new \ReflectionProperty($adapter, 'memoryFacade'))->setValue($adapter, $remote);
                $services[] = new FrontendWorkerSessionService(new LockedCacheFrontendWorkerStateStore($adapter, $lock, 'cache:wls_memory', 86400, 120, true));
            }
            $first = $services[0]->createSession('test-deploy', 'test-build');
            $services[1]->validateSession($first['worker_session_token'], 'test-deploy', 'test-build');
            $second = $services[0]->createSession('test-deploy', 'test-build');
            // Worker two has already cached the snapshot preceding handshake two.
            $services[1]->validateSession($second['worker_session_token'], 'test-deploy', 'test-build');
            $services[0]->validateSession($first['worker_session_token'], 'test-deploy', 'test-build');
            self::assertCount(2, $remote->values['worker_state.v1']['weline_frontend_worker_sessions']);
            try {
                $services[1]->validateSession('not-issued', 'test-deploy', 'test-build');
                self::fail('An unissued token was accepted.');
            } catch (FrontendQueryException $error) {
                self::assertSame('Invalid worker session token.', $error->getMessage());
            }
        } finally {
            @unlink($lock);
        }
    }
}

final class SharedSnapshotMemoryFacade extends MemoryStateFacade
{
    public array $values = [];
    public function __construct() {}
    public function get(string $namespace, string $key): mixed { return 0; }
    public function getCache(string $poolIdentity, string $key): mixed { return $this->values[$key] ?? null; }
    public function setCache(string $poolIdentity, string $key, mixed $value, int $ttl = 0): bool
    {
        $this->values[$key] = $value;
        return true;
    }
    public function disconnect(): void {}
}
