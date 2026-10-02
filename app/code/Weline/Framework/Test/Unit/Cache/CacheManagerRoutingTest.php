<?php
declare(strict_types=1);

namespace Weline\Framework\Cache\test;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Runtime\Runtime;
use Weline\Server\Service\Runtime\RoutingPolicyRegistry;

class CacheManagerRoutingTest extends TestCase
{
    protected function tearDown(): void
    {
        Runtime::resetModeCache();
        RoutingPolicyRegistry::clear();
    }

    public function testWlsModeHijacksFileDriverToWlsMemory(): void
    {
        Runtime::setMode('wls');
        RoutingPolicyRegistry::clear();

        $manager = new CacheManager();
        $driver = $this->invokeResolveDriver(
            $manager,
            ['default' => 'file'],
            ['driver' => 'file']
        );

        $this->assertSame('wls_memory', $driver);
    }

    public function testWlsModeKeepsNonFileDriverUnchanged(): void
    {
        Runtime::setMode('wls');
        RoutingPolicyRegistry::clear();

        $manager = new CacheManager();
        $driver = $this->invokeResolveDriver(
            $manager,
            ['default' => 'file'],
            ['driver' => 'redis']
        );

        $this->assertSame('redis', $driver);
    }

    /**
     * FPC 索引所在的池必须豁免 file→wls_memory 劫持。
     *
     * 生产实测：router 池被劫持到 WlsMemoryAdapter 后，其 set() 返回 false
     * （内存 sidecar 不可用），条目根本不会落库；26/26 次查找 found=false，
     * 每个请求都退化为整页 SSR（action_execute_ms 中位 8s、P90 42s），
     * 而 externalizeSharedPayload 仍持续写正文文件，使 var/cache 涨到 6.2G 孤儿。
     */
    public function testFpcBearingPoolsAreExemptFromVolatileMemoryHijack(): void
    {
        Runtime::setMode('wls');
        RoutingPolicyRegistry::clear();

        $manager = new CacheManager();
        $ref = new ReflectionClass($manager);

        $configProp = $ref->getProperty('config');
        $configProp->setAccessible(true);
        $configProp->setValue($manager, ['default' => 'file']);

        $getPoolConfig = $ref->getMethod('getPoolConfig');
        $getPoolConfig->setAccessible(true);
        $resolveDriver = $ref->getMethod('resolveDriver');
        $resolveDriver->setAccessible(true);

        foreach (['router', 'fpc', 'single_flight'] as $identity) {
            $poolConfig = (array)$getPoolConfig->invoke($manager, $identity);

            self::assertTrue(
                !empty($poolConfig['hijack_exempt']) || !empty($poolConfig['durable']),
                $identity . ' 池必须声明 hijack_exempt 或 durable',
            );
            self::assertSame(
                'file',
                $resolveDriver->invoke($manager, $identity, $poolConfig),
                $identity . ' 池不得被劫持到易失的 wls_memory：'
                . '内存 sidecar 抖动时 set() 返回 false，条目不会落库，查找永远落空。',
            );
        }

        // 对照：普通 file 池仍应被劫持，既有策略不得被放宽。
        self::assertSame(
            'wls_memory',
            $resolveDriver->invoke($manager, 'default', ['driver' => 'file']),
        );
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $poolConfig
     */
    private function invokeResolveDriver(CacheManager $manager, array $globalConfig, array $poolConfig): string
    {
        $ref = new ReflectionClass($manager);

        $configProp = $ref->getProperty('config');
        $configProp->setAccessible(true);
        $configProp->setValue($manager, $globalConfig);

        $method = $ref->getMethod('resolveDriver');
        $method->setAccessible(true);

        /** @var string $driver */
        $driver = $method->invoke($manager, 'default', $poolConfig);
        return $driver;
    }
}

