<?php

declare(strict_types=1);

namespace Weline\Smtp\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Smtp\Service\MailTemplateShellComposer;

/**
 * 固定邮件壳 + 正文片段契约。
 */
final class MailTemplateShellContractTest extends TestCase
{
    public function testShellFilesExistAndBodyFragmentsOnly(): void
    {
        $smtp = dirname(__DIR__, 2);
        self::assertFileExists($smtp . '/view/email/shell/zh_Hans_CN.html');
        self::assertFileExists($smtp . '/view/email/shell/en_US.html');
        $shell = (string)file_get_contents($smtp . '/view/email/shell/zh_Hans_CN.html');
        self::assertStringContainsString('data-weline-mail-shell', $shell);
        self::assertStringContainsString('{{MAIL_BODY}}', $shell);
        self::assertStringContainsString('{{var.site_logo_img|raw}}', $shell);
        self::assertStringContainsString('{{var.brand_header_bg}}', $shell);
        self::assertStringContainsString('{{var.brand_accent}}', $shell);
        self::assertStringContainsString('{{var.contact_email}}', $shell);
        self::assertStringNotContainsString('#16333f', $shell);
        self::assertStringNotContainsString('#e8a14a', $shell);
        self::assertStringNotContainsString('<script', strtolower($shell));

        $composer = new MailTemplateShellComposer();
        $wrapped = $composer->wrap('<h1>Hello</h1><p>Body</p>', 'zh_Hans_CN', ['preheader' => 'Hello']);
        self::assertStringContainsString('data-weline-mail-shell', $wrapped);
        self::assertStringContainsString('{{var.brand_header_bg}}', $wrapped);
        self::assertStringContainsString('<h1>Hello</h1>', $wrapped);
        self::assertStringContainsString('Hello', $wrapped);

        $root = dirname(__DIR__, 6);
        $samples = [
            $root . '/app/code/Weline/Customer/view/email/password_reset/zh_Hans_CN.html',
            $root . '/app/code/Weline/Order/view/email/order_created/zh_Hans_CN.html',
            $root . '/app/code/Weline/Backend/view/email/notification/zh_Hans_CN.html',
            $root . '/app/code/Weline/CustomerService/view/email/email_binding/zh_Hans_CN.html',
            $root . '/app/code/Weline/Dropship/view/email/fulfillment_consolation/zh_Hans_CN.html',
        ];
        foreach ($samples as $file) {
            self::assertFileExists($file, $file);
            $html = (string)file_get_contents($file);
            self::assertStringNotContainsString('<!DOCTYPE', $html, $file);
            self::assertStringNotContainsString('<html', $html, $file);
            self::assertStringNotContainsString('site_logo_img', $html, $file);
            self::assertStringNotContainsString('<script', strtolower($html), $file);
        }

        $provider = (string)file_get_contents($smtp . '/extends/module/Weline_Framework/Query/SmtpQueryProvider.php');
        self::assertStringContainsString('MailTemplateShellComposer', $provider);
        self::assertStringContainsString('->wrap(', $provider);
        $seeder = (string)file_get_contents($smtp . '/Service/MailTemplateSeeder.php');
        self::assertStringContainsString('extractBodyFragment', $seeder);
        $edit = (string)file_get_contents($smtp . '/view/Backend/Template/edit.phtml');
        self::assertStringContainsString('smtp-template-shell-hint', $edit);
    }
}
