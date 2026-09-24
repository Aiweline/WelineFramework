<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Policy\RuntimePolicyStore;
use Weline\Server\Service\Runtime\RuntimeSelection;
use Weline\Server\Service\ServiceOrchestrator;

final class ServiceOrchestratorRuntimePolicyReconcileTest extends TestCase
{
    private const INSTANCE = 'ai-test-policy-reconcile';

    private string $storeBase = '';

    protected function setUp(): void
    {
        $this->storeBase = \sys_get_temp_dir() . '/wls-policy-reconcile-' . \bin2hex(\random_bytes(6));
    }

    protected function tearDown(): void
    {
        $directory = $this->storeBase . '/' . self::INSTANCE;
        foreach (\is_dir($directory) ? (\scandir($directory) ?: []) : [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @\unlink($directory . '/' . $entry);
            }
        }
        @\rmdir($directory);
        @\rmdir($this->storeBase);
    }

    public function testMissingStoreIsRestoredToMasterPublishedDigest(): void
    {
        $orchestrator = $this->orchestrator();
        $compiled = $this->invoke($orchestrator, 'compileRuntimePolicyForContext');
        $this->setProperty($orchestrator, 'runtimePolicyPublishedDigest', $compiled->digest);
        $this->setProperty($orchestrator, 'runtimePolicyState', 'failed');
        $this->setProperty($orchestrator, 'runtimePolicyFailedAt', \hrtime(true) / 1e9);

        $this->invoke($orchestrator, 'reconcileRuntimePolicyStoreAfterReadyMismatch');

        $active = $this->store()->active(self::INSTANCE);
        self::assertNotNull($active);
        self::assertSame($compiled->digest, $active->digest);
        self::assertSame('active', $this->getProperty($orchestrator, 'runtimePolicyState'));
    }

    public function testReconcileIsThrottled(): void
    {
        $orchestrator = $this->orchestrator();
        $compiled = $this->invoke($orchestrator, 'compileRuntimePolicyForContext');
        $this->setProperty($orchestrator, 'runtimePolicyPublishedDigest', $compiled->digest);
        $this->setProperty($orchestrator, 'runtimePolicyStoreReconciledAt', \hrtime(true) / 1e9);

        $this->invoke($orchestrator, 'reconcileRuntimePolicyStoreAfterReadyMismatch');

        self::assertNull($this->store()->active(self::INSTANCE));
    }

    public function testFailedStateIsNotPermanent(): void
    {
        $orchestrator = $this->orchestrator();
        $compiled = $this->invoke($orchestrator, 'compileRuntimePolicyForContext');
        $store = $this->store();
        $store->save(self::INSTANCE, $compiled);
        $store->activate(self::INSTANCE, $compiled->digest);
        $this->setProperty($orchestrator, 'runtimePolicyState', 'failed');
        $this->setProperty($orchestrator, 'runtimePolicyError', 'Critical policy participant disconnected before COMMIT');
        $this->setProperty($orchestrator, 'runtimePolicyFailedAt', \hrtime(true) / 1e9);

        $this->invoke($orchestrator, 'ensureRuntimePolicyPublished');
        self::assertSame('failed', $this->getProperty($orchestrator, 'runtimePolicyState'));

        $this->setProperty($orchestrator, 'runtimePolicyFailedAt', 0.0);
        $this->invoke($orchestrator, 'ensureRuntimePolicyPublished');
        self::assertSame('active', $this->getProperty($orchestrator, 'runtimePolicyState'));
        self::assertSame($compiled->digest, $this->getProperty($orchestrator, 'runtimePolicyPublishedDigest'));
    }

    private function store(): RuntimePolicyStore
    {
        return new RuntimePolicyStore($this->storeBase);
    }

    private function orchestrator(): ServiceOrchestrator
    {
        $orchestrator = new class($this->storeBase) extends ServiceOrchestrator {
            public function __construct(private readonly string $storeBase)
            {
                parent::__construct();
            }

            protected function createRuntimePolicyStore(): RuntimePolicyStore
            {
                return new RuntimePolicyStore($this->storeBase);
            }
        };
        $this->setProperty($orchestrator, 'context', new ServiceContext(
            instanceName: self::INSTANCE,
            epoch: 1,
            controlPort: 35821,
            masterPid: \getmypid() ?: 1,
            host: '127.0.0.1',
            mainPort: 9513,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            runtimeSelection: RuntimeSelection::fromArray([
                'requested_topology' => 'auto',
                'effective_topology' => 'dispatcher',
                'topology_source' => 'unit-test',
                'os_family' => PHP_OS_FAMILY,
                'event_loop_driver' => 'select',
                'ssl_engine' => 'stream',
                'listener_mode' => 'single',
                'policy_compatible' => true,
                'reason_codes' => ['unit_test'],
                'reason' => 'unit test runtime selection',
            ]),
            daemon: true,
            debug: false,
            windowMode: false,
            envConfig: ['wls' => ['edge' => ['adapter' => 'wls']]],
        ));

        return $orchestrator;
    }

    private function invoke(object $object, string $method): mixed
    {
        return (new \ReflectionMethod(ServiceOrchestrator::class, $method))->invoke($object);
    }

    private function setProperty(object $object, string $property, mixed $value): void
    {
        (new \ReflectionProperty(ServiceOrchestrator::class, $property))->setValue($object, $value);
    }

    private function getProperty(object $object, string $property): mixed
    {
        return (new \ReflectionProperty(ServiceOrchestrator::class, $property))->getValue($object);
    }
}
