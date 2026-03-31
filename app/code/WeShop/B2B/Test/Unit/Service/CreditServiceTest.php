<?php

declare(strict_types=1);

namespace WeShop\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\B2B\Service\CreditService;

class CreditServiceTest extends TestCase
{
    public function testGetOrCreateCreditRejectsInvalidCustomerId(): void
    {
        $service = new CreditService();

        $this->expectException(\InvalidArgumentException::class);

        $service->getOrCreateCredit(0, 100);
    }

    public function testReserveCreditRejectsNonPositiveAmount(): void
    {
        $service = new CreditService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Credit amount must be positive');

        $service->reserveCredit(10, 0);
    }

    public function testReleaseCreditRejectsNonPositiveAmount(): void
    {
        $service = new CreditService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Credit amount must be positive');

        $service->releaseCredit(10, -1);
    }
}
