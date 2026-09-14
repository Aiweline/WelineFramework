<?php

declare(strict_types=1);

namespace Weline\Smtp\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 默认邮件正文片段与固定壳分离契约。
 */
final class MailTemplateBeautyContractTest extends TestCase
{
    public function testCustomerFacingTemplatesHaveBrandShellAndCta(): void
    {
        $root = dirname(__DIR__, 6);
        $shell = (string)file_get_contents($root . '/app/code/Weline/Smtp/view/email/shell.phtml');
        self::assertStringContainsString('{{var.brand_header_bg}}', $shell);
        self::assertStringContainsString('{{var.brand_accent}}', $shell);
        self::assertStringContainsString('{{var.brand_canvas}}', $shell);
        self::assertStringNotContainsString('#16333f', $shell);
        self::assertStringNotContainsString('#e8a14a', $shell);
        self::assertStringContainsString('{{var.site_logo_img|raw}}', $shell);
        self::assertStringContainsString('{{var.contact_email}}', $shell);
        self::assertStringContainsString('{{MAIL_BODY}}', $shell);
        self::assertStringContainsString('<lang>需要帮助？</lang>', $shell);

        $files = [
            $root . '/app/code/Weline/Customer/view/email/password_reset/zh_Hans_CN.html',
            $root . '/app/code/Weline/CustomerService/view/email/email_binding/zh_Hans_CN.html',
            $root . '/app/code/Weline/Dropship/view/email/fulfillment_consolation/zh_Hans_CN.html',
            $root . '/app/code/Weline/Order/view/email/order_created/zh_Hans_CN.html',
            $root . '/app/code/Weline/Backend/view/email/notification/zh_Hans_CN.html',
        ];
        foreach ($files as $file) {
            self::assertFileExists($file, $file);
            $html = (string)file_get_contents($file);
            self::assertStringNotContainsString('<!DOCTYPE', $html, $file);
            self::assertStringNotContainsString('site_logo_img', $html, $file);
            self::assertStringNotContainsString('<script', strtolower($html), $file);
        }
        $reset = (string)file_get_contents($files[0]);
        self::assertStringContainsString('{{var.reset_url}}', $reset);
        self::assertStringContainsString('立即重置密码', $reset);
        self::assertStringContainsString('text-decoration:none', $reset);
    }
}
