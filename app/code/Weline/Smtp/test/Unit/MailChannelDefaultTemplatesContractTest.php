<?php

declare(strict_types=1);

namespace Weline\Smtp\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Smtp\Service\MailTemplateDefaultLocales;
use Weline\Smtp\Service\MailTemplateSeedCopyCatalog;
use Weline\Smtp\Service\MailTemplateSeedLocaleResolver;

/**
 * 各业务模块 MailChannelProvider 须声明 default_templates；
 * 磁盘仅保留 zh_Hans_CN 实体；其它语种由种子包内联。
 */
final class MailChannelDefaultTemplatesContractTest extends TestCase
{
    /**
     * @return list<array{0:string,1:string,2:list<string>}>
     */
    public static function providerModules(): array
    {
        $zhOnly = static function (string $dir): array {
            return [
                "view/email/{$dir}/zh_Hans_CN.html",
                "view/email/{$dir}/zh_Hans_CN.subject.txt",
            ];
        };

        return [
            ['Customer', 'extends/MailChannelProvider.php', $zhOnly('password_reset')],
            ['CustomerService', 'extends/MailChannelProvider.php', $zhOnly('email_binding')],
            ['Dropship', 'extends/MailChannelProvider.php', $zhOnly('fulfillment_consolation')],
            ['Order', 'extends/MailChannelProvider.php', array_merge(
                $zhOnly('order_created'),
                $zhOnly('order_paid'),
                $zhOnly('order_status_changed'),
                $zhOnly('order_shipped'),
                $zhOnly('order_refund'),
            )],
            ['Marketing', 'extends/MailChannelProvider.php', array_merge(
                $zhOnly('unpaid_order_reminder'),
                $zhOnly('checkout_abandon_reminder'),
            )],
            ['Backend', 'Extends/MailChannelProvider.php', $zhOnly('notification')],
            ['Product', 'extends/MailChannelProvider.php', array_merge(
                $zhOnly('product_update'),
                $zhOnly('quote_reply'),
            )],
            ['Websites', 'extends/MailChannelProvider.php', $zhOnly('notification')],
            ['Visitor', 'extends/MailChannelProvider.php', $zhOnly('notification')],
        ];
    }

    /** @dataProvider providerModules */
    public function testProviderDeclaresDefaultTemplatesAndZhFilesExist(
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

        $emailRoot = $root . '/view/email';
        if (is_dir($emailRoot)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($emailRoot));
            foreach ($it as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $name = $file->getFilename();
                if (preg_match('/^[a-z]{2}(_[A-Za-z0-9]+)?\.(html|subject\.txt)$/', $name)
                    && !str_starts_with($name, 'zh_Hans_CN.')) {
                    self::fail("non-zh email entity must not exist: {$file->getPathname()}");
                }
            }
        }
    }

    public function testSeedCatalogCoversDefaultWebsiteLanguagesInline(): void
    {
        $maintained = MailTemplateSeedCopyCatalog::maintainedLocales();
        self::assertContains('zh_Hans_CN', $maintained);
        self::assertContains('en_US', $maintained);
        foreach (['ar_SA', 'bn_BD', 'es_ES', 'fr_FR', 'hi_IN', 'id_ID', 'pt_BR', 'ur_PK', 'en_US', 'de_DE'] as $locale) {
            self::assertContains($locale, $maintained);
            $copy = MailTemplateSeedCopyCatalog::forSlug('order_created', $locale);
            self::assertNotNull($copy, $locale);
            self::assertNotSame('', $copy['subject'] ?? '');
            self::assertStringContainsString('{{var.order_number}}', $copy['subject']);
            self::assertStringContainsString('{{var.customer_name}}', $copy['body']);
        }
        self::assertNull(MailTemplateSeedCopyCatalog::forSlug('order_created', 'zh_Hans_CN'));
        self::assertFileExists(dirname(__DIR__, 2) . '/view/email/shell.phtml');

        $shellPhtml = (string)file_get_contents(dirname(__DIR__, 2) . '/view/email/shell.phtml');
        self::assertStringContainsString('<lang>需要帮助？</lang>', $shellPhtml);

        $resolved = MailTemplateSeedLocaleResolver::forDefaultWebsite();
        foreach (MailTemplateSeedCopyCatalog::baselineLocales() as $baseline) {
            self::assertContains($baseline, $resolved);
        }
        foreach ($resolved as $locale) {
            self::assertContains(
                $locale,
                $maintained,
                'maintainedLocales must cover default-website locale ' . $locale
            );
        }

        $entries = MailTemplateDefaultLocales::fileEntries('password_reset');
        self::assertNotEmpty($entries);
        self::assertSame('zh_Hans_CN', $entries[0]['locale'] ?? '');
        self::assertArrayHasKey('subject_file', $entries[0]);
        $en = null;
        foreach ($entries as $row) {
            if (($row['locale'] ?? '') === 'en_US') {
                $en = $row;
                break;
            }
        }
        self::assertNotNull($en);
        self::assertArrayHasKey('subject', $en);
        self::assertArrayHasKey('body', $en);
        self::assertArrayNotHasKey('subject_file', $en);
    }

    public function testMaterializeFilesIsNoOp(): void
    {
        $stats = MailTemplateSeedCopyCatalog::materializeFiles();
        self::assertSame(0, $stats['written']);
        self::assertSame(0, $stats['skipped']);
    }
}
