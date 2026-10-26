<?php

declare(strict_types=1);

namespace Weline\Affiliate\Test\Unit\Controller\Frontend\Affiliate;

use PHPUnit\Framework\TestCase;
use Weline\Affiliate\Controller\Frontend\Affiliate\Index;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;

class IndexTest extends TestCase
{
    public function testIndexRedirectsGuestCustomersToLogin(): void
    {
        $session = $this->createMock(AuthenticatedSessionInterface::class);
        $session->expects($this->once())
            ->method('getUserId')
            ->willReturn(null);

        $controller = $this->getMockBuilder(Index::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['assign', 'fetch', 'redirect', 'getUrl'])
            ->getMock();
        $this->setProtectedProperty($controller, 'session', $session);
        $this->setProtectedProperty($controller, '_objectManager', ObjectManager::getInstance());

        $controller->expects($this->exactly(2))
            ->method('getUrl')
            ->willReturnCallback(static function (string $path, array $params = []): string {
                if ($path === 'customer/account/index') {
                    return '/customer/account/index';
                }
                if ($path === 'customer/account/login') {
                    return '/customer/account/login?redirect_url='
                        . rawurlencode((string) ($params['redirect_url'] ?? ''));
                }

                return '/' . ltrim($path, '/');
            });

        $controller->expects($this->once())
            ->method('redirect')
            ->with('/customer/account/login?redirect_url='
                . rawurlencode('/customer/account/index#affiliate'));
        $controller->expects($this->never())->method('assign');
        $controller->expects($this->never())->method('fetch');

        $this->assertSame('', $controller->index());
    }

    public function testIndexRedirectsLoggedInCustomerToAccountAffiliateSection(): void
    {
        $session = $this->createMock(AuthenticatedSessionInterface::class);
        $session->expects($this->once())
            ->method('getUserId')
            ->willReturn(33);

        $controller = $this->getMockBuilder(Index::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['assign', 'fetch', 'redirect', 'getUrl'])
            ->getMock();
        $this->setProtectedProperty($controller, 'session', $session);

        $controller->expects($this->once())
            ->method('getUrl')
            ->with('customer/account/index')
            ->willReturn('/customer/account/index');
        $controller->expects($this->once())
            ->method('redirect')
            ->with('/customer/account/index#affiliate');
        $controller->expects($this->never())->method('assign');
        $controller->expects($this->never())->method('fetch');

        $this->assertSame('', $controller->index());
    }

    private function setProtectedProperty(object $target, string $property, mixed $value): void
    {
        $reflection = new \ReflectionObject($target);
        while (!$reflection->hasProperty($property) && ($reflection = $reflection->getParentClass())) {
        }

        $reflectionProperty = $reflection->getProperty($property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($target, $value);
    }
}
