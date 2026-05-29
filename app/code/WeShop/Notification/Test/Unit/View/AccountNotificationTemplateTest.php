<?php

declare(strict_types=1);

namespace WeShop\Notification\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class AccountNotificationTemplateTest extends TestCase
{
    public function testAccountSidebarUsesMessageNotificationLabel(): void
    {
        $template = $this->readModuleFile('view/hooks/account.sidebar.phtml');
        $headerTemplate = $this->readModuleFile('view/hooks/header-account-links.phtml');

        $this->assertStringContainsString("__('消息通知')", $template);
        $this->assertStringContainsString("__('评价回复、订单与账户提醒')", $template);
        $this->assertStringContainsString("__('消息通知')", $headerTemplate);
        $this->assertStringContainsString('#notification-preferences', $template);
        $this->assertStringContainsString('#notification-preferences', $headerTemplate);
        $this->assertStringNotContainsString("__('通知设置')", $template);
        $this->assertStringNotContainsString("__('通知设置')", $headerTemplate);
        $this->assertStringNotContainsString('/notification/frontend/notification', $template);
        $this->assertStringNotContainsString('/notification/frontend/notification', $headerTemplate);
    }

    public function testAccountNotificationListUsesLocalizedTypeLabel(): void
    {
        $template = $this->readModuleFile('view/hooks/account.sidebar.content.phtml');

        $this->assertStringContainsString('id="notification-preferences"', $template);
        $this->assertStringContainsString('data-account-section="notification-preferences"', $template);
        $this->assertStringContainsString("\$typeLabel = (string) (\$item['type_label'] ?? \$item['type'] ?? 'info');", $template);
        $this->assertStringContainsString('htmlspecialchars($typeLabel, ENT_QUOTES, \'UTF-8\')', $template);
        $this->assertStringNotContainsString("message-type\"><?= htmlspecialchars((string) (\$item['type'] ?? 'info')", $template);
        $this->assertStringContainsString('payload.data.redirect_url', $template);
    }

    public function testDiscoveryCardLinksToAccountCenterSection(): void
    {
        $template = $this->readModuleFile('view/hooks/WeShop_Customer/frontend/account/discovery/cards.phtml');

        $this->assertStringContainsString("\$this->getUrl('customer/account/index#notification-preferences')", $template);
        $this->assertStringContainsString("__('消息集中在个人中心')", $template);
        $this->assertStringContainsString("__('评价回复、订单状态和会员消息都在个人中心统一查看。')", $template);
        $this->assertStringNotContainsString("\$this->getUrl('notification')", $template);
    }

    private function readModuleFile(string $relativePath): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relativePath;
        $content = file_get_contents($path);
        $this->assertIsString($content);

        return $content;
    }
}
