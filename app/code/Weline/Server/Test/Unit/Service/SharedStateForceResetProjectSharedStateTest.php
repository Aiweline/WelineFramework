<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\SharedStateRuntimeScope;
use Weline\Server\Service\SharedStateServiceManager;
use Weline\Server\Service\SharedStateServiceRegistry;

require_once \dirname(__DIR__, 7) . '/app/bootstrap_phpunit.php';

/**
 * -f factory reset must clear stale lifecycle runtime files without throwing.
 */
final class SharedStateForceResetProjectSharedStateTest extends TestCase
{
    public function testForceResetClearsStaleRuntimeAndRegistryWithoutThrowing(): void
    {
        $id = \bin2hex(\random_bytes(4));
        $sharedDir = Env::VAR_DIR . 'server' . \DIRECTORY_SEPARATOR . 'shared' . \DIRECTORY_SEPARATOR;
        $registryDir = Env::VAR_DIR . 'server' . \DIRECTORY_SEPARATOR . 'shared-services' . \DIRECTORY_SEPARATOR;
        if (!\is_dir($sharedDir) && !@\mkdir($sharedDir, 0755, true) && !\is_dir($sharedDir)) {
            self::markTestSkipped('unable to create shared dir under VAR_DIR');
        }
        if (!\is_dir($registryDir) && !@\mkdir($registryDir, 0755, true) && !\is_dir($registryDir)) {
            self::markTestSkipped('unable to create shared-services dir under VAR_DIR');
        }

        $runtimePath = $sharedDir . 'force-reset-test-' . $id . '.json';
        $runtimeLock = $runtimePath . '.lock';
        $lifecycleLock = $sharedDir . 'force-reset-test-' . $id . '.lifecycle.lock';
        $registryPath = $registryDir . 'force-reset-test-' . $id . '-registry.json';
        $tokenName = 'force-reset-test-' . $id . '.token';
        $tokenPath = SharedStateRuntimeScope::tokenFilePath($tokenName);

        $stale = [
            'schema' => 'wls-shared-runtime/2',
            'role' => ControlMessage::ROLE_SESSION_SERVER,
            'host' => '127.0.0.1',
            'port' => 19970,
            'pid' => 1,
            'token_file_name' => $tokenName,
            'process_name' => 'weline-wls-session-force-reset-test',
            'instance_name' => 'shared-session-force-reset-test',
            'service_instance_name' => 'shared-session-force-reset-test',
            'lifecycle_schema' => 'wls-shared-lifecycle/2',
            'lifecycle_generation' => 3,
            // Deliberately wrong digest vs identity fields → stale/invalid binding.
            'lifecycle_identity_digest' => \str_repeat('a', 64),
        ];
        self::assertNotFalse(\file_put_contents($runtimePath, \json_encode($stale, JSON_THROW_ON_ERROR)));
        self::assertTrue(\touch($runtimeLock));
        self::assertTrue(\touch($lifecycleLock));
        self::assertNotFalse(\file_put_contents(
            $registryPath,
            \json_encode([
                'schema' => 'wls-shared-registry/2',
                'services' => [
                    ControlMessage::ROLE_SESSION_SERVER => $stale,
                ],
            ], JSON_THROW_ON_ERROR),
        ));
        $tokenDir = \dirname($tokenPath);
        if (!\is_dir($tokenDir) && !@\mkdir($tokenDir, 0755, true) && !\is_dir($tokenDir)) {
            self::markTestSkipped('unable to create token dir');
        }
        self::assertNotFalse(\file_put_contents($tokenPath, 'test-token-' . $id));

        $manager = new class($runtimePath, $lifecycleLock, $registryPath) extends SharedStateServiceManager {
            public function __construct(
                private readonly string $runtimePath,
                private readonly string $lifecycleLockPath,
                private readonly string $registryPath,
            ) {
            }

            public function stopWithReport(string $role, array $config = [], array $envConfig = []): array
            {
                throw new \RuntimeException(
                    'Stale WLS shared-state lifecycle binding cannot replace the current generation.'
                );
            }

            protected function getRuntimeFilePath(string $role): string
            {
                return $this->runtimePath;
            }

            protected function getRoleLifecycleLockPath(string $role): string
            {
                return $this->lifecycleLockPath;
            }

            protected function createRegistry(): SharedStateServiceRegistry
            {
                $path = $this->registryPath;

                return new class($path) extends SharedStateServiceRegistry {
                    public function __construct(private readonly string $path)
                    {
                    }

                    public function getRegistryFile(): string
                    {
                        return $this->path;
                    }

                    public function removeRecord(string $role): void
                    {
                        // Force-reset path may call this before whole-file unlink.
                    }
                };
            }

            protected function inspectRunningSharedService(array $definition, string $tokenFileName): array
            {
                return ['in_use' => false, 'reusable' => false, 'pid' => 0];
            }

            protected function isMemoryEnabled(array $config, array $envConfig): bool
            {
                return false;
            }

            protected function buildRoleDefinition(
                string $role,
                string $requesterInstanceName,
                array $config,
                array $envConfig,
                array $reservedPorts = []
            ): array {
                return [
                    'role' => ControlMessage::ROLE_SESSION_SERVER,
                    'host' => '127.0.0.1',
                    'port' => 19970,
                    'token_file_name' => 'force-reset-test.token',
                ];
            }
        };

        try {
            $report = $manager->forceResetProjectSharedState([], [
                'wls' => ['memory_service' => ['enabled' => false]],
            ]);
            self::assertTrue((bool)($report['ok'] ?? false));
            self::assertTrue((bool)($report['registry_cleared'] ?? false));
            $sessionReport = $report['roles'][ControlMessage::ROLE_SESSION_SERVER] ?? [];
            self::assertTrue((bool)($sessionReport['artifacts_cleared'] ?? false));
            self::assertStringContainsString('stop_failed', (string)($sessionReport['stop_reason'] ?? ''));
            self::assertFileDoesNotExist($runtimePath);
            self::assertFileDoesNotExist($runtimeLock);
            self::assertFileDoesNotExist($lifecycleLock);
            self::assertFileDoesNotExist($registryPath);
            self::assertFileDoesNotExist($tokenPath);
        } finally {
            foreach ([$runtimePath, $runtimeLock, $lifecycleLock, $registryPath, $tokenPath] as $path) {
                if (\is_file($path) || \is_link($path)) {
                    @\unlink($path);
                }
            }
        }
    }
}
