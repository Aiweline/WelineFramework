<?php

declare(strict_types=1);

namespace WeShop\Customer\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Session\CustomerSession;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;

class CustomerSessionTest extends TestCase
{
    public function testGetUserIdReusesFrontendSessionAcrossCalls(): void
    {
        $frontendSession = $this->createMock(AuthenticatedSessionInterface::class);
        $frontendSession->expects($this->exactly(2))
            ->method('getUserId')
            ->willReturn(35);

        $sessionFactory = $this->getMockBuilder(SessionFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['createFrontendSession'])
            ->getMock();
        $sessionFactory->expects($this->once())
            ->method('createFrontendSession')
            ->willReturn($frontendSession);

        $customerSession = new CustomerSession($sessionFactory);

        $this->assertSame(35, $customerSession->getUserId());
        $this->assertSame(35, $customerSession->getUserId());
    }
}
