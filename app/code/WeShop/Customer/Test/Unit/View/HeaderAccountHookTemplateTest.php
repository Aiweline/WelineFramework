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

    public function testHeaderAccountHookBuildsUnpaidOrderItemsSafely(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../view/hooks/header-account.phtml');
        $this->assertIsString($template);

        $this->assertStringContainsString("\$this->getUrl('weshop/order/retry-payment'", $template);
        $this->assertStringContainsString('retryPaymentUrlTemplate.replace', $template);
        $this->assertStringContainsString('document.createElement(\'span\')', $template);
        $this->assertStringContainsString('number.textContent', $template);
        $this->assertStringContainsString('amount.textContent', $template);
        $this->assertStringContainsString('retryLink.textContent', $template);
        $this->assertStringNotContainsString('orderItem.innerHTML', $template);
        $this->assertStringNotContainsString('$frontendUrl ?>weshop', $template);
        $this->assertStringNotContainsString('pagebuilder/frontend/page/viewweshop', $template);
    }
}
