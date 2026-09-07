<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\SessionStateFacade;
use Weline\Server\Service\SharedRuntimeConnectionWarmup;
use Weline\Server\Shared\Connection\ConnectionPoolManager;

final class SharedSessionPrewarmOptionsTest extends TestCase
{
    private array $previousRuntimeConfig;
    private array $previousPools;

    protected function setUp(): void
    {
        $this->previousRuntimeConfig = (new \ReflectionProperty(Env::class, 'runtimeConfig'))->getValue(Env::getInstance());
        $this->previousPools = (new \ReflectionProperty(ConnectionPoolManager::class, 'instances'))->getValue();
        Env::getInstance()->applyRuntimeConfig(['wls' => [
            'session' => ['connect_timeout' => 0.5, 'timeout' => 1.0, 'acquire_timeout' => 0.1],
            'shared_state' => ['prewarm_connect_timeout' => 0.05, 'prewarm_timeout' => 0.05, 'prewarm_acquire_timeout' => 0.01],
        ]]);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(ConnectionPoolManager::class, 'instances'))->setValue(null, $this->previousPools);
        (new \ReflectionProperty(Env::class, 'runtimeConfig'))->setValue(Env::getInstance(), $this->previousRuntimeConfig);
        (new \ReflectionMethod(Env::class, 'rebuildEffectiveConfig'))->invoke(Env::getInstance());
    }

    public function testSessionPrewarmPreservesBusinessPoolIdentityAndBudgets(): void
    {
        $endpoint = $this->endpoint();
        $businessOptions = $this->facadeOptions([], $endpoint);
        $pool = ConnectionPoolManager::getInstance($endpoint['host'], $endpoint['port'], $businessOptions);
        self::assertSame([0.5, 1.0], $this->timeouts($pool));
        $this->prewarm($endpoint);
        self::assertSame($pool, ConnectionPoolManager::getInstance($endpoint['host'], $endpoint['port'], $businessOptions));
        self::assertSame(['idle' => 0, 'busy' => 0, 'total' => 0], $pool->getPoolMetrics());
        self::assertSame([0.5, 1.0], $this->timeouts($pool));
    }

    public function testSessionPrewarmUsesExplicitSessionConfigWithoutMemoryPolicyBudgets(): void
    {
        $sessionConfig = ['connect_timeout' => 0.14, 'timeout' => 0.35, 'acquire_timeout' => 0.07];
        Env::getInstance()->applyRuntimeConfig(['wls' => ['session' => $sessionConfig]]);
        $endpoint = $this->endpoint();
        $businessOptions = $this->facadeOptions($sessionConfig, $endpoint);
        $pool = ConnectionPoolManager::getInstance($endpoint['host'], $endpoint['port'], $businessOptions);
        self::assertSame([0.14, 0.35], $this->timeouts($pool));
        $this->prewarm($endpoint);
        self::assertSame($pool, ConnectionPoolManager::getInstance($endpoint['host'], $endpoint['port'], $businessOptions));
        self::assertSame(['idle' => 0, 'busy' => 0, 'total' => 0], $pool->getPoolMetrics());
        self::assertSame([0.14, 0.35], $this->timeouts($pool));
    }

    private function prewarm(array $endpoint): void
    {
        // Exercise the real pool construction with no idle connections: no socket is opened.
        (new \ReflectionMethod(SharedRuntimeConnectionWarmup::class, 'warmPool'))->invoke(
            null,
            $endpoint,
            'Session',
            ControlMessage::ROLE_SESSION_SERVER,
            0,
            ['connect_timeout' => 0.02, 'timeout' => 0.02, 'acquire_timeout' => 0.01],
        );
    }

    private function facadeOptions(array $config, array $endpoint): array
    {
        $facade = (new \ReflectionClass(SessionStateFacade::class))->newInstanceWithoutConstructor();
        return (new \ReflectionMethod(SessionStateFacade::class, 'buildServiceOptions'))->invoke($facade, $config, $endpoint);
    }

    private function timeouts(ConnectionPoolManager $pool): array
    {
        $options = (new \ReflectionProperty(ConnectionPoolManager::class, 'options'))->getValue($pool);
        return [$options['connect_timeout'], $options['timeout']];
    }

    private function endpoint(): array
    {
        $identity = 'session-prewarm-test-' . \bin2hex(\random_bytes(8));
        return ['host' => '127.0.0.1', 'port' => 19003, 'token_file_name' => $identity . '.token', 'token_authority_instance' => $identity];
    }
}
