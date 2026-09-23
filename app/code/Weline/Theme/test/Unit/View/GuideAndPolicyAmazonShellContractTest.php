<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/** 指南壳与政策页：页面包裹器 + 主题宽度；政策页条款式左目录右正文。 */
final class GuideAndPolicyAmazonShellContractTest extends TestCase
{
    public function testGuideDefaultLayoutUsesPageWrapperAndThemeWidth(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/layouts/guide/default.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('weline-page-wrapper', $source);
        self::assertStringContainsString('amazon-policy-doc__stage', $source);
        self::assertStringContainsString('--weline-layout-content-max-width', $source);
        self::assertStringContainsString('--weline-layout-content-padding-inline', $source);
        self::assertStringContainsString('padding-block:', $source);
        self::assertStringNotContainsString('1440px', $source);
    }

    public function testTermsLayoutUsesInsetHeroAndThemeWidth(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/layouts/terms/default.phtml';
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('weline-page-wrapper', $source);
        self::assertStringContainsString('amazon-terms__stage', $source);
        self::assertStringContainsString('border-radius: 8px', $source);
        self::assertStringContainsString('amazon-terms__panel', $source);
        self::assertStringContainsString('amazon-terms__toc', $source);
        self::assertStringContainsString("WidgetI18n::label('目录')", $source);
        self::assertStringNotContainsString('<lang>', $source);
        self::assertStringNotContainsString('amazon-terms__hero-inner', $source);
        self::assertStringNotContainsString('1440px', $source);
    }

    /**
     * @dataProvider policyLayoutProvider
     */
    public function testPolicyLayoutsUseInsetHeroAndTocPanel(string $file): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/layouts/policy/' . $file;
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('weline-page-wrapper', $source);
        self::assertStringContainsString('amazon-policy__stage', $source);
        self::assertStringContainsString('amazon-policy__hero', $source);
        self::assertStringContainsString('border-radius: 8px', $source);
        self::assertStringContainsString('amazon-policy__panel', $source);
        self::assertStringContainsString('amazon-policy__toc', $source);
        self::assertStringContainsString('amazon-policy__breadcrumb', $source);
        self::assertStringContainsString('type="breadcrumb"', $source);
        self::assertStringContainsString('showBreadcrumb', $source);
        self::assertStringNotContainsString('amazon-policy__hero-inner', $source);
        self::assertStringNotContainsString('1440px', $source);
        self::assertStringNotContainsString('<lang>', $source);
        self::assertStringContainsString("WidgetI18n::label('目录')", $source);
        self::assertMatchesRegularExpression(
            "/WidgetI18n::label\\('(?:隐私政策|服务条款|Cookie 政策|退款政策|免责声明|网站政策说明|配送政策|无障碍声明)'\\)/u",
            $source
        );
    }

    public static function policyLayoutProvider(): array
    {
        return [
            'privacy' => ['privacy.phtml'],
            'cookie' => ['cookie.phtml'],
            'refund' => ['refund.phtml'],
            'disclaimer' => ['disclaimer.phtml'],
            'term-condition' => ['term-condition.phtml'],
            'shipping' => ['shipping.phtml'],
            'accessibility' => ['accessibility.phtml'],
        ];
    }

    public function testPolicyAndFaqTemplatesHaveNoCompileTimeLangChrome(): void
    {
        $base = dirname(__DIR__, 3) . '/view/theme/frontend/layouts';
        $files = [
            $base . '/policy/privacy.phtml',
            $base . '/policy/cookie.phtml',
            $base . '/policy/refund.phtml',
            $base . '/policy/disclaimer.phtml',
            $base . '/policy/term-condition.phtml',
            $base . '/policy/default.phtml',
            $base . '/terms/default.phtml',
            $base . '/faq/default.phtml',
        ];
        foreach ($files as $path) {
            self::assertFileExists($path);
            $source = (string)file_get_contents($path);
            self::assertStringNotContainsString('<lang>', $source, $path);
            self::assertStringContainsString('WidgetI18n::label', $source, $path);
        }
    }

    public function testPolicyAndTermsTocFragmentHrefsSurviveDocumentBase(): void
    {
        $base = dirname(__DIR__, 3) . '/view/theme/frontend/layouts';
        $files = [
            $base . '/policy/shipping.phtml',
            $base . '/policy/refund.phtml',
            $base . '/policy/privacy.phtml',
            $base . '/policy/cookie.phtml',
            $base . '/policy/disclaimer.phtml',
            $base . '/policy/term-condition.phtml',
            $base . '/policy/accessibility.phtml',
            $base . '/terms/default.phtml',
        ];
        foreach ($files as $path) {
            self::assertFileExists($path);
            $source = (string)file_get_contents($path);
            self::assertStringContainsString('StorefrontHref::fragmentHref', $source, $path);
            self::assertStringContainsString('$tocFallbackPath', $source, $path);
            self::assertStringNotContainsString('href="#<?= $escape($item[\'id\']) ?>"', $source, $path);
            self::assertStringContainsString(
                'scroll-margin-top: calc(var(--theme-header-height, 64px) + var(--weline-space-3, 0.75rem))',
                $source,
                $path
            );
            // 保留 sticky 悬浮；top 仅避开顶栏，禁止 12rem 过大留白
            self::assertStringContainsString('position: sticky', $source, $path);
            self::assertStringContainsString(
                'top: calc(var(--theme-header-height, 64px) + var(--weline-space-3, 0.75rem))',
                $source,
                $path
            );
            self::assertStringNotContainsString('top: 12rem', $source, $path);
        }
    }

    public function testEnglishCsvTranslatesPolicyTocHeading(): void
    {
        $map = self::loadCsvMap(dirname(__DIR__, 3) . '/i18n/en_US.csv');
        self::assertSame('Contents', $map['目录'] ?? null);
        self::assertSame('Privacy policy contents', $map['隐私政策目录'] ?? null);
        self::assertSame('Cookie policy contents', $map['Cookie 政策目录'] ?? null);
        self::assertSame('Terms of service contents', $map['服务条款目录'] ?? null);
        self::assertSame('Disclaimer contents', $map['免责声明目录'] ?? null);
        self::assertSame('Refund policy contents', $map['退款政策目录'] ?? null);
    }

    /**
     * policy-copy-tone-soften：政策页去公文硬句，但合规底线源串仍在；新源串须有 en_US 真译。
     */
    public function testPolicyLayoutsSoftenedToneKeepsComplianceAnchors(): void
    {
        $base = dirname(__DIR__, 3) . '/view/theme/frontend/layouts/policy';
        $files = [
            'privacy.phtml',
            'term-condition.phtml',
            'refund.phtml',
            'cookie.phtml',
            'shipping.phtml',
            'disclaimer.phtml',
            'accessibility.phtml',
            'default.phtml',
        ];
        $hardPhrases = [
            '请您仔细阅读',
            '请仔细阅读',
            '充分理解并同意',
            '构成您与本网站之间',
            '绝对连续性',
            '法律向配送政策',
            '下单即确认',
            '亦不得仅以',
            '不单独构成自动退款',
            '请不要进行后续操作',
        ];
        foreach ($files as $file) {
            $source = (string)file_get_contents($base . '/' . $file);
            foreach ($hardPhrases as $phrase) {
                self::assertStringNotContainsString($phrase, $source, $file . ' still has hard phrase: ' . $phrase);
            }
        }

        $termsPath = dirname(__DIR__, 3) . '/view/theme/frontend/layouts/terms/default.phtml';
        self::assertFileExists($termsPath);
        $terms = (string)file_get_contents($termsPath);
        foreach ($hardPhrases as $phrase) {
            self::assertStringNotContainsString($phrase, $terms, 'terms/default.phtml still has hard phrase: ' . $phrase);
        }
        self::assertStringContainsString('欢迎逛店、下单', $terms);
        self::assertStringContainsString('下面用白话说明逛店与下单时需要留意的规则', $terms);
        self::assertStringContainsString('1 / 我们提供哪些服务', $terms);

        $privacy = (string)file_get_contents($base . '/privacy.phtml');
        self::assertStringContainsString('PayPal、Stripe', $privacy);
        self::assertStringContainsString('中国境内', $privacy);

        $cookie = (string)file_get_contents($base . '/cookie.phtml');
        self::assertStringContainsString('非必要统计/营销类 Cookie 将在获得同意后再启用', $cookie);

        $refund = (string)file_get_contents($base . '/refund.phtml');
        self::assertStringContainsString('质量问题、错发漏发、运输损坏', $refund);
        self::assertStringContainsString('强制范围内', $refund);

        $shipping = (string)file_get_contents($base . '/shipping.phtml');
        self::assertStringContainsString('不是固定送达承诺', $shipping);

        $en = self::loadCsvMap(dirname(__DIR__, 3) . '/i18n/en_US.csv');
        $zh = self::loadCsvMap(dirname(__DIR__, 3) . '/i18n/zh_Hans_CN.csv');
        $newSources = [
            '我们会认真保护您的个人信息。下面说明：我们可能收集什么、用来做什么、何时会与支付/物流伙伴共享，以及您如何查询或更正。',
            '欢迎逛店、下单。使用本站前，请花一两分钟看看账户安全与购物规则；有不清楚的地方，随时问客服。',
            '必要 Cookie 让登录、购物车、结账能正常工作。统计或营销类 Cookie，我们会先征得您的同意再开启；您也可以随时在浏览器里清理。',
            '本页是配送规则说明；操作步骤见配送指南。',
            '下面用白话说明逛店与下单时需要留意的规则。可按目录跳转；有疑问请通过帮助中心联系我们。',
        ];
        foreach ($newSources as $src) {
            self::assertArrayHasKey($src, $zh, 'zh CSV missing: ' . $src);
            self::assertArrayHasKey($src, $en, 'en CSV missing: ' . $src);
            self::assertNotSame($src, $en[$src], 'en CSV left Chinese source as translation: ' . $src);
            self::assertNotSame('', trim((string)$en[$src]));
        }
    }

    /**
     * Non-zh storefront chrome: module CSV only en_US; other locales live in system dictionary.
     *
     * @dataProvider nonZhLocaleProvider
     */
    public function testNonZhCsvTranslatesPolicyChrome(string $locale, string $tocTranslation): void
    {
        if ($locale === 'en_US') {
            $map = self::loadCsvMap(dirname(__DIR__, 3) . '/i18n/en_US.csv');
            self::assertSame($tocTranslation, $map['目录'] ?? null);
            self::assertNotSame('隐私政策', $map['隐私政策'] ?? '隐私政策');
            self::assertNotSame('服务条款', $map['服务条款'] ?? '服务条款');
            self::assertNotSame('帮助中心', $map['帮助中心'] ?? '帮助中心');
            self::assertNotSame('目录', $map['目录'] ?? '目录');

            return;
        }

        // Module i18n/ forbids non zh/en CSV (MODULE_CSV_LOCALES); assert dictionary row instead.
        $pdo = new \PDO(
            'pgsql:host=127.0.0.1;port=5432;dbname=mig_clone_productcurrent20260810_20260810022347_e07e',
            'weline',
            'weline',
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
        $stmt = $pdo->prepare(
            'SELECT translate FROM w_i18n_locale_dictionary WHERE word = :word AND locale_code = :locale LIMIT 1'
        );
        $stmt->execute([':word' => '目录', ':locale' => $locale]);
        $tr = (string)$stmt->fetchColumn();
        if ($tr === '') {
            self::markTestSkipped('No dictionary row for 目录@' . $locale . ' in local DB yet');
        }
        self::assertSame($tocTranslation, $tr);
        self::assertNotSame('目录', $tr);
    }

    public static function nonZhLocaleProvider(): array
    {
        return [
            'en_US' => ['en_US', 'Contents'],
            'ar_SA' => ['ar_SA', 'المحتويات'],
            'es_ES' => ['es_ES', 'Contenido'],
            'fr_FR' => ['fr_FR', 'Sommaire'],
            'pt_BR' => ['pt_BR', 'Conteúdo'],
            'id_ID' => ['id_ID', 'Daftar isi'],
            'bn_BD' => ['bn_BD', 'সূচিপত্র'],
            'hi_IN' => ['hi_IN', 'विषय सूची'],
            'ur_PK' => ['ur_PK', 'فہرست'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function loadCsvMap(string $path): array
    {
        self::assertFileExists($path);
        $handle = fopen($path, 'rb');
        self::assertNotFalse($handle);
        $map = [];
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) >= 2 && $row[0] !== '') {
                $map[(string)$row[0]] = (string)$row[1];
            }
        }
        fclose($handle);

        return $map;
    }
}
