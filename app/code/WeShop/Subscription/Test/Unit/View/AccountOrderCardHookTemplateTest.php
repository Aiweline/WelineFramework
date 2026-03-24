<?php

declare(strict_types=1);

namespace WeShop\Subscription\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class AccountOrderCardHookTemplateTest extends TestCase
{
    public function testAccountOrderCardReadsHostDataInsteadOfSessionObjectManager(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../view/hooks/WeShop_Customer/frontend/account/orders/cards.phtml');

        $this->assertIsString($template);
        $this->assertStringContainsString("getData('subscription_count')", $template);
        $this->assertStringNotContainsString('SessionFactory', $template);
        $this->assertStringNotContainsString('ObjectManager', $template);
    }
}
