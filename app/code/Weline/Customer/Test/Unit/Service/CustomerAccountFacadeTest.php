<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Customer\Model\Customer;
use Weline\Customer\Service\CustomerAccountFacade;
use Weline\Customer\Service\CustomerAccountService;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;

final class CustomerAccountFacadeTest extends TestCase
{
    public function testCurrentUsesTheInjectedRequestScopedFrontendSession(): void
    {
        $customer = new Customer();
        $customer->setData(Customer::schema_fields_ID, 42);
        $customer->setEmail('dealer@example.test');
        $customer->setAvatar('/dealer.png');
        $customer->setSandboxAccount(true);

        $session = $this->createMock(AuthenticatedSessionInterface::class);
        $session->expects(self::once())->method('isLoggedIn')->willReturn(true);
        $session->expects(self::once())->method('getUser')->willReturn($customer);

        $sessionFactory = $this->createMock(SessionFactory::class);
        $sessionFactory->expects(self::once())
            ->method('createFrontendSession')
            ->willReturn($session);

        $facade = new CustomerAccountFacade(
            $this->createMock(CustomerAccountService::class),
            $sessionFactory,
        );

        $identity = $facade->current();

        self::assertNotNull($identity);
        self::assertSame(42, $identity->getId());
        self::assertSame('dealer@example.test', $identity->getEmail());
        self::assertSame('/dealer.png', $identity->getAvatar());
        self::assertTrue($identity->isSandboxAccount());
    }
}
