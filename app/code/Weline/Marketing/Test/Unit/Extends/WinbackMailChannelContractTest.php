<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\Extends;

use PHPUnit\Framework\TestCase;

/**
 * Marketing 邮件渠道契约：未付催付 + 结账遗弃两渠道均须声明。
 */
final class WinbackMailChannelContractTest extends TestCase
{
    public function testDeclaresUnpaidAndCheckoutAbandonChannels(): void
    {
        $root = dirname(__DIR__, 3);
        $provider = (string)file_get_contents($root . '/extends/MailChannelProvider.php');
        self::assertStringContainsString("'code' => 'Weline_Marketing::unpaid_order_reminder'", $provider);
        self::assertStringContainsString("'code' => 'Weline_Marketing::checkout_abandon_reminder'", $provider);
        self::assertStringContainsString("MailTemplateDefaultLocales::fileEntries('unpaid_order_reminder')", $provider);
        self::assertStringContainsString("MailTemplateDefaultLocales::fileEntries('checkout_abandon_reminder')", $provider);
        self::assertStringContainsString("'code' => 'quote_token'", $provider);
        self::assertStringContainsString("'code' => 'continue_checkout_url'", $provider);
        self::assertStringContainsString("'code' => 'grand_total_minor'", $provider);
        self::assertStringContainsString("'code' => 'checkout_entry'", $provider);
        self::assertStringContainsString("'code' => 'website_id'", $provider);

        $notifier = (string)file_get_contents($root . '/Service/WinbackMailNotifier.php');
        self::assertStringContainsString("CHANNEL_CHECKOUT_ABANDON_REMINDER = 'Weline_Marketing::checkout_abandon_reminder'", $notifier);
        self::assertStringContainsString('function notifyCheckoutAbandon', $notifier);

        foreach ([
            'zh_Hans_CN', 'en_US', 'es_ES', 'fr_FR', 'pt_BR',
            'id_ID', 'hi_IN', 'bn_BD', 'ur_PK', 'ar_SA',
        ] as $locale) {
            self::assertFileExists($root . "/view/email/checkout_abandon_reminder/{$locale}.html");
            self::assertFileExists($root . "/view/email/checkout_abandon_reminder/{$locale}.subject.txt");
        }

        $en = (string)file_get_contents($root . '/view/email/checkout_abandon_reminder/en_US.html');
        self::assertStringContainsString('{{#if var.continue_checkout_url}}', $en);
        self::assertStringContainsString('{{var.customer_name}}', $en);
        self::assertStringContainsString('{{var.currency}}', $en);
        self::assertStringContainsString('{{var.grand_total}}', $en);
        self::assertStringContainsString('{{var.created_at}}', $en);

        $unpaidZh = (string)file_get_contents($root . '/view/email/unpaid_order_reminder/zh_Hans_CN.html');
        self::assertStringNotContainsString('width:38%', $unpaidZh);
        self::assertStringContainsString('width:1%', $unpaidZh);
        self::assertStringContainsString('white-space:nowrap', $unpaidZh);
        self::assertStringContainsString('resolveCreatedAtVar', $notifier);

        $upgrade = (string)file_get_contents($root . '/Setup/Upgrade.php');
        self::assertStringContainsString('MailTemplateSeeder', $upgrade);
        self::assertStringContainsString('syncAll', $upgrade);
        self::assertStringContainsString('SystemConfig::SCOPE_GLOBAL', $upgrade);
        self::assertStringContainsString('syncMailTemplatesToGlobalScope', $upgrade);
        self::assertStringContainsString("'code' => 'Weline_Marketing::cart_abandon_reminder'", $provider);
        self::assertStringContainsString("'code' => 'Weline_Marketing::welcome_customer'", $provider);
        self::assertStringContainsString("MailTemplateDefaultLocales::fileEntries('cart_abandon_reminder')", $provider);
        self::assertStringContainsString("MailTemplateDefaultLocales::fileEntries('welcome_customer')", $provider);
    }
}
