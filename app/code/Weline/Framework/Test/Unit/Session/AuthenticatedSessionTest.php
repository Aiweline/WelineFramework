<?php

declare(strict_types=1);

namespace Weline\Framework\Session\Test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Runtime\RuntimeProviderResolver;
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
    private RuntimeProviderResolver $runtimeProviders;
    private string $providerRegistryFile;

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
        $this->providerRegistryFile = \sys_get_temp_dir()
            . '/weline-auth-session-providers-'
            . \bin2hex(\random_bytes(8))
            . '.php';
        \file_put_contents($this->providerRegistryFile, '<?php return ' . \var_export([
            'format' => 1,
            'order' => ['Weline_Test'],
            'modules' => ['Weline_Test' => ['provides' => []]],
        ], true) . ';');
        $this->runtimeProviders = new RuntimeProviderResolver(
            new ServiceProviderRegistry($this->providerRegistryFile),
        );
        
        $areaConfig = AreaConfig::backend();
        $this->authSession = new AuthenticatedSession($this->session, $areaConfig, $this->runtimeProviders);
    }

    protected function tearDown(): void
    {
        $this->session->destroy();
        if (\is_file($this->providerRegistryFile)) {
            \unlink($this->providerRegistryFile);
        }
        WelineEnv::getInstance()->reset();
        if (Context::hasCurrent()) {
            Context::leave();
        }
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
        $frontendSession = new AuthenticatedSession(
            $this->session,
            $frontendConfig,
            $this->runtimeProviders,
        );
        
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

    public function testExistingSessionUsesAuthorityQualifiedCookieName(): void
    {
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
        Context::current()->set('input.server.HTTP_HOST', '127.0.0.1:9502');
        Context::current()->set('input.server.SERVER_PORT', 9502);
        Context::current()->set('input.host', '127.0.0.1');
        Context::current()->set('input.cookie', ['WELINE_SESSID_9502' => str_repeat('a', 32)]);
        Context::current()->set('input.server.HTTP_COOKIE', 'WELINE_SESSID_9502=' . str_repeat('a', 32));
        $method = new \ReflectionMethod($this->authSession, 'canReadExistingSession');

        self::assertTrue($method->invoke($this->authSession));

        Context::current()->set('input.cookie', ['WELINE_SESSID' => str_repeat('b', 32)]);
        Context::current()->set('input.server.HTTP_COOKIE', 'WELINE_SESSID=' . str_repeat('b', 32));
        self::assertFalse($method->invoke($this->authSession));
    }

    private function createMockUser(int $id, string $username): AuthenticableInterface
    {
        return new class($id, $username) implements AuthenticableInterface {
            public function __construct(
                private readonly int $id,
                private readonly string $username
            ) {
            }

            public function getAuthIdentifier(): int|string
            {
                return $this->id;
            }

            public function getAuthUsername(): string
            {
                return $this->username;
            }

            public function getAuthSessionId(): string
            {
                return '';
            }

            public static function getAuthModelClass(): string
            {
                return self::class;
            }
        };
    }
}
