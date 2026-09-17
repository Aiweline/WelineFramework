<?php

declare(strict_types=1);

namespace Weline\CustomerService\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/** 未读浮钮呼吸/绿点与 load 后延迟加载契约。 */
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
        $this->assertStringContainsString('startBackgroundUnreadWatch', $js);
        $this->assertStringContainsString('pollIncomingMessages', $js);
        $this->assertStringContainsString('stopStatusPolling', $js);
        $this->assertStringContainsString('收起后仍轮询消息', $js);
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
            dirname(__DIR__, 3) . '/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml'
        );
        $this->assertStringContainsString('CS_POST_LOAD_DELAY_MS = 3000', $hook);
        $this->assertStringContainsString("addEventListener('load', scheduleCustomerServiceWidget", $hook);
        $this->assertStringContainsString('避免与正文抢', $hook);
        $this->assertStringNotContainsString('requestIdleCallback', $hook);
    }
}
