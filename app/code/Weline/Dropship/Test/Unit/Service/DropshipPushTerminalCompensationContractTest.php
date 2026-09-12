<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/** Contract: 行级 Coordinator 退款、禁 order_refunded、安慰邮件在退款 ok 后。 */
final class DropshipPushTerminalCompensationContractTest extends TestCase
{
    public function testCompensationUsesCoordinatorAndNeverOrderRefunded(): void
    {
        $root = dirname(__DIR__, 3);
        $svc = (string)file_get_contents($root . '/Service/DropshipPushTerminalCompensationService.php');
        $obs = (string)file_get_contents($root . '/Observer/DropshipPushTerminalRefundObserver.php');
        $eventXml = (string)file_get_contents($root . '/etc/event.xml');
        $mail = (string)file_get_contents($root . '/extends/MailChannelProvider.php');
        $extends = (string)file_get_contents($root . '/extends.php');

        self::assertStringContainsString('OrderRefundCoordinator', $svc);
        self::assertStringContainsString('requestRefund', $svc);
        self::assertStringContainsString('dropship:auto-refund:', $svc);
        self::assertStringContainsString('item_uuid', $svc);
        self::assertStringContainsString('qty_minor', $svc);
        self::assertStringContainsString('local_offer_id', $svc);
        self::assertStringContainsString('shippingRefundMinor', $svc);
        self::assertStringContainsString('compensation', $svc);
        self::assertStringContainsString('switch_off', $svc);
        self::assertStringContainsString('refunded', $svc);
        self::assertStringContainsString('refund_failed', $svc);
        self::assertStringContainsString('mail_sent_at', $svc);
        self::assertStringContainsString('fulfillment_consolation', $svc);
        self::assertStringContainsString('已启动退款', $svc);
        self::assertStringNotContainsString('order_refunded', $svc);
        self::assertStringNotContainsString('RefundService', $svc);

        self::assertStringContainsString('DropshipPushTerminalCompensationService', $obs);
        self::assertStringContainsString("\$action !== 'create'", $svc);

        self::assertStringContainsString('Weline_Dropship::push_terminal', $eventXml);
        self::assertStringContainsString('DropshipPushTerminalRefundObserver', $eventXml);

        self::assertStringContainsString('Weline_Dropship::fulfillment_consolation', $mail);
        self::assertStringContainsString('MailChannelProviderInterface', $extends);
    }

    public function testSettingsSwitchDefaultOn(): void
    {
        $root = dirname(__DIR__, 3);
        $settings = (string)file_get_contents($root . '/Service/DropshipSettings.php');
        $decl = (string)file_get_contents($root . '/extends/module/Weline_SystemConfig/Config/frontend/dropship-settings.phtml');

        self::assertStringContainsString('KEY_AUTO_REFUND_ON_PUSH_FAIL', $settings);
        self::assertStringContainsString('dropship/ops/auto_refund_on_push_fail', $settings);
        self::assertStringContainsString('isAutoRefundOnPushFail', $settings);
        self::assertMatchesRegularExpression(
            '/key="dropship\/ops\/auto_refund_on_push_fail"[^>]*default="1"/',
            $decl
        );
    }
}
