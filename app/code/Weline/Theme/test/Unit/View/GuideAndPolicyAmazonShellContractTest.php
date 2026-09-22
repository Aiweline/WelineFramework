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
            self::assertStringContainsString('scroll-margin-top: 12rem', $source, $path);
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
