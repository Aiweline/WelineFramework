<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

if (!\function_exists(__NAMESPACE__ . '\w_log_error')) {
    function w_log_error(string $message, array $context = [], ?string $channel = null): void
    {
    }
}

namespace Weline\Server\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Contract\SharedCacheStateInterface;
use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestResetException;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\StateManager;
use Weline\Framework\Runtime\WlsRuntime;
use Weline\Framework\Runtime\WlsRuntimeAdapterInterface;
use Weline\Framework\Runtime\WlsRuntimeAdapterResolver;
use Weline\Server\Service\WorkerResponseMemoryGuard;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . DIRECTORY_SEPARATOR);
\defined('DS') || \define('DS', DIRECTORY_SEPARATOR);

final class ResetQuarantineWlsRuntimeAdapter implements WlsRuntimeAdapterInterface
{
    public function discoverHotPaths(int $maxPaths): array
    {
        return [];
    }

    public function normalizeFrontendPagePath(mixed $path): ?string
    {
        return null;
    }

    public function createSharedState(array $config): SharedCacheStateInterface
    {
        throw new \LogicException('Shared state is not used by this reset-boundary test.');
    }

    public function recordPerformanceTrace(array $timing): void
    {
    }

    public function compactResponseMemory(): array
    {
        return [];
    }

    public function compactResponseMemoryIfPressure(float $threshold): ?array
    {
        return null;
    }

    public function requestDrainAfterResponse(string $reason): void
    {
        WorkerResponseMemoryGuard::requestDrainAfterResponse($reason);
    }

    public function isVerboseLog(): bool
    {
        return false;
    }

    public function flushLogs(): void
    {
    }
}

final class WlsRuntimeResetQuarantineTest extends TestCase
{
    private const FAILURE_CALLBACK = '__wls_runtime_reset_failure_probe__';
    private const AFTER_CALLBACK = '__wls_runtime_reset_after_probe__';

    private string $registryFile = '';
    private ?ServiceProviderRegistry $previousRegistry = null;

    protected function setUp(): void
    {
        $registryFile = \tempnam(\sys_get_temp_dir(), 'wls-reset-provider-');
        self::assertIsString($registryFile);
        $this->registryFile = $registryFile;
        self::assertNotFalse(\file_put_contents(
            $this->registryFile,
            "<?php\nreturn " . \var_export([
                'format' => 1,
                'modules' => [
                    'Weline_Server_Test' => [
                        'provides' => [
                            WlsRuntimeAdapterInterface::class
                                => ResetQuarantineWlsRuntimeAdapter::class,
                        ],
                    ],
                ],
                'order' => ['Weline_Server_Test'],
            ], true) . ";\n",
        ));
        $registry = new ServiceProviderRegistry($this->registryFile);
        $this->previousRegistry = ObjectManager::replaceServiceProviderRegistry($registry);
        ObjectManager::removeInstance(WlsRuntimeAdapterResolver::class);
        ObjectManager::removeInstance(ResetQuarantineWlsRuntimeAdapter::class);
        Runtime::setMode('wls');
        WorkerResponseMemoryGuard::consumeDrainAfterResponseReason();
    }

    protected function tearDown(): void
    {
        StateManager::unregisterResetCallback(self::FAILURE_CALLBACK);
        StateManager::unregisterResetCallback(self::AFTER_CALLBACK);
        WorkerResponseMemoryGuard::consumeDrainAfterResponseReason();
        Runtime::resetModeCache();
        ObjectManager::removeInstance(WlsRuntimeAdapterResolver::class);
        ObjectManager::removeInstance(ResetQuarantineWlsRuntimeAdapter::class);
        ObjectManager::replaceServiceProviderRegistry($this->previousRegistry);
        if ($this->registryFile !== '' && \is_file($this->registryFile)) {
            \unlink($this->registryFile);
        }
        parent::tearDown();
    }

    public function testResetAttemptsRemainingStagesAndRequestsWorkerQuarantine(): void
    {
        $afterFailureCalls = 0;
        StateManager::registerResetCallback(
            self::FAILURE_CALLBACK,
            static fn () => throw new \RuntimeException('runtime reset probe failed'),
        );
        StateManager::registerResetCallback(self::AFTER_CALLBACK, static function () use (&$afterFailureCalls): void {
            ++$afterFailureCalls;
        });

        try {
            (new WlsRuntime())->reset();
            self::fail('Expected the WLS reset boundary to fail closed.');
        } catch (RequestResetException $exception) {
            self::assertStringContainsString(self::FAILURE_CALLBACK, $exception->getMessage());
        }

        self::assertSame(1, $afterFailureCalls);
        self::assertSame(
            'request_reset_failure',
            WorkerResponseMemoryGuard::consumeDrainAfterResponseReason(),
        );
    }
}
