<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteDesignTokenResolver;
use GuoLaiRen\PageBuilder\Service\AiSiteDeterministicStylePatchService;
use GuoLaiRen\PageBuilder\Service\AiSiteLanguageVoiceResolver;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeManifestPolicy;
use GuoLaiRen\PageBuilder\Service\AiSiteVirtualThemeCssService;
use PHPUnit\Framework\TestCase;

final class AiSiteDesignConsistencyServicesTest extends TestCase
{
    public function testManifestPolicyRejectsInlinePlanJsonArtifact(): void
    {
        $policy = new AiSiteScopeManifestPolicy();
        $this->expectException(\InvalidArgumentException::class);
        $policy->assertManifestClean([
            'plan_json' => [
                'pages' => [
                    'home_page' => [
                        'hero' => ['html' => \str_repeat('x', AiSiteScopeManifestPolicy::MANIFEST_INLINE_MAX_BYTES + 1)],
                    ],
                ],
            ],
        ], true);
    }

    public function testManifestPolicyDehydrateStripsInlineHtml(): void
    {
        $policy = new AiSiteScopeManifestPolicy();
        $scope = [
            'plan_json' => ['pages' => []],
            'virtual_pages_by_type' => [
                'home_page' => [
                    'block_nodes' => [
                        ['block_id' => 'hero', 'html' => str_repeat('x', 600)],
                    ],
                ],
            ],
        ];
        $dehydrated = $policy->dehydrateScopePaths($scope);
        self::assertSame([], $dehydrated['plan_json']);
        self::assertArrayNotHasKey('html', $dehydrated['virtual_pages_by_type']['home_page']['block_nodes'][0]);
        self::assertNotEmpty($dehydrated['virtual_page_index']['home_page']['block_nodes']);
    }

    public function testDesignTokenResolverBuildsRootCss(): void
    {
        $resolver = new AiSiteDesignTokenResolver();
        $tokens = $resolver->resolveFromBlueprint([
            'theme_design' => [
                'color_scheme' => ['primary' => '#112233', 'accent' => '#445566'],
                'typography_spacing_radius' => ['font_family' => '"Noto Sans SC", sans-serif', 'radius_scale' => '14px'],
            ],
        ]);
        self::assertSame('"Noto Sans SC", sans-serif', $tokens['font_display']);
        $css = $resolver->buildRootCssVariables($tokens);
        self::assertStringContainsString('--pb-font-display:', $css);
        self::assertStringContainsString('--pb-color-primary:#112233', $css);
    }

    public function testDeterministicFontPatchUsesVarTokens(): void
    {
        $patch = new AiSiteDeterministicStylePatchService();
        $css = '#componentId .pb-c-title{font-family:Inter,sans-serif;}';
        $patched = $patch->patchHardcodedFonts($css, ['font_display' => 'X']);
        self::assertStringContainsString('var(--pb-font-display)', $patched);
        self::assertStringNotContainsString('Inter', $patched);
    }

    public function testLanguageVoiceResolverBuildsLexicon(): void
    {
        $resolver = new AiSiteLanguageVoiceResolver();
        $lexicon = $resolver->resolveCtaLexicon([
            'site_strategy' => ['primary_cta' => '立即下载'],
            'theme_design' => ['cta_tone' => '直接行动'],
        ], 'zh_Hans_CN');
        self::assertContains('立即下载', $lexicon);
        self::assertGreaterThanOrEqual(3, \count($lexicon));
    }

    public function testVirtualThemeCssServiceGeneratesSharedClasses(): void
    {
        $service = new AiSiteVirtualThemeCssService();
        $result = $service->generateThemeCss([
            'theme_design' => [
                'color_scheme' => ['primary' => '#101010'],
                'typography_spacing_radius' => ['font_family' => 'Georgia, serif'],
            ],
        ]);
        self::assertStringContainsString('.pb-c-cta-primary', $result['css']);
        self::assertStringContainsString('@media (max-width:768px)', $result['css']);
        self::assertStringStartsWith('sha256:', $result['hash']);
        $ref = $service->buildManifestRef($result);
        self::assertSame('theme_css', $ref['artifact_key']);
        self::assertArrayNotHasKey('css', $ref);
    }

    public function testMemoryRegressionWithArtifactLoopPeakIsStable(): void
    {
        $policy = new AiSiteScopeManifestPolicy();
        $peakBefore = \memory_get_usage(true);
        for ($i = 0; $i < 50; ++$i) {
            $scope = [
                'design_tokens' => ['font_display' => 'A', 'font_body' => 'A'],
                'plan_json' => [
                    'pages' => [
                        'home_page' => [
                            'hero_' . $i => ['status' => 1, 'html' => '<section>Hero</section>'],
                        ],
                    ],
                ],
            ];
            $policy->dehydrateScopePaths($scope);
            unset($scope);
        }
        $peakAfter = \memory_get_usage(true);
        self::assertLessThan($peakBefore + (8 * 1024 * 1024), $peakAfter);
    }
}
