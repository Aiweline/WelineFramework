<?php

declare(strict_types=1);

namespace Weline\Smtp\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Smtp\Service\MailTemplateDefaultLocales;
use Weline\Smtp\Service\MailTemplateSeedCopyCatalog;
use Weline\Smtp\Service\MailTemplateSeedLocaleResolver;

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
        $locales = MailTemplateSeedCopyCatalog::maintainedLocales();
        $filesFor = static function (string $dir) use ($locales): array {
            $out = [];
            foreach ($locales as $locale) {
                $out[] = "view/email/{$dir}/{$locale}.html";
                $out[] = "view/email/{$dir}/{$locale}.subject.txt";
            }

            return $out;
        };

        return [
            ['Customer', 'extends/MailChannelProvider.php', $filesFor('password_reset')],
            ['CustomerService', 'extends/MailChannelProvider.php', $filesFor('email_binding')],
            ['Dropship', 'extends/MailChannelProvider.php', $filesFor('fulfillment_consolation')],
            ['Order', 'extends/MailChannelProvider.php', array_merge(
                $filesFor('order_created'),
                $filesFor('order_paid'),
                $filesFor('order_status_changed'),
                $filesFor('order_shipped'),
                $filesFor('order_refund'),
            )],
            ['Marketing', 'extends/MailChannelProvider.php', array_merge(
                $filesFor('unpaid_order_reminder'),
                $filesFor('checkout_abandon_reminder'),
            )],
            ['Backend', 'Extends/MailChannelProvider.php', $filesFor('notification')],
            ['Product', 'extends/MailChannelProvider.php', array_merge(
                $filesFor('product_update'),
                $filesFor('quote_reply'),
            )],
            ['Websites', 'extends/MailChannelProvider.php', $filesFor('notification')],
            ['Visitor', 'extends/MailChannelProvider.php', $filesFor('notification')],
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
            str_contains($src, 'MailTemplateDefaultLocales')
            || str_contains($src, 'view/email/')
            || str_contains($src, 'subject_file')
            || str_contains($src, 'body_file'),
            $module . ' should declare template file keys or MailTemplateDefaultLocales'
        );

        foreach ($requiredFiles as $rel) {
            self::assertFileExists($root . '/' . $rel, "missing {$module}/{$rel}");
        }
    }

    public function testSeedCatalogCoversDefaultWebsiteLanguages(): void
    {
        $maintained = MailTemplateSeedCopyCatalog::maintainedLocales();
        self::assertContains('zh_Hans_CN', $maintained);
        self::assertContains('en_US', $maintained);
        foreach (['ar_SA', 'bn_BD', 'es_ES', 'fr_FR', 'hi_IN', 'id_ID', 'pt_BR', 'ur_PK'] as $locale) {
            self::assertContains($locale, $maintained);
            $copy = MailTemplateSeedCopyCatalog::forSlug('order_created', $locale);
            self::assertNotNull($copy);
            self::assertNotSame('', $copy['subject'] ?? '');
            self::assertStringContainsString('{{var.order_number}}', $copy['subject']);
            self::assertStringContainsString('{{var.customer_name}}', $copy['body']);
            self::assertFileExists(dirname(__DIR__, 2) . '/view/email/shell.phtml');
        }

        $shellPhtml = (string)file_get_contents(dirname(__DIR__, 2) . '/view/email/shell.phtml');
        self::assertStringContainsString('<lang>需要帮助？</lang>', $shellPhtml);

        $resolved = MailTemplateSeedLocaleResolver::forDefaultWebsite();
        foreach (MailTemplateSeedCopyCatalog::baselineLocales() as $baseline) {
            self::assertContains($baseline, $resolved);
        }

        $entries = MailTemplateDefaultLocales::fileEntries('password_reset');
        self::assertNotEmpty($entries);
        self::assertSame('zh_Hans_CN', $entries[0]['locale'] ?? '');
    }
}
