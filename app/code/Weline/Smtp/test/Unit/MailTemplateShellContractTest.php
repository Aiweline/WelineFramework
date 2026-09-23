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
    public function testShellPhtmlUsesLangTagsAndWrapsBody(): void
    {
        $smtp = dirname(__DIR__, 2);
        $phtml = $smtp . '/view/email/shell.phtml';
        self::assertFileExists($phtml);
        $shellSrc = (string)file_get_contents($phtml);
        self::assertStringContainsString('data-weline-mail-shell', $shellSrc);
        self::assertStringContainsString('{{MAIL_BODY}}', $shellSrc);
        self::assertStringContainsString('{{var.site_logo_img|raw}}', $shellSrc);
        self::assertStringContainsString('{{var.brand_header_bg}}', $shellSrc);
        self::assertStringContainsString('{{var.brand_header_bg_css}}', $shellSrc);
        self::assertStringContainsString('{{var.brand_body_bg_css}}', $shellSrc);
        self::assertStringContainsString('{{var.brand_footer_bg_css}}', $shellSrc);
        self::assertStringContainsString('data-weline-mail-region="header"', $shellSrc);
        self::assertStringContainsString('data-weline-mail-region="body"', $shellSrc);
        self::assertStringContainsString('data-weline-mail-region="footer"', $shellSrc);
        self::assertSame(1, substr_count(strtolower($shellSrc), 'data-weline-mail-region="footer"'));
        self::assertStringContainsString('{{var.brand_display_name}}', $shellSrc);
        self::assertStringContainsString('<lang>需要帮助？</lang>', $shellSrc);
        self::assertStringContainsString('<lang>访问</lang>', $shellSrc);
        self::assertStringContainsString('<lang>此邮件由系统自动发送，请勿直接回复。如非本人操作，请忽略本邮件。</lang>', $shellSrc);
        self::assertStringContainsString('{{MAIL_HTML_DIR}}', $shellSrc);
        self::assertStringContainsString('{{MAIL_ALIGN_START}}', $shellSrc);
        self::assertStringNotContainsString('<script', strtolower($shellSrc));

        $composer = new MailTemplateShellComposer();
        $zh = $composer->wrap('<h1>Hello</h1><p>Body</p>', 'zh_Hans_CN', ['preheader' => 'Hello']);
        self::assertStringContainsString('data-weline-mail-shell', $zh);
        self::assertStringContainsString('lang="zh-Hans-CN"', $zh);
        self::assertStringContainsString('dir="ltr"', $zh);
        self::assertStringContainsString('align="left"', $zh);
        self::assertStringContainsString('{{var.brand_header_bg}}', $zh);
        self::assertStringContainsString('<h1>Hello</h1>', $zh);
        self::assertStringContainsString('需要帮助？', $zh);
        self::assertStringNotContainsString('<lang>', $zh);
        self::assertStringNotContainsString('{{MAIL_HTML_DIR}}', $zh);

        $en = $composer->wrap('<h1>Hello</h1><p>Body</p>', 'en_US', ['preheader' => 'Hello']);
        self::assertStringContainsString('lang="en-US"', $en);
        self::assertStringContainsString('dir="ltr"', $en);
        self::assertStringContainsString('Need help?', $en);
        self::assertStringContainsString('Visit', $en);
        self::assertStringNotContainsString('<lang>', $en);

        $ar = $composer->wrap('<h1>Hello</h1><p>Body</p>', 'ar_SA', ['preheader' => 'Hello']);
        self::assertSame('rtl', $composer->resolveTextDirection('ar_SA'));
        self::assertSame('rtl', $composer->resolveTextDirection('ur_PK'));
        self::assertSame('ltr', $composer->resolveTextDirection('bn_BD'));
        self::assertStringContainsString('lang="ar-SA"', $ar);
        self::assertStringContainsString('dir="rtl"', $ar);
        self::assertStringContainsString('direction:rtl', $ar);
        self::assertStringContainsString('align="right"', $ar);
        self::assertStringContainsString('text-align:right', $ar);
        self::assertStringContainsString('padding-right:16px', $ar);
        self::assertStringNotContainsString('{{MAIL_ALIGN_START}}', $ar);

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

        $orderCreated = (string)file_get_contents($root . '/app/code/Weline/Order/view/email/order_created/zh_Hans_CN.html');
        self::assertStringNotContainsString('width:38%', $orderCreated);
        self::assertStringContainsString('width:1%', $orderCreated);
        self::assertStringContainsString('white-space:nowrap', $orderCreated);
        $catalog = (string)file_get_contents($smtp . '/Service/MailTemplateSeedCopyCatalog.php');
        self::assertStringContainsString('width:1%', $catalog);
        self::assertStringNotContainsString("width:38%;", $catalog);

        $provider = (string)file_get_contents($smtp . '/extends/module/Weline_Framework/Query/SmtpQueryProvider.php');
        self::assertStringContainsString('MailTemplateShellComposer', $provider);
        self::assertStringContainsString('->wrap(', $provider);
        $seeder = (string)file_get_contents($smtp . '/Service/MailTemplateSeeder.php');
        self::assertStringContainsString('extractBodyFragment', $seeder);
        $edit = (string)file_get_contents($smtp . '/view/Backend/Template/edit.phtml');
        self::assertStringContainsString('smtp-template-shell-hint', $edit);
        $composerSrc = (string)file_get_contents($smtp . '/Service/MailTemplateShellComposer.php');
        self::assertStringContainsString('TranslationResolverInterface', $composerSrc);
        self::assertStringContainsString('shell.phtml', $composerSrc);
        self::assertStringContainsString('ThemeDirectoryResolver', $composerSrc);
        self::assertStringContainsString('resolveThemeTemplatePath', $composerSrc);
        self::assertStringContainsString('withMailLocaleEnvironment', $composerSrc);
        self::assertStringContainsString('Context::enter', $composerSrc);
        self::assertStringContainsString('setRequestLanguageOverride', $composerSrc);

        $hanfuShell = $root . '/app/design/Weline/hanfu/Weline_Smtp/email/shell.phtml';
        self::assertFileExists($hanfuShell);
        $hanfuSrc = (string)file_get_contents($hanfuShell);
        self::assertStringContainsString('data-weline-mail-theme="hanfu"', $hanfuSrc);
        self::assertStringContainsString('{{MAIL_BODY}}', $hanfuSrc);
        self::assertStringContainsString('data-weline-mail-region="header"', $hanfuSrc);

        // 激活 frontend=hanfu 时 wrap 须真正吃到设计主题壳（运行时，非仅文件存在）
        try {
            if (class_exists(\Weline\Theme\Model\WelineTheme::class)
                && class_exists(\Weline\Framework\Manager\ObjectManager::class)
            ) {
                /** @var \Weline\Theme\Model\WelineTheme $active */
                $active = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Theme\Model\WelineTheme::class);
                $active->clearData()->clearQuery()->getActiveTheme('frontend');
                if ((int)$active->getId() === 3 || str_contains((string)$active->getPath(), 'hanfu')) {
                    self::assertStringContainsString('data-weline-mail-theme="hanfu"', $zh);
                }
            }
        } catch (\Throwable) {
        }
    }

    public function testShellCsvCoversDefaultWebsiteLocalesWithoutChineseDisclaimer(): void
    {
        $smtp = dirname(__DIR__, 2);
        $i18n = $smtp . '/i18n';
        $disclaimerZh = '此邮件由系统自动发送，请勿直接回复。如非本人操作，请忽略本邮件。';
        // 模块 CSV 仅 zh+en；其它 locale 壳文案走 seed shellCopy
        foreach (['zh_Hans_CN', 'en_US'] as $locale) {
            self::assertFileExists($i18n . '/' . $locale . '.csv', $locale);
            $csv = (string)file_get_contents($i18n . '/' . $locale . '.csv');
            self::assertStringContainsString($disclaimerZh, $csv, $locale);
            self::assertStringContainsString('客服邮箱：', $csv, $locale);
            self::assertStringContainsString('需要帮助？', $csv, $locale);
        }

        $composer = new MailTemplateShellComposer();
        $bn = $composer->wrap('<p>Body</p>', 'bn_BD', ['preheader' => 'x']);
        self::assertStringNotContainsString($disclaimerZh, $bn);
        self::assertStringNotContainsString('客服邮箱：', $bn);
        self::assertStringNotContainsString('服务时间：', $bn);
        self::assertStringNotContainsString('Phone:', $bn);
        self::assertStringContainsString('সাহায্য প্রয়োজন?', $bn);
        self::assertStringContainsString('সহায়তা:', $bn);

        $fr = $composer->wrap('<p>Body</p>', 'fr_FR', ['preheader' => 'x']);
        self::assertStringNotContainsString($disclaimerZh, $fr);
        self::assertStringNotContainsString('Phone:', $fr);
        self::assertStringContainsString('Ce message a été envoyé automatiquement', $fr);
        self::assertStringContainsString('Support :', $fr);
        self::assertStringContainsString('Besoin d', $fr);
    }

    /**
     * be-shell-prefer-seed：非 en 壳 UI 优先 seed shellCopy，禁止词典英回落 Phone:/Hours:/Address:。
     * 联系变量可空（仅验标签路径，不含品牌 service_hours 值）。
     */
    public function testNonEnglishShellLabelsPreferSeedNotEnglishFallback(): void
    {
        $composer = new MailTemplateShellComposer();
        foreach (['ru_RU', 'de_DE'] as $locale) {
            $shell = $composer->loadShell($locale);
            $wrapped = $composer->wrap('<p>Body</p>', $locale, ['preheader' => 'x']);
            foreach ([$shell, $wrapped] as $html) {
                self::assertStringNotContainsString('Phone:', $html, $locale);
                self::assertStringNotContainsString('Hours:', $html, $locale);
                self::assertStringNotContainsString('Address:', $html, $locale);
                self::assertStringNotContainsString('Need help?', $html, $locale);
            }
        }

        $ru = $composer->wrap('<p>Body</p>', 'ru_RU', ['preheader' => 'x']);
        self::assertStringContainsString('Телефон:', $ru);
        self::assertStringContainsString('Часы работы:', $ru);
        self::assertStringContainsString('Адрес:', $ru);
        self::assertStringContainsString('Нужна помощь?', $ru);

        $de = $composer->wrap('<p>Body</p>', 'de_DE', ['preheader' => 'x']);
        self::assertStringContainsString('Telefon:', $de);
        self::assertStringContainsString('Öffnungszeiten:', $de);
        self::assertStringContainsString('Adresse:', $de);
        self::assertStringContainsString('Brauchst du Hilfe?', $de);
    }
}
