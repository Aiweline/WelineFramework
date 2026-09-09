<?php

declare(strict_types=1);

namespace Weline\CustomerService\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\CustomerService\Model\ChatMessage;
use Weline\CustomerService\Service\ChatService;

final class GuestSendGateContractTest extends TestCase
{
    public function testAwaitingAgentReplyWhenNewestIsCustomer(): void
    {
        $this->assertFalse(ChatService::isAwaitingAgentReplyFromSenders([]));
        $this->assertTrue(ChatService::isAwaitingAgentReplyFromSenders([
            ChatMessage::SENDER_TYPE_CUSTOMER,
        ]));
        $this->assertFalse(ChatService::isAwaitingAgentReplyFromSenders([
            ChatMessage::SENDER_TYPE_AGENT,
            ChatMessage::SENDER_TYPE_CUSTOMER,
        ]));
        $this->assertTrue(ChatService::isAwaitingAgentReplyFromSenders([
            ChatMessage::SENDER_TYPE_CUSTOMER,
            ChatMessage::SENDER_TYPE_AGENT,
        ]));
    }

    public function testGuestSendGateSurfaceInServiceAndProviders(): void
    {
        $serviceFile = dirname(__DIR__, 3) . '/Service/ChatService.php';
        $providerFile = dirname(__DIR__, 3)
            . '/extends/module/Weline_Framework/Query/CustomerServiceQueryProvider.php';
        $chatController = dirname(__DIR__, 3) . '/Controller/Frontend/Chat.php';
        $widgetJs = dirname(__DIR__, 3) . '/view/statics/js/customer-service.js';
        $bodyEnd = dirname(__DIR__, 3)
            . '/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml';

        $service = (string)file_get_contents($serviceFile);
        $provider = (string)file_get_contents($providerFile);
        $controller = (string)file_get_contents($chatController);
        $js = (string)file_get_contents($widgetJs);
        $hook = (string)file_get_contents($bodyEnd);

        $this->assertStringContainsString('resolveGuestSendGate', $service);
        $this->assertStringContainsString('assertCustomerMaySend', $service);
        $this->assertStringContainsString('isSessionEmailBound', $service);
        $this->assertStringContainsString('guest_send', $provider);
        $this->assertStringContainsString('guest_send_locked', $provider);
        $this->assertStringContainsString('assertCustomerMaySend', $controller);
        $this->assertStringContainsString('applyGuestSendGate', $js);
        $this->assertStringContainsString('cs-guest-send-gate', $hook);
        $this->assertStringContainsString('data-cs-open-bind-email', $hook);
        $this->assertStringContainsString('instanceof ServiceAgent', $service);
    }
}
