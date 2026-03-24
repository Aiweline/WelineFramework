<?php

declare(strict_types=1);

namespace WeShop\B2B\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class AccountOrdersCardEmailLookupTest extends TestCase
{
    public function testAccountOrdersCardPrefersCustomerEmail(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../view/hooks/WeShop_Customer/frontend/account/orders/cards.phtml');

        $this->assertIsString($template);
        $this->assertStringContainsString('getEmail()', $template);
        $this->assertStringContainsString('getUsername()', $template);
    }
}
