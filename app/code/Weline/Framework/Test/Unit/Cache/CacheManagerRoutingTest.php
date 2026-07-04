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

