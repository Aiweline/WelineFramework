<?php

declare(strict_types=1);

namespace WeShop\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class HeaderAccountHookTemplateTest extends TestCase
{
    public function testHeaderAccountHookUsesGeneratedOrderApiRoutesForUnpaidRequests(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../view/hooks/header-account.phtml');
        $this->assertIsString($template);

        $this->assertStringContainsString("getApiUrl('weshop_order/rest/v1/order/unpaid-count'", $template);
        $this->assertStringContainsString("getApiUrl('weshop_order/rest/v1/order/unpaid-list'", $template);
        $this->assertStringNotContainsString('api/rest/v1/weshop_order/order/unpaid-count', $template);
        $this->assertStringNotContainsString('api/rest/v1/weshop_order/order/unpaid-list', $template);
    }
}
