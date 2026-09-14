<?php

declare(strict_types=1);

namespace Weline\Smtp\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 各业务模块 MailChannelProvider 须声明 default_templates 且模板文件存在。
 */
final class MailChannelDefaultTemplatesContractTest extends TestCase
{
    /**
     * @return list<array{0:string,1:string,2:list<string>}>
     */
    public static function providerModules(): array
    {
        return [
            ['Customer', 'extends/MailChannelProvider.php', [
                'view/email/password_reset/zh_Hans_CN.html',
                'view/email/password_reset/en_US.html',
            ]],
            ['CustomerService', 'extends/MailChannelProvider.php', [
                'view/email/email_binding/zh_Hans_CN.html',
            ]],
            ['Dropship', 'extends/MailChannelProvider.php', [
                'view/email/fulfillment_consolation/zh_Hans_CN.html',
            ]],
            ['Order', 'extends/MailChannelProvider.php', [
                'view/email/order_created/zh_Hans_CN.html',
                'view/email/order_paid/zh_Hans_CN.html',
                'view/email/order_status_changed/zh_Hans_CN.html',
                'view/email/order_shipped/zh_Hans_CN.html',
                'view/email/order_refund/zh_Hans_CN.html',
            ]],
            ['Backend', 'Extends/MailChannelProvider.php', [
                'view/email/notification/zh_Hans_CN.html',
            ]],
            ['Product', 'extends/MailChannelProvider.php', [
                'view/email/product_update/zh_Hans_CN.html',
                'view/email/quote_reply/zh_Hans_CN.html',
            ]],
            ['Websites', 'extends/MailChannelProvider.php', [
                'view/email/notification/zh_Hans_CN.html',
            ]],
            ['Visitor', 'extends/MailChannelProvider.php', [
                'view/email/notification/zh_Hans_CN.html',
            ]],
        ];
    }

    /** @dataProvider providerModules */
    public function testProviderDeclaresDefaultTemplatesAndFilesExist(
        string $module,
        string $relProvider,
        array $requiredFiles,
    ): void {
        $root = dirname(__DIR__, 3) . '/' . $module;
        $providerPath = $root . '/' . $relProvider;
        self::assertFileExists($providerPath);
        $src = (string)file_get_contents($providerPath);
        self::assertStringContainsString("'default_templates'", $src);
        self::assertStringContainsString("'variables'", $src);
        self::assertTrue(
            str_contains($src, 'view/email/')
            || str_contains($src, 'subject_file')
            || str_contains($src, 'body_file'),
            $module . ' should declare template file keys'
        );

        foreach ($requiredFiles as $rel) {
            self::assertFileExists($root . '/' . $rel, "missing {$module}/{$rel}");
        }
    }
}
