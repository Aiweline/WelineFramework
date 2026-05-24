<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteBuildPlanService;
use PHPUnit\Framework\TestCase;

final class AiSiteBuildPlanServiceTest extends TestCase
{
    public function testBuildsValidBuildPlanV2FromStageOneExecutionBlueprint(): void
    {
        $service = new AiSiteBuildPlanService();

        $contract = $service->buildFromScope([
            'page_types' => ['home_page'],
            'site_title' => 'Example Site',
            'brief_description' => 'Convert qualified buyers with clear trust proof.',
            'default_locale' => 'en_US',
            'execution_blueprint_draft' => [
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
        self::assertSame('draft', $contract['contract_meta']['status']);
        self::assertCount(3, $contract['tasks']);
        self::assertSame(['shared:header', 'shared:footer', 'page:home_page:content/home-page-hero'], $contract['build_order']);
        self::assertArrayHasKey('runtime_context', $contract['tasks'][2]);
        self::assertArrayHasKey('output_contract', $contract['tasks'][2]);
        self::assertArrayHasKey('acceptance', $contract['tasks'][2]);
        self::assertSame('en_US', $contract['tasks'][2]['runtime_context']['content_locale'] ?? null);
        self::assertSame('en_US', $contract['tasks'][2]['runtime_context']['language_contract']['source_of_truth_locale'] ?? null);
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
            'execution_blueprint_draft' => [
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
        foreach ($contract['tasks'] as $task) {
            self::assertSame('ar_SA', $task['runtime_context']['content_locale'] ?? null);
            self::assertSame('ar_SA', $task['runtime_context']['language_contract']['source_of_truth_locale'] ?? null);
        }
    }

    public function testConfirmMarksContractConfirmedAndProjectionIsReadOnly(): void
    {
        $service = new AiSiteBuildPlanService();

        $contract = $service->confirm($service->buildFromScope([
            'page_types' => ['home_page'],
            'site_title' => 'Example Site',
            'brief_description' => 'Explain the service clearly.',
            'default_locale' => 'en_US',
            'execution_blueprint_draft' => [
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
            'execution_blueprint_draft' => [
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
}
