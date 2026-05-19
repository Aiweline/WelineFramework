<?php

declare(strict_types=1);

namespace Tests\Unit\GuoLaiRen\PageBuilder;

use GuoLaiRen\PageBuilder\Service\AI\Contract\AiSiteVisualBlockContractRenderer;
use PHPUnit\Framework\TestCase;

/**
 * AiSiteVisualBlockContractRenderer 单元测试。
 *
 * 目标：固化「13 项门禁反向编码 → 单一契约清单」的输出形态，
 * 保证后续重构不会无意丢掉关键自检项（visual_depth gradient、
 * responsive @media 768/420、theme palette hex、必含事实等）。
 */
final class AiSiteVisualBlockContractRendererTest extends TestCase
{
    private AiSiteVisualBlockContractRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new AiSiteVisualBlockContractRenderer();
    }

    public function testRenderSectionContractCoversAllThirteenGateSignals(): void
    {
        $output = $this->renderer->renderSectionVisualContract(
            ['primary' => '#0f172a', 'accent' => '#f59e0b', 'surface' => '#ffffff', 'text' => '#1f2937'],
            [
                'page_goal' => 'Convert players to download Teen Patti APK',
                'block_goal' => 'Hero with one-tap download CTA',
                'must_include_facts' => ['Free APK', 'Daily bonus 100', 'Verified safe'],
            ],
            'zh_Hans_CN',
            true
        );

        self::assertStringContainsString('content_locale (HARD): zh_Hans_CN', $output);
        self::assertStringContainsString('[gate#visual_depth]', $output);
        self::assertStringContainsString('linear-gradient(...)', $output);
        self::assertStringContainsString('[gate#responsive_support]', $output);
        self::assertStringContainsString('@media (max-width: 768px)', $output);
        self::assertStringContainsString('@media (max-width: 420px)', $output);
        self::assertStringContainsString('clamp(', $output);
        self::assertStringContainsString('[gate#visual_assets_safe]', $output);
        self::assertStringContainsString('verified_asset_src_allowlist', $output);
        self::assertStringContainsString('[gate#theme_visible]', $output);
        self::assertStringContainsString('[gate#stage1_content_visible]', $output);
        self::assertStringContainsString('Free APK', $output);
        self::assertStringContainsString('[gate#content_quality]', $output);
        self::assertStringContainsString('[gate#language_consistency]', $output);
        self::assertStringContainsString('[gate#render_data_quality]', $output);
        self::assertStringContainsString('[gate#shared_blocks_ready]', $output);
        self::assertStringContainsString('[self-check before return]', $output);
        self::assertStringContainsString('#0f172a', $output);
        self::assertStringContainsString('#f59e0b', $output);
    }

    public function testRenderSectionContractWithoutHeroImageDescribesCssOnlyFallback(): void
    {
        $output = $this->renderer->renderSectionVisualContract(
            ['primary' => '#0f172a', 'accent' => '#f59e0b', 'surface' => '#ffffff', 'text' => '#1f2937'],
            ['must_include_facts' => []],
            'en_US',
            false
        );

        self::assertStringContainsString('visual.image：本区块没有验证后的图片素材', $output);
        self::assertStringNotContainsString('保留已验证的 <img>', $output);
        self::assertStringContainsString('content_locale (HARD): en_US', $output);
    }

    public function testRenderSectionContractIncludesVisualSignatureAndPageDesignPlan(): void
    {
        $output = $this->renderer->renderSectionVisualContract(
            ['primary' => '#0f172a'],
            ['block_goal' => 'Show product benefits'],
            'zh_Hans_CN',
            false,
            [
                'composition_pattern' => 'stacked_editorial_band',
                'spatial_rhythm' => 'airy vertical cadence',
                'media_strategy' => 'full_width_feature_image',
                'surface_treatment' => 'soft elevated panels',
                'interaction_pattern' => 'subtle hover lift',
            ],
            [
                'anti_monotony_rule' => 'Alternate split, stacked, and proof-band compositions',
                'composition_motif' => 'editorial lifestyle',
            ]
        );

        self::assertStringContainsString('visual_signature (HARD layout contract)', $output);
        self::assertStringContainsString('stacked_editorial_band', $output);
        self::assertStringContainsString('page_design_plan (page-level design brief)', $output);
        self::assertStringContainsString('Alternate split, stacked, and proof-band', $output);
        self::assertStringContainsString('Gate compliance is not an excuse for template sameness', $output);
    }

    public function testRenderSectionContractWithEmptyPaletteEmitsExplicitWarning(): void
    {
        $output = $this->renderer->renderSectionVisualContract(
            [],
            ['must_include_facts' => ['Loyalty rewards']],
            'zh_Hans_CN',
            false
        );

        self::assertStringContainsString('当前 scope 未提供 hex token', $output);
        self::assertStringContainsString('Loyalty rewards', $output);
    }

    public function testRenderSharedContractIncludesBrandWordsAndPalette(): void
    {
        $output = $this->renderer->renderSharedRegionVisualContract(
            'footer',
            ['primary' => '#0f172a', 'accent' => '#f59e0b'],
            ['site_title' => 'TPMaster', 'brand_name' => 'TPM'],
            'zh_Hans_CN'
        );

        self::assertStringContainsString('AI Shared footer Contract', $output);
        self::assertStringContainsString('content_locale (HARD): zh_Hans_CN', $output);
        self::assertStringContainsString('TPMaster', $output);
        self::assertStringContainsString('#0f172a', $output);
    }
}
