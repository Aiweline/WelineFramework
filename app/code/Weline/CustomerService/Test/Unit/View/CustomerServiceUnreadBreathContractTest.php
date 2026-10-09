<?php

declare(strict_types=1);

namespace Weline\CustomerService\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/** 未读浮钮呼吸/绿点与标准连接生命周期契约。 */
final class CustomerServiceUnreadBreathContractTest extends TestCase
{
    public function testUnreadBadgeTogglesBreathClassAndPresenceDot(): void
    {
        $js = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/customer-service.js'
        );
        $this->assertStringContainsString('function updateUnreadBadge()', $js);
        $this->assertStringContainsString("getElementById('cs-presence-dot')", $js);
        $this->assertStringContainsString("classList.toggle('has-unread'", $js);
        $this->assertStringContainsString('presenceDot.hidden', $js);
        $this->assertStringContainsString('lastReadMessageId', $js);
        $this->assertStringContainsString('syncUnreadFromServer', $js);
        $this->assertStringContainsString('applyUnreadFromMessages', $js);
        $this->assertStringContainsString('markChatRead', $js);
        $this->assertStringContainsString('markChatReadFromServer', $js);
        $this->assertStringContainsString('getLatestSeenMessageId', $js);
        $this->assertStringContainsString('messageRowId', $js);
        $this->assertStringContainsString('savedSessionId', $js);
        $this->assertStringContainsString('点开看过 = 看完', $js);
        $this->assertStringContainsString('还没有已读水位', $js);
        $this->assertStringContainsString('水位已覆盖当前页最大 id', $js);
        $this->assertStringContainsString('禁止先把 unread=0', $js);
        $this->assertStringContainsString('unreadCount', $js);
        $this->assertStringContainsString('pollIncomingMessages', $js);
        $this->assertStringContainsString('MESSAGE_POLL_INTERVAL_MS = 15000', $js);
        $this->assertStringContainsString('cs-lifecycle-standard-poll-20261010', $js);
        $this->assertStringContainsString('stopStatusPolling', $js);
        $this->assertStringContainsString('getUnreadDebugState', $js);

        $css = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/css/customer-service.css'
        );
        $this->assertStringContainsString('weline-social-quick-bar', $css);
        $this->assertStringContainsString('2147483001', $css);
        $this->assertStringContainsString('.customer-service-widget.has-unread', $css);
        $this->assertStringContainsString('.cs-badge.is-visible', $css);
        $this->assertStringContainsString('勿把 transform 放进 transition', $css);
    }

    public function testStandardLifecycleGatesPolling(): void
    {
        $js = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/customer-service.js'
        );
        $this->assertStringContainsString('function canPoll()', $js);
        $this->assertStringContainsString('function touchExchange()', $js);
        $this->assertStringContainsString('function markConversationOpen()', $js);
        $this->assertStringContainsString('function engageAfterCustomerSend()', $js);
        $this->assertStringContainsString('function startQuietUnreadWatch()', $js);
        $this->assertStringContainsString('function bindVisibilityLifecycle()', $js);
        $this->assertStringContainsString('MESSAGE_LONG_IDLE_MS = 600000', $js);
        $this->assertStringContainsString('MESSAGE_POLL_BACKOFF_MAX_MS = 60000', $js);
        $this->assertStringContainsString('MESSAGE_HIDDEN_POLL_MS = 120000', $js);
        $this->assertStringContainsString('conversationOpen', $js);
        $this->assertStringContainsString('lastExchangeAt', $js);
        $this->assertStringContainsString('禁止「有 token 就全站密刷」', $js);
        $this->assertStringContainsString('仅未结束会话才 QuietWatch', $js);
        $this->assertStringNotContainsString('startBackgroundUnreadWatch', $js);
        $this->assertStringNotContainsString('收起后仍轮询消息', $js);
        $this->assertStringNotContainsString('}, 3000);', $js);
    }

    public function testUnreadWidgetStacksAboveSocialQuickBar(): void
    {
        $css = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/css/customer-service.css'
        );
        $this->assertStringContainsString(':has(.cs-chat-button.has-unread)', $css);
        $this->assertStringContainsString('2147483001', $css);
    }

    public function testWidgetLoadsOnlyAfterWindowLoadPlusThreeSeconds(): void
    {
        $hook = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Frontend/widgets/customer-service-float.phtml'
        );
        $this->assertStringContainsString('CS_POST_LOAD_DELAY_MS = 3000', $hook);
        $this->assertStringContainsString("addEventListener('load', scheduleCustomerServiceWidget", $hook);
        $this->assertStringContainsString('避免与正文抢', $hook);
        $this->assertStringNotContainsString('requestIdleCallback', $hook);
    }
}
