<?php

declare(strict_types=1);

namespace Weline\Smtp\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ch5：调用方迁移 + Topic 展开 + Order 薄服务 + 默认模板种子契约。
 */
final class MailTemplateMigrateContractTest extends TestCase
{
    private function repoRoot(): string
    {
        return dirname(__DIR__, 6);
    }

    public function testBackendProviderExpandsTopicsWithSharedDefaults(): void
    {
        $root = $this->repoRoot();
        $src = (string)file_get_contents($root . '/app/code/Weline/Backend/Extends/MailChannelProvider.php');
        self::assertStringContainsString('notification_email', $src);
        self::assertStringContainsString('default_templates', $src);
        self::assertStringContainsString('collectTopicRows', $src);
        self::assertStringContainsString('MailTemplateDefaultLocales', $src);
        self::assertFileExists($root . '/app/code/Weline/Backend/view/email/notification/zh_Hans_CN.html');
        self::assertFileExists($root . '/app/code/Weline/Backend/view/email/notification/fr_FR.html');
        self::assertFileExists($root . '/app/code/Weline/Backend/view/email/notification/ar_SA.html');
    }

    public function testEmailAdapterKeepsChannelAndUsesVars(): void
    {
        $src = (string)file_get_contents($this->repoRoot() . '/app/code/Weline/Backend/Adapter/Notification/EmailAdapter.php');
        self::assertStringContainsString("'channel' => \$channel", $src);
        self::assertStringContainsString("'vars' =>", $src);
        self::assertStringNotContainsString('unset($params[\'channel\'])', $src);
        self::assertStringContainsString('notify_', $src);
        self::assertStringContainsString('notification_email', $src);
    }

    public function testOrderMailNotifierAndObservers(): void
    {
        $root = $this->repoRoot();
        self::assertFileExists($root . '/app/code/Weline/Order/Service/OrderMailNotifier.php');
        $notifier = (string)file_get_contents($root . '/app/code/Weline/Order/Service/OrderMailNotifier.php');
        self::assertStringContainsString('website_code', $notifier);
        self::assertStringContainsString('locale', $notifier);
        self::assertStringContainsString('notify_customer', $notifier);
        self::assertStringContainsString('CHANNEL_SHIPPED', $notifier);
        self::assertStringContainsString('CHANNEL_REFUND', $notifier);

        foreach ([
            'OrderCreatedObserver',
            'OrderPaidObserver',
            'OrderStatusChangedObserver',
            'OrderShippedObserver',
            'OrderRefundedObserver',
        ] as $cls) {
            $path = $root . '/app/code/Weline/Order/Observer/' . $cls . '.php';
            self::assertFileExists($path);
            self::assertStringContainsString('OrderMailNotifier', (string)file_get_contents($path));
        }
    }

    public function testDebtCallersUseChannelAndVars(): void
    {
        $root = $this->repoRoot();
        $customer = (string)file_get_contents($root . '/app/code/Weline/Customer/Service/PasswordResetService.php');
        self::assertStringContainsString('Weline_Customer::password_reset', $customer);
        self::assertStringContainsString("'vars'", $customer);

        $binding = (string)file_get_contents($root . '/app/code/Weline/CustomerService/Service/EmailBindingService.php');
        self::assertStringContainsString('Weline_CustomerService::email_binding', $binding);
        self::assertStringContainsString("'vars'", $binding);

        $dropship = (string)file_get_contents($root . '/app/code/Weline/Dropship/Service/DropshipPushTerminalCompensationService.php');
        self::assertStringContainsString('Weline_Dropship::fulfillment_consolation', $dropship);
        self::assertStringContainsString('website_code', $dropship);
        self::assertStringContainsString("'vars'", $dropship);
    }

    public function testModuleDefaultTemplateFilesExist(): void
    {
        $root = $this->repoRoot();
        $files = [
            '/app/code/Weline/Order/view/email/order_created/zh_Hans_CN.html',
            '/app/code/Weline/Order/view/email/order_paid/en_US.html',
            '/app/code/Weline/Order/view/email/order_shipped/zh_Hans_CN.subject.txt',
            '/app/code/Weline/Order/view/email/order_refund/en_US.subject.txt',
            '/app/code/Weline/Product/view/email/product_update/zh_Hans_CN.html',
            '/app/code/Weline/Product/view/email/quote_reply/en_US.html',
            '/app/code/Weline/Websites/view/email/notification/zh_Hans_CN.html',
            '/app/code/Weline/Visitor/view/email/notification/en_US.html',
            '/app/code/Weline/Dropship/view/email/fulfillment_consolation/zh_Hans_CN.html',
            '/app/code/Weline/Customer/view/email/password_reset/zh_Hans_CN.html',
            '/app/code/Weline/CustomerService/view/email/email_binding/zh_Hans_CN.html',
        ];
        foreach ($files as $rel) {
            self::assertFileExists($root . $rel, $rel);
        }
    }

    public function testProvidersDeclareDefaultTemplates(): void
    {
        $root = $this->repoRoot();
        foreach ([
            'Order',
            'Product',
            'Websites',
            'Visitor',
            'Dropship',
            'Customer',
            'CustomerService',
        ] as $mod) {
            $path = $root . '/app/code/Weline/' . $mod . '/extends/MailChannelProvider.php';
            if (!is_file($path)) {
                $path = $root . '/app/code/Weline/' . $mod . '/Extends/MailChannelProvider.php';
            }
            self::assertFileExists($path, $mod);
            self::assertStringContainsString('default_templates', (string)file_get_contents($path), $mod);
        }
    }

    public function testUpgradeSeedsMailTemplates(): void
    {
        $root = $this->repoRoot();
        self::assertFileExists($root . '/app/code/Weline/Smtp/Setup/Upgrade.php');
        $src = (string)file_get_contents($root . '/app/code/Weline/Smtp/Setup/Upgrade.php');
        self::assertStringContainsString('MailTemplateSeeder', $src);
        self::assertStringContainsString('syncAll', $src);
        self::assertStringContainsString('MailTemplateSeedCopyCatalog', $src);
        self::assertStringContainsString('materializeFiles', $src);
        $module = include $root . '/app/code/Weline/Smtp/etc/module.php';
        self::assertIsArray($module);
        self::assertTrue(
            version_compare((string)($module['version'] ?? '0'), '1.4.25', '>='),
            'Smtp module must be >= 1.4.25 for mail template seed upgrade'
        );
    }
}
