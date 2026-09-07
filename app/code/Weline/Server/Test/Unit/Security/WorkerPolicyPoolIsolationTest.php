<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Security;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Framework\Runtime\Policy\RuntimePolicyBundle;
use Weline\Server\Security\ConnectionAcceptGatePool;
use Weline\Server\Security\WorkerPolicyKernel;
use Weline\Server\Service\MemoryStateFacade;
use Weline\Server\Service\Runtime\RoutingPolicyRegistry;
use Weline\Server\Shared\Client\SharedStateClient;
use Weline\Server\Shared\Connection\ConnectionPoolManager;

final class WorkerPolicyPoolIsolationTest extends TestCase
{
    private array $runtimeConfig;
    private array $staticState = [];
    private array $processEnvironment;

    protected function setUp(): void
    {
        $this->runtimeConfig = (new \ReflectionProperty(Env::class, 'runtimeConfig'))->getValue(Env::getInstance());
        foreach ([ConnectionPoolManager::class, RoutingPolicyRegistry::class, WorkerPolicyKernel::class, ConnectionAcceptGatePool::class] as $class) {
            foreach ((new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
                $this->staticState[] = [$property, $property->getValue()];
            }
        }
        (new \ReflectionProperty(ConnectionPoolManager::class, 'instances'))->setValue(null, []);
        (new \ReflectionProperty(ConnectionAcceptGatePool::class, 'instance'))->setValue(null, null);
        $this->processEnvironment = [\getenv('WLS_RUNTIME_TOPOLOGY'), $_ENV['WLS_RUNTIME_TOPOLOGY'] ?? null, $_SERVER['WLS_RUNTIME_TOPOLOGY'] ?? null];
        $identity = 'worker-policy-pool-test-' . \bin2hex(\random_bytes(8));
        Env::getInstance()->applyRuntimeConfig(['wls' => ['memory_service' => [
            'host' => '127.0.0.1', 'port' => 19004, 'token_file_name' => $identity . '.token',
        ]]]);
        // Preload a data-only policy bundle; boot must not load policy storage or open a socket.
        $bundle = RuntimePolicyBundle::fromDescriptors([], topology: 'direct');
        RoutingPolicyRegistry::prepare($bundle);
        RoutingPolicyRegistry::activate($bundle->digest);
    }

    protected function tearDown(): void
    {
        foreach (\array_reverse($this->staticState) as [$property, $value]) {
            $property->setValue(null, $value);
        }
        (new \ReflectionProperty(Env::class, 'runtimeConfig'))->setValue(Env::getInstance(), $this->runtimeConfig);
        (new \ReflectionMethod(Env::class, 'rebuildEffectiveConfig'))->invoke(Env::getInstance());
        [$environment, $env, $server] = $this->processEnvironment;
        \putenv($environment === false ? 'WLS_RUNTIME_TOPOLOGY' : 'WLS_RUNTIME_TOPOLOGY=' . $environment);
        if ($env === null) { unset($_ENV['WLS_RUNTIME_TOPOLOGY']); } else { $_ENV['WLS_RUNTIME_TOPOLOGY'] = $env; }
        if ($server === null) { unset($_SERVER['WLS_RUNTIME_TOPOLOGY']); } else { $_SERVER['WLS_RUNTIME_TOPOLOGY'] = $server; }
    }

    public function testBootKeepsPolicyBudgetSeparateFromBusinessPool(): void
    {
        $business = $this->businessFacade();
        $businessPool = $this->pool($business);
        $kernel = WorkerPolicyKernel::boot('pool-isolation-test', 'direct');
        $state = (new \ReflectionProperty(WorkerPolicyKernel::class, 'state'))->getValue($kernel);
        self::assertInstanceOf(MemoryStateFacade::class, $state);
        $policyPool = $this->pool($state);
        self::assertNotSame($businessPool, $policyPool);
        self::assertSame([0.05, 0.05], $this->timeouts($businessPool));
        self::assertSame([0.02, 0.02], $this->timeouts($policyPool));
        self::assertSame(['idle' => 0, 'busy' => 0, 'total' => 0], $businessPool->getPoolMetrics());
        self::assertSame(['idle' => 0, 'busy' => 0, 'total' => 0], $policyPool->getPoolMetrics());
    }

    public function testReconnectReusesPolicyPoolWithoutShrinkingBusinessBudget(): void
    {
        $business = $this->businessFacade();
        $businessPool = $this->pool($business);
        $kernel = WorkerPolicyKernel::boot('pool-isolation-test', 'direct');
        $stateProperty = new \ReflectionProperty(WorkerPolicyKernel::class, 'state');
        $bootState = $stateProperty->getValue($kernel);
        self::assertInstanceOf(MemoryStateFacade::class, $bootState);
        $bootPool = $this->pool($bootState);
        $limiter = (new \ReflectionProperty(WorkerPolicyKernel::class, 'rateLimiter'))->getValue($kernel);
        $limiter->attachState(null);
        (new \ReflectionMethod(WorkerPolicyKernel::class, 'reconnectSharedStateIfDue'))->invoke($kernel);
        $reconnectedState = $stateProperty->getValue($kernel);
        self::assertInstanceOf(MemoryStateFacade::class, $reconnectedState);
        $reconnectedPool = $this->pool($reconnectedState);
        self::assertSame($bootPool, $reconnectedPool);
        self::assertNotSame($businessPool, $reconnectedPool);
        self::assertSame([0.05, 0.05], $this->timeouts($businessPool));
        self::assertSame([0.01, 0.01], $this->timeouts($reconnectedPool));
        self::assertFalse($limiter->shouldReconnectSharedState());
        self::assertSame(['idle' => 0, 'busy' => 0, 'total' => 0], $businessPool->getPoolMetrics());
        self::assertSame(['idle' => 0, 'busy' => 0, 'total' => 0], $reconnectedPool->getPoolMetrics());
    }

    private function businessFacade(): MemoryStateFacade
    {
        return new MemoryStateFacade([
            'consumer_code' => 'business-pool-isolation-test',
            'prefer_direct_connect' => true, 'fail_fast_on_unhealthy' => true,
            'connect_timeout' => 0.05, 'timeout' => 0.05, 'pool_min_idle' => 0,
        ]);
    }

    private function pool(MemoryStateFacade $facade): ConnectionPoolManager
    {
        $client = (new \ReflectionProperty(MemoryStateFacade::class, 'stateClient'))->getValue($facade);
        return (new \ReflectionProperty(SharedStateClient::class, 'pool'))->getValue($client);
    }

    private function timeouts(ConnectionPoolManager $pool): array
    {
        $options = (new \ReflectionProperty(ConnectionPoolManager::class, 'options'))->getValue($pool);
        return [$options['connect_timeout'], $options['timeout']];
    }
}
