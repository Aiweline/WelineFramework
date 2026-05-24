<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteBlockContractAssemblerService;
use GuoLaiRen\PageBuilder\Service\AiSiteDesignDirectorService;
use PHPUnit\Framework\TestCase;

final class AiSiteBlockContractAssemblerServiceTest extends TestCase
{
    public function testAttachContractsDiversifiesMorphologyAndDistributesNonHeroImages(): void
    {
        $scope = [
            'site_title' => 'Atlas Finance',
            'brief_description' => 'Build a premium finance advisory site with proof, services, and clear consultation CTA.',
            'page_types' => ['home_page'],
            'palette' => [
                'primary' => '#16324F',
                'surface' => '#F7F9FC',
                'text' => '#101820',
                'accent' => '#C9972B',
            ],
        ];
        $pagePlans = [
            'home_page' => [
                'page_goal' => 'Explain the advisory offer and convert qualified visitors.',
                'blocks' => [
                    ['block_key' => 'hero', 'page_flow_role' => 'opening', 'title' => 'Plan with clarity', 'goal' => 'Introduce advisory value.'],
                    ['block_key' => 'services', 'page_flow_role' => 'details', 'title' => 'Services', 'goal' => 'Show planning services.'],
                    ['block_key' => 'proof', 'page_flow_role' => 'proof', 'title' => 'Proof', 'goal' => 'Show measurable client outcomes.'],
                    ['block_key' => 'process', 'page_flow_role' => 'details', 'title' => 'Process', 'goal' => 'Explain consultation steps.'],
                    ['block_key' => 'cta', 'page_flow_role' => 'cta', 'title' => 'Book now', 'goal' => 'Invite a consultation.'],
                ],
            ],
        ];
        $siteDesignSystem = (new AiSiteDesignDirectorService())->materialize($scope, [], [], $pagePlans);

        $assembled = (new AiSiteBlockContractAssemblerService())->assemble(
            $scope,
            [],
            [],
            $pagePlans,
            $siteDesignSystem
        );

        $blocks = $assembled['page_plans']['home_page']['blocks'] ?? [];
        self::assertCount(5, $blocks);

        $morphologies = \array_map(static fn (array $block): string => (string)($block['block_contract']['morphology_id'] ?? ''), $blocks);
        self::assertGreaterThanOrEqual(3, \count(\array_unique($morphologies)));
        for ($i = 1; $i < \count($morphologies); $i++) {
            self::assertNotSame($morphologies[$i - 1], $morphologies[$i]);
        }

        $requiredImageBlocks = \array_values(\array_filter($blocks, static function (array $block): bool {
            return !empty($block['block_contract']['media_strategy']['needs_real_image']);
        }));
        self::assertGreaterThanOrEqual(3, \count($requiredImageBlocks));
        self::assertGreaterThanOrEqual(
            2,
            (int)($assembled['asset_distribution_policy']['per_page']['home_page']['non_hero_required_image_count'] ?? 0)
        );
        self::assertSame('home_page', $assembled['asset_distribution_policy']['per_page']['home_page']['page_type'] ?? null);
        self::assertSame(3, (int)($assembled['asset_distribution_policy']['per_page']['home_page']['target_real_image_slots'] ?? 0));
        self::assertSame(2, (int)($assembled['asset_distribution_policy']['per_page']['home_page']['min_non_hero_real_image_slots'] ?? 0));

        foreach ($requiredImageBlocks as $block) {
            self::assertTrue($block['image_intent']['needs_image'] ?? false);
            self::assertNotEmpty($block['asset_requirements'][0]['slot_id'] ?? '');
            self::assertTrue($block['asset_requirements'][0]['required'] ?? false);
            self::assertSame(
                $block['asset_requirements'][0]['slot_id'],
                $block['block_contract']['media_strategy']['asset_slot_id'] ?? null
            );
            self::assertStringStartsWith('page:home_page:', (string)($block['asset_requirements'][0]['slot_id'] ?? ''));
        }

        $cssOnlyBlocks = \array_values(\array_filter($blocks, static function (array $block): bool {
            return empty($block['block_contract']['media_strategy']['needs_real_image']);
        }));
        self::assertNotEmpty($cssOnlyBlocks);
        foreach ($cssOnlyBlocks as $block) {
            self::assertFalse($block['image_intent']['needs_image'] ?? true);
            self::assertNotEmpty($block['block_contract']['media_strategy']['css_motif'] ?? '');
            self::assertStringStartsWith('CSS-only/no generated image', (string)($block['visual_signature']['media_strategy'] ?? ''));
        }
    }

    public function testServicesPageReceivesMultipleNonHeroImageSlots(): void
    {
        $scope = [
            'site_title' => 'Mira Studio',
            'brief_description' => 'Build a polished services site with proof, service detail, process, and consultation CTA.',
            'page_types' => ['services_page'],
            'palette' => [
                'primary' => '#244C5A',
                'surface' => '#F8FBFC',
                'text' => '#111827',
                'accent' => '#D97706',
            ],
        ];
        $pagePlans = [
            'services_page' => [
                'blocks' => [
                    ['block_key' => 'hero', 'page_flow_role' => 'opening', 'goal' => 'Frame the service promise.'],
                    ['block_key' => 'service_grid', 'page_flow_role' => 'details', 'goal' => 'Show service categories.'],
                    ['block_key' => 'proof', 'page_flow_role' => 'proof', 'goal' => 'Show credibility and outcomes.'],
                    ['block_key' => 'process', 'page_flow_role' => 'details', 'goal' => 'Explain the work process.'],
                    ['block_key' => 'support', 'page_flow_role' => 'support', 'goal' => 'Answer buyer concerns.'],
                    ['block_key' => 'cta', 'page_flow_role' => 'cta', 'goal' => 'Invite consultation.'],
                ],
            ],
        ];
        $siteDesignSystem = (new AiSiteDesignDirectorService())->materialize($scope, [], [], $pagePlans);

        $assembled = (new AiSiteBlockContractAssemblerService())->assemble($scope, [], [], $pagePlans, $siteDesignSystem);
        $policy = $assembled['asset_distribution_policy']['per_page']['services_page'] ?? [];

        self::assertGreaterThanOrEqual(3, (int)($policy['target_real_image_slots'] ?? 0));
        self::assertGreaterThanOrEqual(3, (int)($policy['min_non_hero_real_image_slots'] ?? 0));
        self::assertGreaterThanOrEqual(3, (int)($policy['non_hero_required_image_count'] ?? 0));
        self::assertContains('details', $policy['preferred_roles'] ?? []);
    }

    public function testLowImageryBriefKeepsBlocksCssOnlyUnlessAlreadyRequired(): void
    {
        $scope = [
            'site_title' => 'LeanOps',
            'brief_description' => 'Create a text only website with minimal imagery for operational buyers.',
            'page_types' => ['home_page'],
            'palette' => [
                'primary' => '#334155',
                'surface' => '#F8FAFC',
                'text' => '#0F172A',
                'accent' => '#0EA5E9',
            ],
        ];
        $pagePlans = [
            'home_page' => [
                'blocks' => [
                    ['block_key' => 'hero', 'page_flow_role' => 'opening', 'goal' => 'Introduce the product.'],
                    ['block_key' => 'features', 'page_flow_role' => 'details', 'goal' => 'Explain workflow features.'],
                    ['block_key' => 'proof', 'page_flow_role' => 'proof', 'goal' => 'Show operational proof.'],
                    ['block_key' => 'cta', 'page_flow_role' => 'cta', 'goal' => 'Invite a demo.'],
                ],
            ],
        ];
        $siteDesignSystem = (new AiSiteDesignDirectorService())->materialize($scope, [], [], $pagePlans);

        $assembled = (new AiSiteBlockContractAssemblerService())->assemble($scope, [], [], $pagePlans, $siteDesignSystem);
        $blocks = $assembled['page_plans']['home_page']['blocks'] ?? [];

        foreach ($blocks as $block) {
            self::assertFalse($block['image_intent']['needs_image'] ?? true);
            self::assertSame([], $block['asset_requirements'] ?? null);
            self::assertNotEmpty($block['block_contract']['media_strategy']['css_motif'] ?? '');
        }
    }
}
