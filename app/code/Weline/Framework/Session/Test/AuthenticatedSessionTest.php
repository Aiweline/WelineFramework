<?php

declare(strict_types=1);

namespace Weline\Framework\Session\Test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Session\Auth\AreaConfig;
use Weline\Framework\Session\Auth\AuthenticableInterface;
use Weline\Framework\Session\Auth\AuthenticatedSession;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\Session;
use Weline\Framework\Session\SessionInterface;
use Weline\Framework\Session\Storage\FileStorage;
use Weline\Framework\Session\Strategy\FpmStrategy;

/**
 * AuthenticatedSession 单元测试
 *
 * 测试认证 Session 的登录、登出、用户获取等功能。
 */
class AuthenticatedSessionTest extends TestCase
{
    private AuthenticatedSession $authSession;
    private SessionInterface $session;
    private string $testSessionId;

    protected function setUp(): void
    {
        $storage = new FileStorage([
            'path' => 'var/test_session/',
            'lifetime' => 3600,
        ]);
        
        $strategy = new FpmStrategy($storage, [
            'lifetime' => 3600,
        ]);
        
        $this->session = new Session($storage, $strategy, 3600);
        $this->testSessionId = 'test_auth_session_' . \bin2hex(\random_bytes(8));
        
        $areaConfig = AreaConfig::backend();
        $this->authSession = new AuthenticatedSession($this->session, $areaConfig);
    }

    protected function tearDown(): void
    {
        $this->session->destroy();
    }

    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(AuthenticatedSessionInterface::class, $this->authSession);
    }

    public function testInitiallyNotLoggedIn(): void
    {
        $this->session->start($this->testSessionId);
        
        $this->assertFalse($this->authSession->isLoggedIn());
        $this->assertNull($this->authSession->getUser());
        $this->assertNull($this->authSession->getUserId());
        $this->assertNull($this->authSession->getUsername());
    }

    public function testLogin(): void
    {
        $this->session->start($this->testSessionId);
        
        $user = $this->createMockUser(1, 'admin');
        
        $this->authSession->login($user);
        
        $this->assertTrue($this->authSession->isLoggedIn());
        $this->assertEquals(1, $this->authSession->getUserId());
        $this->assertEquals('admin', $this->authSession->getUsername());
    }

    public function testLogout(): void
    {
        $this->session->start($this->testSessionId);
        
        $user = $this->createMockUser(1, 'admin');
        $this->authSession->login($user);
        
        $this->assertTrue($this->authSession->isLoggedIn());
        
        $this->authSession->logout();
        
        $this->assertFalse($this->authSession->isLoggedIn());
        $this->assertNull($this->authSession->getUserId());
        $this->assertNull($this->authSession->getUsername());
    }

    public function testGetArea(): void
    {
        $this->assertEquals('backend', $this->authSession->getArea());
    }

    public function testIsBackend(): void
    {
        $this->assertTrue($this->authSession->isBackend());
        $this->assertFalse($this->authSession->isFrontend());
    }

    public function testGetSession(): void
    {
        $session = $this->authSession->getSession();
        
        $this->assertInstanceOf(SessionInterface::class, $session);
        $this->assertSame($this->session, $session);
    }

    public function testCompatibilityMethods(): void
    {
        $this->session->start($this->testSessionId);
        
        $this->authSession->setData('test_key', 'test_value');
        $this->assertEquals('test_value', $this->authSession->getData('test_key'));
        
        $this->authSession->delete('test_key');
        $this->assertNull($this->authSession->getData('test_key'));
        
        $user = $this->createMockUser(1, 'admin');
        $this->authSession->login($user);
        
        $this->assertTrue($this->authSession->isLogin());
        $this->assertEquals(1, $this->authSession->getLoginUserID());
        $this->assertEquals('admin', $this->authSession->getLoginUsername());
    }

    public function testFrontendAreaConfig(): void
    {
        $frontendConfig = AreaConfig::frontend();
        $frontendSession = new AuthenticatedSession($this->session, $frontendConfig);
        
        $this->assertEquals('frontend', $frontendSession->getArea());
        $this->assertTrue($frontendSession->isFrontend());
        $this->assertFalse($frontendSession->isBackend());
    }

    public function testReset(): void
    {
        $this->session->start($this->testSessionId);
        
        $user = $this->createMockUser(1, 'admin');
        $this->authSession->login($user);
        
        $this->authSession->reset();
        
        $this->assertFalse($this->session->isStarted());
    }

    private function createMockUser(int $id, string $username): AuthenticableInterface
    {
        $user = $this->createMock(AuthenticableInterface::class);
        
        $user->method('getAuthIdentifier')->willReturn($id);
        $user->method('getAuthUsername')->willReturn($username);
        $user->method('getAuthSessionId')->willReturn('');
        $user->expects($this->any())
            ->method('getAuthModelClass')
            ->willReturn(get_class($user));
        
        return $user;
    }
}
