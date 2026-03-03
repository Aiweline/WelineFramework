<?php

declare(strict_types=1);

namespace Weline\Server\Test\Session;

use PHPUnit\Framework\TestCase;
use Weline\Server\Session\Backend\SessionBackendFactory;
use Weline\Server\Session\Backend\SessionBackendInterface;
use Weline\Server\Session\Backend\WlsSessionBackend;

/**
 * SessionBackendFactory 工厂类测试
 */
class SessionBackendFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        SessionBackendFactory::reset();
    }

    protected function tearDown(): void
    {
        SessionBackendFactory::reset();
    }

    /**
     * 测试创建 WLS 后端（默认）
     */
    public function testCreateWlsBackend(): void
    {
        $backend = SessionBackendFactory::create(['backend' => 'wls']);
        
        $this->assertInstanceOf(SessionBackendInterface::class, $backend);
        $this->assertInstanceOf(WlsSessionBackend::class, $backend);
    }

    /**
     * 测试后端实例缓存
     */
    public function testBackendCaching(): void
    {
        $backend1 = SessionBackendFactory::create(['backend' => 'wls']);
        $backend2 = SessionBackendFactory::create(['backend' => 'wls']);
        
        $this->assertSame($backend1, $backend2);
    }

    /**
     * 测试获取支持的后端列表
     */
    public function testGetSupportedBackends(): void
    {
        $backends = SessionBackendFactory::getSupportedBackends();
        
        $this->assertIsArray($backends);
        $this->assertContains('wls', $backends);
        $this->assertContains('redis', $backends);
        $this->assertContains('memcached', $backends);
    }

    /**
     * 测试检查后端是否支持
     */
    public function testIsSupported(): void
    {
        $this->assertTrue(SessionBackendFactory::isSupported('wls'));
        $this->assertTrue(SessionBackendFactory::isSupported('redis'));
        $this->assertTrue(SessionBackendFactory::isSupported('memcached'));
        $this->assertFalse(SessionBackendFactory::isSupported('unknown'));
    }

    /**
     * 测试重置实例
     */
    public function testReset(): void
    {
        $backend1 = SessionBackendFactory::create(['backend' => 'wls']);
        SessionBackendFactory::reset();
        $backend2 = SessionBackendFactory::create(['backend' => 'wls']);
        
        $this->assertNotSame($backend1, $backend2);
    }

    /**
     * 测试不支持的后端回退到 WLS
     */
    public function testUnsupportedBackendFallback(): void
    {
        $backend = SessionBackendFactory::create(['backend' => 'unsupported']);
        
        $this->assertInstanceOf(WlsSessionBackend::class, $backend);
    }
}
