<?php

declare(strict_types=1);

namespace WeShop\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\B2B\Service\AccountService;

class AccountServiceTest extends TestCase
{
    public function testGetOrCreateAccountRejectsInvalidCustomerId(): void
    {
        $service = new AccountService();

        $this->expectException(\InvalidArgumentException::class);

        $service->getOrCreateAccount(0);
    }
}
