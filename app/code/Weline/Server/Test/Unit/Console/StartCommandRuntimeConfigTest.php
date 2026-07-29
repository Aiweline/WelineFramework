<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Start;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\Runtime\RuntimeSelection;

class StartCommandRuntimeConfigTest extends TestCase
{
    public function testPureWlsCliKeepsHttp2WithHttp11Fallback(): void
    {
        $start = new class extends Start {
            /** @return array<string,mixed> */
            public function configFor(string $instanceName, array $args): array
            {
                return $this->getServerConfig($instanceName, $args);
            }

            protected function getEnvConfig(): array
            {
                return [];
            }

            protected function loadSavedInstanceConfig(string $instanceName): ?array
            {
                return null;
            }

            protected function hasCliArgvToken(array $tokens): bool
            {
                return false;
            }
        };
        $start->__init();

        $config = $start->configFor('unit-pure-wls-h2', ['edge' => 'wls']);

        self::assertSame('wls', $config['edge']['adapter'] ?? null);
        self::assertSame(['h2', 'h1'], $config['http']['protocols'] ?? null);
        self::assertSame('h2', $config['http']['preferred'] ?? null);
        self::assertTrue((bool)($config['http']['tls_session_resumption'] ?? false));
    }

    public function testCliHostOverrideRefreshesPersistedDerivedPublicOrigin(): void
    {
        $start = new class extends Start {
            /** @return array<string,mixed> */
            public function configFor(string $instanceName, array $args): array
            {
                return $this->getServerConfig($instanceName, $args);
            }

            protected function getEnvConfig(): array
            {
                return [];
            }

            protected function loadSavedInstanceConfig(string $instanceName): ?array
            {
                return [
                    'host' => 'old-reused.weline.test',
                    'public_host' => 'old-reused.weline.test',
                    'public_origin' => 'https://old-reused.weline.test',
                    'https' => true,
                ];
            }

            protected function ensureHostsFileConfigured(string $host): void
            {
                // Unit configuration tests must never mutate the host OS network files.
            }

            protected function hasCliArgvToken(array $tokens): bool
            {
                return \in_array('--host', $tokens, true);
            }
        };
        $start->__init();

        $config = $start->configFor('unit-reused-host', [
            'host' => 'new-reused.weline.test',
        ]);

        self::assertSame('new-reused.weline.test', $config['host'] ?? null);
        self::assertSame('new-reused.weline.test', $config['public_host'] ?? null);
        self::assertSame('https://new-reused.weline.test', $config['public_origin'] ?? null);
    }

    public function testConfigureMasterRuntimeKeepsFrontendWorkerTopology(): void
    {
        $start = new Start();
        $start->__init();
        $master = new MasterProcess();

        $method = new \ReflectionMethod(Start::class, 'configureMasterRuntime');
        $method->setAccessible(true);
        $runtimeSelection = RuntimeSelection::fromArray([
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
        ]);

        $result = $method->invoke(
            $start,
            $master,
            $runtimeSelection,
            2,
            10000,
            22081,
            12081
        );

        self::assertSame($master, $result);
        self::assertSame($runtimeSelection, $this->readProperty($master, 'runtimeSelection'));
        self::assertSame(2, $this->readProperty($master, 'workerCount'));
        self::assertSame(22080, $this->readProperty($master, 'workerBasePort'));
        self::assertSame(22081, $this->readProperty($master, 'workerPort'));
        self::assertSame(12081, $this->readProperty($master, 'mainPort'));
    }

    private function readProperty(object $object, string $property): mixed
    {
        $ref = new \ReflectionProperty($object, $property);
        $ref->setAccessible(true);

        return $ref->getValue($object);
    }
}
