<?php

declare(strict_types=1);

namespace Weline\Framework\Session\Test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Session\Auth\AuthenticatedSession;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\Session\SessionInterface;
use Weline\Framework\Session\Storage\FileStorage;
use Weline\Framework\Session\Storage\RedisStorage;
use Weline\Framework\Session\Storage\SessionStorageInterface;
use Weline\Framework\Session\Storage\WlsSharedStorage;
use Weline\Framework\Session\Strategy\FpmStrategy;
use Weline\Framework\Session\Strategy\SessionStrategyInterface;
use Weline\Framework\Runtime\Runtime;
use Weline\Server\Service\Runtime\RoutingPolicyRegistry;

/**
 * SessionFactory 单元测试
 *
 * 测试 Session 工厂的创建功能。
 */
class SessionFactoryTest extends TestCase
{
    private SessionFactory $factory;

    protected function setUp(): void
    {
        SessionFactory::resetAll();
        
        $this->factory = new SessionFactory([
            'default' => 'file',
            'lifetime' => 3600,
            'drivers' => [
                'file' => [
                    'path' => 'var/test_session/',
                ],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        $this->factory->resetRequestInstances();
        SessionFactory::resetAll();
        Runtime::resetModeCache();
        RoutingPolicyRegistry::clear();
    }

    public function testCreateStorage(): void
    {
        $storage = $this->factory->createStorage('file');
        
        $this->assertInstanceOf(SessionStorageInterface::class, $storage);
        $this->assertInstanceOf(FileStorage::class, $storage);
    }

    public function testCreateStorageCachesSameType(): void
    {
        $storage1 = $this->factory->createStorage('file');
        $storage2 = $this->factory->createStorage('file');
        
        $this->assertSame($storage1, $storage2);
    }

    public function testCreateStrategy(): void
    {
        $strategy = $this->factory->createStrategy();
        
        $this->assertInstanceOf(SessionStrategyInterface::class, $strategy);
    }

    public function testCreateSession(): void
    {
        $session = $this->factory->createSession();
        
        $this->assertInstanceOf(SessionInterface::class, $session);
    }

    public function testCreateSessionReturnsSameInstance(): void
    {
        $session1 = $this->factory->createSession();
        $session2 = $this->factory->createSession();
        
        $this->assertSame($session1, $session2);
    }

    public function testCreateBackendSession(): void
    {
        $session = $this->factory->createBackendSession();
        
        $this->assertInstanceOf(AuthenticatedSessionInterface::class, $session);
        $this->assertEquals('backend', $session->getArea());
    }

    public function testCreateFrontendSession(): void
    {
        $session = $this->factory->createFrontendSession();
        
        $this->assertInstanceOf(AuthenticatedSessionInterface::class, $session);
        $this->assertEquals('frontend', $session->getArea());
    }

    public function testCreateApiSession(): void
    {
        $session = $this->factory->createApiSession();
        
        $this->assertInstanceOf(AuthenticatedSessionInterface::class, $session);
        $this->assertEquals('api', $session->getArea());
    }

    public function testRestBackendSessionUsesBackendCredentialKeys(): void
    {
        $session = $this->factory->createAuthenticatedSession('rest_backend');

        $this->assertInstanceOf(AuthenticatedSession::class, $session);
        $this->assertEquals('rest_backend', $session->getArea());
        $this->assertSame('WF_BACKEND_USER', $session->getAreaConfig()->getLoginKey());
        $this->assertSame('WF_BACKEND_USER_ID', $session->getAreaConfig()->getLoginIdKey());
        $this->assertSame('WF_BACKEND_USER_MODEL', $session->getAreaConfig()->getUserModelKey());
    }

    public function testResetRequestInstances(): void
    {
        $session1 = $this->factory->createSession();
        
        $this->factory->resetRequestInstances();
        
        $session2 = $this->factory->createSession();
        
        $this->assertNotSame($session1, $session2);
    }

    public function testStaticGetInstance(): void
    {
        $instance1 = SessionFactory::getInstance();
        $instance2 = SessionFactory::getInstance();
        
        $this->assertSame($instance1, $instance2);
    }

    public function testStaticSessionMethod(): void
    {
        $session = SessionFactory::session();
        
        $this->assertInstanceOf(SessionInterface::class, $session);
    }

    public function testStaticBackendMethod(): void
    {
        $session = SessionFactory::backend();
        
        $this->assertInstanceOf(AuthenticatedSessionInterface::class, $session);
        $this->assertEquals('backend', $session->getArea());
    }

    public function testStaticFrontendMethod(): void
    {
        $session = SessionFactory::frontend();
        
        $this->assertInstanceOf(AuthenticatedSessionInterface::class, $session);
        $this->assertEquals('frontend', $session->getArea());
    }

    public function testGetConfig(): void
    {
        $config = $this->factory->getConfig();
        
        $this->assertIsArray($config);
        $this->assertEquals('file', $config['default']);
        $this->assertEquals(3600, $config['lifetime']);
    }

    public function testWlsModeHijacksFileDefaultToWlsStorage(): void
    {
        Runtime::setMode('wls');
        RoutingPolicyRegistry::clear();

        $factory = new SessionFactory([
            'default' => 'file',
            'drivers' => [
                'file' => ['path' => 'var/test_session/'],
            ],
        ]);

        $storage = $factory->createStorage();
        $this->assertInstanceOf(WlsSharedStorage::class, $storage);
    }

    public function testWlsModeKeepsRedisDefaultUnchanged(): void
    {
        Runtime::setMode('wls');
        RoutingPolicyRegistry::clear();

        $factory = new SessionFactory([
            'default' => 'redis',
            'drivers' => [
                'redis' => [
                    'host' => '127.0.0.1',
                    'port' => 6379,
                ],
            ],
        ]);

        $storage = $factory->createStorage();
        $this->assertInstanceOf(RedisStorage::class, $storage);
    }

    public function testWlsModeExplicitFileStorageIsHijackedToWls(): void
    {
        Runtime::setMode('wls');
        RoutingPolicyRegistry::clear();

        $factory = new SessionFactory([
            'default' => 'redis',
            'drivers' => [
                'file' => ['path' => 'var/test_session/'],
            ],
        ]);

        $storage = $factory->createStorage('file');
        $this->assertInstanceOf(WlsSharedStorage::class, $storage);
    }
}
