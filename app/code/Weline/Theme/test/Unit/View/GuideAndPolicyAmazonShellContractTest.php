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
        self::assertStringContainsString('<lang>目录</lang>', $source);
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
        self::assertStringNotContainsString('amazon-policy__hero-inner', $source);
        self::assertStringNotContainsString('1440px', $source);
    }

    public static function policyLayoutProvider(): array
    {
        return [
            'privacy' => ['privacy.phtml'],
            'cookie' => ['cookie.phtml'],
            'refund' => ['refund.phtml'],
            'disclaimer' => ['disclaimer.phtml'],
            'term-condition' => ['term-condition.phtml'],
        ];
    }

    public function testEnglishCsvTranslatesPolicyTocHeading(): void
    {
        $csv = (string)file_get_contents(dirname(__DIR__, 3) . '/i18n/en_US.csv');
        self::assertStringContainsString('目录,Contents', $csv);
        self::assertStringNotContainsString("\n目录,目录\n", $csv);
        self::assertStringContainsString('隐私政策目录,"Privacy policy contents"', $csv);
        self::assertStringContainsString('"Cookie 政策目录","Cookie policy contents"', $csv);
        self::assertStringContainsString('服务条款目录,"Terms of service contents"', $csv);
        self::assertStringContainsString('免责声明目录,"Disclaimer contents"', $csv);
        self::assertStringContainsString('退款政策目录,"Refund policy contents"', $csv);
    }
}
