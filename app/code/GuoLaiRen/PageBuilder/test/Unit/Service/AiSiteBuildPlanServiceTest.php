<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteBuildPlanService;
use PHPUnit\Framework\TestCase;

final class AiSiteBuildPlanServiceTest extends TestCase
{
    public function testBuildsValidBuildPlanV2FromStageOnePlanJson(): void
    {
        $service = new AiSiteBuildPlanService();

        $contract = $service->buildFromScope([
            'page_types' => ['home_page'],
            'site_title' => 'Example Site',
            'brief_description' => 'Convert qualified buyers with clear trust proof.',
            'default_locale' => 'en_US',
            'plan_json' => [
                'signature' => 'stage1-signature',
                'pages' => [
                    'home_page' => [
                        'title' => 'Home',
                        'page_goal' => 'Show the product value and lead visitors to contact sales.',
                        'blocks' => [
                            [
                                'block_key' => 'hero',
                                'page_flow_role' => 'opening',
                                'title' => 'Launch reliable AI workflows',
                                'goal' => 'Show the core value with a direct CTA.',
                                'visual_signature' => [
                                    'composition_pattern' => 'split hero',
                                    'spatial_rhythm' => 'copy left, media right',
                                    'media_strategy' => 'Generated hero image in the media panel',
                                    'surface_treatment' => 'deep blue surface',
                                    'interaction_pattern' => 'CTA hover lift',
                                ],
                                'image_intent' => [
                                    'needs_image' => true,
                                    'image_role' => 'hero_image',
                                    'image_subject' => 'dashboard interface on a laptop',
                                    'placement' => 'media_panel',
                                    'visual_atmosphere' => 'calm enterprise workspace',
                                    'image_treatment' => 'rounded editorial crop',
                                    'reuse_policy' => 'reuse_when_intent_matches',
                                    'css_motif' => '',
                                ],
                                'field_plan' => [
                                    ['field' => 'description', 'sample' => 'Operational tooling for teams that need dependable automation.'],
                                    ['field' => 'cta', 'sample' => 'Book a workflow audit'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $result = $service->validate($contract);

        self::assertTrue($result['valid'], \implode("\n", $result['errors']));
        self::assertSame('2.2', $contract['contract_meta']['version']);
        self::assertSame('build_plan_v2', $contract['contract_meta']['type']);
        self::assertSame('draft', $contract['contract_meta']['status']);
        self::assertArrayNotHasKey('tasks', $contract);
        self::assertArrayNotHasKey('build_order', $contract);
        self::assertCount(1, $contract['pages']);
        self::assertCount(1, $contract['blocks']);
        self::assertSame('home_page', $contract['pages'][0]['page_id'] ?? null);
        self::assertSame(['home_page.hero'], $contract['pages'][0]['blocks'] ?? null);
        self::assertSame('home_page.hero', $contract['blocks'][0]['block_id'] ?? null);
        self::assertSame('hero', $contract['blocks'][0]['section_key'] ?? null);
        $responsiveContract = \is_array($contract['blocks'][0]['visual']['responsive_layout_contract'] ?? null)
            ? $contract['blocks'][0]['visual']['responsive_layout_contract']
            : [];
        self::assertStringContainsString('brand/logo text', (string)($responsiveContract['breakpoints']['mobile'] ?? ''));
        self::assertStringContainsString(
            'overflow-wrap:anywhere',
            \implode("\n", \is_array($responsiveContract['overflow_guards'] ?? null) ? $responsiveContract['overflow_guards'] : [])
        );
        self::assertStringContainsString(
            'white-space:nowrap',
            \implode("\n", \is_array($responsiveContract['overflow_guards'] ?? null) ? $responsiveContract['overflow_guards'] : [])
        );
        self::assertStringContainsString(
            'substantial CSS media surface',
            (string)($contract['blocks'][0]['visual']['image_integration'] ?? '')
        );
        self::assertStringContainsString(
            'policy/legal blocks may remain text-dense',
            (string)($contract['blocks'][0]['visual']['image_integration'] ?? '')
        );
        self::assertSame('en_US', $contract['i18n']['primary_locale'] ?? null);
        self::assertSame('en_US', $contract['content_manifest']['primary_locale'] ?? null);
        self::assertSame('Launch reliable AI workflows', $contract['content_manifest']['items']['block.home_page.hero.title'] ?? null);
        self::assertSame('Book a workflow audit', $contract['content_manifest']['items']['block.home_page.hero.cta'] ?? null);
        self::assertSame(true, $contract['presentation_projection']['never_feed_to_build'] ?? null);
    }

    public function testBuildUsesPlanJsonAsSingleStageOneSource(): void
    {
        $service = new AiSiteBuildPlanService();

        $contract = $service->buildFromScope([
            'page_types' => ['home_page'],
            'site_title' => 'Structured Source Site',
            'brief_description' => 'Confirm structured plans when the lightweight plan_json only carries metadata.',
            'default_locale' => 'en_US',
            'plan_json' => [
                'content_locale' => 'en_US',
                'signature' => 'plan-json-stage1-signature',
                'site_strategy' => [
                    'site_display_name' => 'Plan JSON Source Site',
                ],
                'pages' => [
                    'home_page' => [
                        'title' => 'Home',
                        'page_goal' => 'Explain the offer and drive the primary contact CTA.',
                        'blocks' => [
                            [
                                'block_key' => 'hero',
                                'page_flow_role' => 'opening',
                                'title' => 'Plan JSON hero',
                                'content' => 'A concise proof-led hero section for a complete plan_json source plan.',
                                'goal' => 'Use plan_json as the source of truth.',
                                'execution_script' => [
                                    'core_copy' => 'Guests see signature dishes, trust proof, and a clear reservation path.',
                                ],
                                'visual_signature' => [
                                    'composition_pattern' => 'editorial hero',
                                    'spatial_rhythm' => 'headline above proof row',
                                    'media_strategy' => 'CSS-only proof surface',
                                    'surface_treatment' => 'warm high-contrast panel',
                                    'interaction_pattern' => 'CTA hover lift',
                                ],
                                'image_intent' => [
                                    'needs_image' => false,
                                    'css_motif' => 'fine line grid',
                                ],
                                'field_plan' => [
                                    ['field' => 'description', 'sample' => 'A concise proof-led hero section.'],
                                    ['field' => 'cta', 'sample' => 'Reserve a table'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $result = $service->validate($contract);

        self::assertTrue($result['valid'], \implode("\n", $result['errors']));
        self::assertSame('home_page', $contract['pages'][0]['page_type'] ?? null);
        self::assertSame('Guests see signature dishes, trust', $contract['content_manifest']['items']['block.home_page.hero.title'] ?? null);
    }

    public function testBuildPlanTreatsMissingVisibleBlockCopyAsDiagnosticOnly(): void
    {
        $service = new AiSiteBuildPlanService();

        $contract = $service->buildFromScope([
            'page_types' => ['privacy_policy'],
            'site_title' => 'Example Site',
            'brief_description' => 'Explain privacy rules in a clear website structure.',
            'default_locale' => 'en_US',
            'plan_json' => [
                'signature' => 'stage1-signature-privacy',
                'pages' => [
                    'privacy_policy' => [
                        'title' => 'Privacy Policy',
                        'page_goal' => 'Present the privacy policy sections.',
                        'blocks' => [
                            [
                                'block_key' => 'privacy_overview',
                                'page_flow_role' => 'opening',
                                'visual_signature' => [
                                    'composition_pattern' => 'policy intro',
                                    'spatial_rhythm' => 'single column legal overview',
                                    'media_strategy' => 'CSS-only divider motif',
                                    'surface_treatment' => 'quiet readable surface',
                                    'interaction_pattern' => 'anchor navigation',
                                ],
                                'image_intent' => [
                                    'needs_image' => false,
                                    'css_motif' => 'thin policy divider',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $result = $service->validate($contract);

        self::assertTrue($result['valid'], \implode("\n", $result['errors']));
        self::assertSame('Privacy Overview', $contract['content_manifest']['items']['block.privacy_policy.privacy_overview.title'] ?? null);
        self::assertSame(
            'Present the core message clearly and guide visitors to the next action.',
            $contract['content_manifest']['items']['block.privacy_policy.privacy_overview.copy'] ?? null
        );
    }

    public function testBuildPlanRejectsStageOnePagesMissingSelectedPageTypes(): void
    {
        $service = new AiSiteBuildPlanService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing selected page_types: about_page');

        $service->buildFromScope([
            'page_types' => ['home_page', 'about_page'],
            'site_title' => 'Example Site',
            'brief_description' => 'Convert qualified buyers with clear trust proof.',
            'default_locale' => 'en_US',
            'plan_json' => [
                'signature' => 'stage1-signature',
                'content_locale' => 'zh_Hans_CN',
                'i18n' => ['locale' => 'pt_BR'],
                'site_strategy' => [
                    'core_goal' => 'Baixar o APK Teenipiya com seguranca.',
                ],
                'pages' => [
                    'home_page' => [
                        'title' => 'Home',
                        'page_goal' => 'Show the product value and lead visitors to contact sales.',
                        'blocks' => [
                            [
                                'block_key' => 'hero',
                                'page_flow_role' => 'opening',
                                'title' => 'Launch reliable AI workflows',
                                'goal' => 'Show the core value with a direct CTA.',
                                'visual_signature' => [
                                    'composition_pattern' => 'split hero',
                                    'spatial_rhythm' => 'copy left, media right',
                                    'media_strategy' => 'Generated hero image in the media panel',
                                    'surface_treatment' => 'deep blue surface',
                                ],
                                'image_intent' => [
                                    'needs_image' => false,
                                    'css_motif' => 'thin neon table grid',
                                ],
                                'field_plan' => [
                                    ['field' => 'description', 'sample' => 'Operational tooling for dependable automation.'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testBuildPlanDoesNotReuseConfirmedContractWhenSelectedPageTypesChange(): void
    {
        $service = new AiSiteBuildPlanService();
        $confirmedHomeOnly = $service->confirm($service->buildFromScope([
            'page_types' => ['home_page'],
            'site_title' => 'Example Site',
            'brief_description' => 'Convert qualified buyers with clear trust proof.',
            'default_locale' => 'en_US',
            'plan_json' => [
                'signature' => 'stage1-signature-home',
                'pages' => [
                    'home_page' => $this->minimalStageOnePage('Home', 'home hero'),
                ],
            ],
        ]));

        $contract = $service->buildFromScope([
            'page_types' => ['home_page', 'about_page'],
            'site_title' => 'Example Site',
            'brief_description' => 'Convert qualified buyers with clear trust proof.',
            'default_locale' => 'en_US',
            'build_plan_v2' => $confirmedHomeOnly,
            'plan_json' => [
                'signature' => 'stage1-signature-home-about',
                'pages' => [
                    'home_page' => $this->minimalStageOnePage('Home', 'home hero'),
                    'about_page' => $this->minimalStageOnePage('About', 'about story'),
                ],
            ],
        ]);

        self::assertSame(['home_page', 'about_page'], \array_column($contract['pages'], 'page_type'));
        self::assertCount(2, $contract['pages']);
    }

    public function testBuildPlanUsesContentLocaleInsteadOfPlanLocaleForEveryTask(): void
    {
        $service = new AiSiteBuildPlanService();

        $contract = $service->buildFromScope([
            'page_types' => ['home_page'],
            'site_title' => 'Example Site',
            'brief_description' => 'Convert qualified buyers with clear trust proof.',
            'content_locale' => 'ar_SA',
            'default_locale' => 'ar_SA',
            'plan_locale' => 'en_US',
            'plan_json' => [
                'signature' => 'stage1-signature',
                'pages' => [
                    'home_page' => [
                        'title' => 'Home',
                        'page_goal' => 'Show the product value and lead visitors to contact sales.',
                        'blocks' => [
                            [
                                'block_key' => 'hero',
                                'page_flow_role' => 'opening',
                                'title' => 'Launch reliable AI workflows',
                                'goal' => 'Show the core value with a direct CTA.',
                                'visual_signature' => [
                                    'composition_pattern' => 'split hero',
                                    'spatial_rhythm' => 'copy left, media right',
                                    'media_strategy' => 'Generated hero image in the media panel',
                                    'surface_treatment' => 'deep blue surface',
                                    'interaction_pattern' => 'CTA hover lift',
                                ],
                                'image_intent' => [
                                    'needs_image' => true,
                                    'image_role' => 'hero_image',
                                    'image_subject' => 'dashboard interface on a laptop',
                                    'placement' => 'media_panel',
                                    'visual_atmosphere' => 'calm enterprise workspace',
                                    'image_treatment' => 'rounded editorial crop',
                                    'reuse_policy' => 'reuse_when_intent_matches',
                                    'css_motif' => '',
                                ],
                                'field_plan' => [
                                    ['field' => 'description', 'sample' => 'Operational tooling for teams that need dependable automation.'],
                                    ['field' => 'cta', 'sample' => 'Book a workflow audit'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        self::assertSame('ar_SA', $contract['i18n']['primary_locale'] ?? null);
        self::assertSame('ar_SA', $contract['content_manifest']['primary_locale'] ?? null);
        self::assertArrayNotHasKey('tasks', $contract);
        self::assertArrayNotHasKey('build_order', $contract);
        self::assertSame('home_page', $contract['pages'][0]['page_id'] ?? null);
        self::assertSame('home_page.hero', $contract['blocks'][0]['block_id'] ?? null);
        self::assertSame('Launch reliable AI workflows', $contract['content_manifest']['items']['block.home_page.hero.title'] ?? null);
    }

    public function testBuildPlanPrefersSelectedDefaultLocaleOverPollutedProfileAndPlanLocale(): void
    {
        $service = new AiSiteBuildPlanService();

        $contract = $service->buildFromScope([
            'page_types' => ['home_page'],
            'site_title' => 'Teenipiya',
            'content_locale' => 'zh_Hans_CN',
            'brief_description' => '葡萄牙语网站。所有访客文案必须是巴西葡萄牙语。',
            'default_locale' => 'pt_BR',
            'plan_locale' => 'zh_Hans_CN',
            'plan_generated_locale' => 'zh_Hans_CN',
            'website_profile' => [
                'content_locale' => 'zh_Hans_CN',
                'default_locale' => 'pt_BR',
                'brief_description' => '葡萄牙语网站。所有访客文案必须是巴西葡萄牙语。',
            ],
            'plan_json' => [
                'signature' => 'stage1-signature',
                'content_locale' => 'zh_Hans_CN',
                'i18n' => ['locale' => 'pt_BR'],
                'site_strategy' => [
                    'core_goal' => 'Baixar o APK Teenipiya com segurança.',
                ],
                'pages' => [
                    'home_page' => [
                        'title' => 'Início',
                        'page_goal' => 'Baixar o APK Teenipiya com segurança.',
                        'blocks' => [
                            [
                                'block_key' => 'hero',
                                'page_flow_role' => 'opening',
                                'title' => 'Baixe Teenipiya com confiança',
                                'execution_script' => [
                                    'core_copy' => '这里是中文方案说明，不应该进入可见文案。',
                                ],
                                'visual_signature' => [
                                    'composition_pattern' => 'split hero',
                                    'spatial_rhythm' => 'copy left, media right',
                                    'media_strategy' => 'Generated hero image in the media panel',
                                    'surface_treatment' => 'dark premium surface',
                                    'interaction_pattern' => 'CTA hover lift',
                                ],
                                'image_intent' => [
                                    'needs_image' => true,
                                    'image_role' => 'hero_image',
                                    'image_subject' => 'players enjoying Teen Patti on a phone',
                                    'placement' => 'media_panel',
                                    'visual_atmosphere' => 'premium mobile gaming',
                                    'image_treatment' => 'rounded editorial crop',
                                    'reuse_policy' => 'reuse_when_intent_matches',
                                    'css_motif' => '',
                                ],
                                'field_plan' => [
                                    ['field' => 'description', 'sample' => 'Baixe o APK Teenipiya e jogue Teen Patti em um ambiente seguro.'],
                                    ['field' => 'cta', 'sample' => 'Baixar APK'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $result = $service->validate($contract);

        self::assertTrue($result['valid'], \implode("\n", $result['errors']));
        self::assertSame('pt_BR', $contract['i18n']['primary_locale'] ?? null);
        self::assertSame('pt_BR', $contract['content_manifest']['primary_locale'] ?? null);
        self::assertSame(
            'Baixe o APK Teenipiya e jogue Teen Patti em um ambiente seguro.',
            $contract['content_manifest']['items']['block.home_page.hero.copy'] ?? null
        );
    }

    public function testBuildPlanRejectsShortCjkPageTitleForNonCjkLocale(): void
    {
        $service = new AiSiteBuildPlanService();

        $contract = $service->buildFromScope([
            'page_types' => ['about_page'],
            'site_title' => 'Teenipiya',
            'brief_description' => 'Build a Portuguese APK landing page.',
            'content_locale' => 'pt_BR',
            'default_locale' => 'pt_BR',
            'plan_locale' => 'zh_Hans_CN',
            'plan_json' => [
                'signature' => 'stage1-signature',
                'pages' => [
                    'about_page' => [
                        'title' => "\u{5173}\u{4E8E}\u{6211}\u{4EEC}",
                        'page_goal' => 'Apresentar a marca Teenipiya com foco em confianca.',
                        'blocks' => [
                            [
                                'block_key' => 'origin_story',
                                'page_flow_role' => 'opening',
                                'title' => 'Uma marca feita para Teen Patti seguro',
                                'goal' => 'Apresentar confianca e clareza para novos jogadores.',
                                'visual_signature' => [
                                    'composition_pattern' => 'editorial split',
                                    'spatial_rhythm' => 'story copy beside proof cards',
                                    'media_strategy' => 'Generated brand story image in the media panel',
                                    'surface_treatment' => 'premium dark surface',
                                    'interaction_pattern' => 'CTA hover lift',
                                ],
                                'image_intent' => [
                                    'needs_image' => true,
                                    'image_role' => 'story_image',
                                    'image_subject' => 'Teen Patti players using a secure mobile app',
                                    'placement' => 'media_panel',
                                    'visual_atmosphere' => 'warm trustworthy gaming scene',
                                    'image_treatment' => 'rounded editorial crop',
                                    'reuse_policy' => 'reuse_when_intent_matches',
                                    'css_motif' => '',
                                ],
                                'field_plan' => [
                                    ['field' => 'title', 'sample' => 'Teenipiya APK seguro'],
                                    ['field' => 'headline', 'sample' => 'Teenipiya nasceu para jogos claros e seguros.'],
                                    ['field' => 'copy', 'sample' => 'Teenipiya apresenta um APK claro e seguro para jogadores de Teen Patti.'],
                                    ['field' => 'supporting_copy', 'sample' => 'Cada detalhe orienta jogadores a baixar o APK correto.'],
                                    ['field' => 'cta_label', 'sample' => 'Baixar APK'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        self::assertSame('pt_BR', $contract['content_manifest']['primary_locale'] ?? null);
        self::assertNotSame("\u{5173}\u{4E8E}\u{6211}\u{4EEC}", $contract['content_manifest']['items']['page.about_page.title'] ?? null);
        self::assertDoesNotMatchRegularExpression(
            '/[\x{4e00}-\x{9fff}]/u',
            (string)($contract['content_manifest']['items']['page.about_page.title'] ?? '')
        );
    }

    public function testConfirmMarksContractConfirmedAndProjectionIsReadOnly(): void
    {
        $service = new AiSiteBuildPlanService();

        $contract = $service->confirm($service->buildFromScope([
            'page_types' => ['home_page'],
            'site_title' => 'Example Site',
            'brief_description' => 'Explain the service clearly.',
            'default_locale' => 'en_US',
            'plan_json' => [
                'signature' => 'stage1-signature',
                'pages' => [
                    'home_page' => [
                        'title' => 'Home',
                        'page_goal' => 'Explain the service clearly.',
                        'blocks' => [
                            [
                                'block_key' => 'hero',
                                'page_flow_role' => 'opening',
                                'title' => 'Explain the service clearly',
                                'goal' => 'Show the service value with a direct CTA.',
                                'visual_signature' => [
                                    'composition_pattern' => 'split hero',
                                    'spatial_rhythm' => 'copy left, media right',
                                    'media_strategy' => 'Generated hero image in the media panel',
                                    'surface_treatment' => 'clean blue surface',
                                    'interaction_pattern' => 'CTA hover lift',
                                ],
                                'image_intent' => [
                                    'needs_image' => true,
                                    'image_role' => 'hero_image',
                                    'image_subject' => 'service dashboard on a laptop',
                                    'placement' => 'media_panel',
                                    'visual_atmosphere' => 'calm professional workspace',
                                    'image_treatment' => 'rounded editorial crop',
                                    'reuse_policy' => 'reuse_when_intent_matches',
                                    'css_motif' => '',
                                ],
                                'field_plan' => [
                                    ['field' => 'headline', 'sample' => 'Explain the service clearly'],
                                    ['field' => 'supporting_copy', 'sample' => 'A clear overview helps visitors understand the next step.'],
                                    ['field' => 'cta_label', 'sample' => 'Contact us'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]));
        $projection = $service->projection($contract);

        self::assertTrue($service->validate($contract)['valid']);
        self::assertSame('confirmed', $contract['contract_meta']['status']);
        self::assertSame(true, $projection['never_feed_to_build']);
        self::assertSame((string)$contract['contract_meta']['id'], $projection['source_contract_id']);
    }

    public function testBuildPlanStripsStageOneExplanatoryFieldsFromBlockContracts(): void
    {
        $service = new AiSiteBuildPlanService();

        $contract = $service->buildFromScope([
            'page_types' => ['home_page'],
            'site_title' => 'Example Site',
            'brief_description' => 'Convert qualified buyers with clear trust proof.',
            'default_locale' => 'en_US',
            'plan_json' => [
                'signature' => 'stage1-signature',
                'pages' => [
                    'home_page' => [
                        'title' => 'Home',
                        'page_goal' => 'Show the product value and lead visitors to contact sales.',
                        'page_design_plan' => [
                            'composition_motif' => 'split hero with proof cards',
                            'reason' => 'internal planning prose',
                        ],
                        'blocks' => [
                            [
                                'block_key' => 'hero',
                                'page_flow_role' => 'opening',
                                'title' => 'Launch reliable AI workflows',
                                'goal' => 'Show the core value with a direct CTA.',
                                'design_tags' => [
                                    'visual' => ['proof cards'],
                                    'reason' => 'internal planning prose',
                                ],
                                'visual_signature' => [
                                    'composition_pattern' => 'split hero',
                                    'spatial_rhythm' => 'copy left, media right',
                                    'media_strategy' => 'Generated hero image in the media panel',
                                    'surface_treatment' => 'deep blue surface',
                                    'interaction_pattern' => 'CTA hover lift',
                                ],
                                'image_intent' => [
                                    'needs_image' => true,
                                    'image_role' => 'hero_image',
                                    'image_subject' => 'dashboard interface on a laptop',
                                    'placement' => 'media_panel',
                                    'visual_atmosphere' => 'calm enterprise workspace',
                                    'image_treatment' => 'rounded editorial crop',
                                    'reuse_policy' => 'reuse_when_intent_matches',
                                    'css_motif' => '',
                                    'rationale' => 'internal planning prose',
                                ],
                                'field_plan' => [
                                    ['field' => 'headline', 'sample' => 'Launch reliable AI workflows'],
                                    ['field' => 'supporting_copy', 'sample' => 'Operational tooling for dependable automation.'],
                                    ['field' => 'cta_label', 'sample' => 'Book a workflow audit'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $result = $service->validate($contract);

        self::assertTrue($result['valid'], \implode("\n", $result['errors']));
        self::assertArrayNotHasKey('reason', $contract['pages'][0]['page_design_plan']);
        self::assertArrayNotHasKey('reason', $contract['blocks'][0]['design_tags']);
        self::assertArrayNotHasKey('rationale', $contract['blocks'][0]['image_intent']);
        self::assertArrayNotHasKey('rationale', $contract['blocks'][0]['visual']['image_intent']);
        self::assertArrayNotHasKey('reason', $contract['blocks'][0]['visual']['source_design_tags']);
    }

    private function minimalStageOnePage(string $title, string $headline): array
    {
        return [
            'title' => $title,
            'page_goal' => 'Explain the offer and guide visitors to the next action.',
            'blocks' => [
                [
                    'block_key' => 'hero',
                    'page_flow_role' => 'opening',
                    'title' => $headline,
                    'goal' => 'Show the core value with a direct CTA.',
                    'visual_signature' => [
                        'composition_pattern' => 'split hero',
                        'spatial_rhythm' => 'copy left, media right',
                        'media_strategy' => 'Generated hero image in the media panel',
                        'surface_treatment' => 'clean dark surface',
                        'interaction_pattern' => 'CTA hover lift',
                    ],
                    'image_intent' => [
                        'needs_image' => true,
                        'image_role' => 'hero_image',
                        'image_subject' => 'service dashboard on a laptop',
                        'placement' => 'media_panel',
                        'visual_atmosphere' => 'calm professional workspace',
                        'image_treatment' => 'rounded editorial crop',
                        'reuse_policy' => 'reuse_when_intent_matches',
                        'css_motif' => '',
                    ],
                    'field_plan' => [
                        ['field' => 'headline', 'sample' => $headline],
                        ['field' => 'supporting_copy', 'sample' => 'A clear overview helps visitors understand the next step.'],
                        ['field' => 'cta_label', 'sample' => 'Contact us'],
                    ],
                ],
            ],
        ];
    }
}
