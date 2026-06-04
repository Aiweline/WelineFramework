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
        $planJsonPages = [
            'home_page' => $this->pageWithBlocks(
                ['page_goal' => 'Explain the advisory offer and convert qualified visitors.'],
                [
                    ['block_key' => 'hero', 'page_flow_role' => 'opening', 'title' => 'Plan with clarity', 'goal' => 'Introduce advisory value.'],
                    ['block_key' => 'services', 'page_flow_role' => 'details', 'title' => 'Services', 'goal' => 'Show planning services.'],
                    ['block_key' => 'proof', 'page_flow_role' => 'proof', 'title' => 'Proof', 'goal' => 'Show measurable client outcomes.'],
                    ['block_key' => 'process', 'page_flow_role' => 'details', 'title' => 'Process', 'goal' => 'Explain consultation steps.'],
                    ['block_key' => 'cta', 'page_flow_role' => 'cta', 'title' => 'Book now', 'goal' => 'Invite a consultation.'],
                ]
            ),
        ];
        $siteDesignSystem = (new AiSiteDesignDirectorService())->materialize($scope, [], [], $planJsonPages);

        $assembled = (new AiSiteBlockContractAssemblerService())->assemble(
            $scope,
            [],
            [],
            $planJsonPages,
            $siteDesignSystem
        );

        $blocks = $this->dynamicPageBlockNodes($assembled['pages']['home_page'] ?? []);
        self::assertCount(5, $blocks);

        $morphologies = \array_map(static fn (array $block): string => (string)($block['block_contract']['morphology_id'] ?? ''), $blocks);
        self::assertGreaterThanOrEqual(3, \count(\array_unique($morphologies)));
        for ($i = 1; $i < \count($morphologies); $i++) {
            self::assertNotSame($morphologies[$i - 1], $morphologies[$i]);
        }

        $requiredImageBlocks = \array_values(\array_filter($blocks, static function (array $block): bool {
            return !empty($block['block_contract']['media_strategy']['needs_real_image']);
        }));
        self::assertGreaterThanOrEqual(4, \count($requiredImageBlocks));
        self::assertGreaterThanOrEqual(
            3,
            (int)($assembled['asset_distribution_policy']['per_page']['home_page']['non_hero_required_image_count'] ?? 0)
        );
        self::assertSame('home_page', $assembled['asset_distribution_policy']['per_page']['home_page']['page_type'] ?? null);
        self::assertSame(4, (int)($assembled['asset_distribution_policy']['per_page']['home_page']['target_real_image_slots'] ?? 0));
        self::assertSame(3, (int)($assembled['asset_distribution_policy']['per_page']['home_page']['min_non_hero_real_image_slots'] ?? 0));

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
        $planJsonPages = [
            'services_page' => $this->pageWithBlocks(
                [],
                [
                    ['block_key' => 'hero', 'page_flow_role' => 'opening', 'goal' => 'Frame the service promise.'],
                    ['block_key' => 'service_grid', 'page_flow_role' => 'details', 'goal' => 'Show service categories.'],
                    ['block_key' => 'proof', 'page_flow_role' => 'proof', 'goal' => 'Show credibility and outcomes.'],
                    ['block_key' => 'process', 'page_flow_role' => 'details', 'goal' => 'Explain the work process.'],
                    ['block_key' => 'support', 'page_flow_role' => 'support', 'goal' => 'Answer buyer concerns.'],
                    ['block_key' => 'cta', 'page_flow_role' => 'cta', 'goal' => 'Invite consultation.'],
                ]
            ),
        ];
        $siteDesignSystem = (new AiSiteDesignDirectorService())->materialize($scope, [], [], $planJsonPages);

        $assembled = (new AiSiteBlockContractAssemblerService())->assemble($scope, [], [], $planJsonPages, $siteDesignSystem);
        $policy = $assembled['asset_distribution_policy']['per_page']['services_page'] ?? [];

        self::assertGreaterThanOrEqual(5, (int)($policy['target_real_image_slots'] ?? 0));
        self::assertGreaterThanOrEqual(4, (int)($policy['min_non_hero_real_image_slots'] ?? 0));
        self::assertGreaterThanOrEqual(4, (int)($policy['non_hero_required_image_count'] ?? 0));
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
        $planJsonPages = [
            'home_page' => $this->pageWithBlocks(
                [],
                [
                    ['block_key' => 'hero', 'page_flow_role' => 'opening', 'goal' => 'Introduce the product.'],
                    ['block_key' => 'features', 'page_flow_role' => 'details', 'goal' => 'Explain workflow features.'],
                    ['block_key' => 'proof', 'page_flow_role' => 'proof', 'goal' => 'Show operational proof.'],
                    ['block_key' => 'cta', 'page_flow_role' => 'cta', 'goal' => 'Invite a demo.'],
                ]
            ),
        ];
        $siteDesignSystem = (new AiSiteDesignDirectorService())->materialize($scope, [], [], $planJsonPages);

        $assembled = (new AiSiteBlockContractAssemblerService())->assemble($scope, [], [], $planJsonPages, $siteDesignSystem);
        $blocks = $this->dynamicPageBlockNodes($assembled['pages']['home_page'] ?? []);

        foreach ($blocks as $block) {
            self::assertFalse($block['image_intent']['needs_image'] ?? true);
            self::assertSame([], $block['asset_requirements'] ?? null);
            self::assertNotEmpty($block['block_contract']['media_strategy']['css_motif'] ?? '');
        }
    }

    public function testNeonCardBriefCreatesRoleSpecificImageSubjects(): void
    {
        $scope = [
            'site_title' => 'Neon Card Club',
            'brief_description' => 'India-focused online card game APK download site with hero, features, proof, support, and final CTA sections.',
            'page_types' => ['home_page'],
        ];
        $planJsonPages = [
            'home_page' => $this->pageWithBlocks(
                [],
                [
                    ['block_key' => 'hero', 'page_flow_role' => 'opening', 'goal' => 'Introduce the card game club and APK download offer.'],
                    ['block_key' => 'game_features', 'page_flow_role' => 'details', 'goal' => 'Explain game modes, bonuses, and safe Android play.'],
                    ['block_key' => 'player_proof', 'page_flow_role' => 'proof', 'goal' => 'Show player trust signals and responsible gaming cues.'],
                    ['block_key' => 'support_center', 'page_flow_role' => 'support', 'goal' => 'Present help options and account support paths.'],
                    ['block_key' => 'final_cta', 'page_flow_role' => 'cta', 'goal' => 'Close with a focused APK download action.'],
                ]
            ),
        ];
        $siteDesignSystem = (new AiSiteDesignDirectorService())->materialize($scope, [], [], $planJsonPages);

        $assembled = (new AiSiteBlockContractAssemblerService())->assemble($scope, [], [], $planJsonPages, $siteDesignSystem);
        $blocks = $this->dynamicPageBlockNodes($assembled['pages']['home_page'] ?? []);

        $subjectsByKey = [];
        foreach ($blocks as $block) {
            $key = (string)($block['block_key'] ?? '');
            $subjectsByKey[$key] = (string)($block['block_contract']['media_strategy']['image_subject'] ?? '');
        }

        self::assertStringContainsString('neon card-game lobby hero scene', $subjectsByKey['hero'] ?? '');
        self::assertStringContainsString('neon card-game feature scene', $subjectsByKey['game_features'] ?? '');
        self::assertStringContainsString('player trust proof scene', $subjectsByKey['player_proof'] ?? '');
        self::assertStringContainsString('VIP support desk', $subjectsByKey['support_center'] ?? '');
        self::assertNotSame($subjectsByKey['hero'] ?? '', $subjectsByKey['player_proof'] ?? '');
        self::assertTrue((bool)($blocks[3]['image_intent']['needs_image'] ?? false));
    }

    public function testBlockContractPreservesPlannedGeneratedImageSubject(): void
    {
        $scope = [
            'site_title' => 'Neon Table Club',
            'brief_description' => 'Neon card-game site with game rooms, player proof, strategy guides, and support.',
            'page_types' => ['home_page'],
        ];
        $planJsonPages = [
            'home_page' => $this->pageWithBlocks(
                [],
                [[
                    'block_key' => 'reward_feature',
                    'page_flow_role' => 'details',
                    'goal' => 'Show limited-time table rewards.',
                    'image_intent' => [
                        'needs_image' => true,
                        'image_subject' => 'block visual for rewards: neon poker chips, mahjong tiles, bonus cards, and live table prize UI',
                        'image_treatment' => 'tight section crop with cyan-magenta prize glow',
                    ],
                ]]
            ),
        ];
        $siteDesignSystem = (new AiSiteDesignDirectorService())->materialize($scope, [], [], $planJsonPages);

        $assembled = (new AiSiteBlockContractAssemblerService())->assemble($scope, [], [], $planJsonPages, $siteDesignSystem);
        $blocks = $this->dynamicPageBlockNodes($assembled['pages']['home_page'] ?? []);
        $block = $blocks[0] ?? [];
        $media = \is_array($block['block_contract']['media_strategy'] ?? null)
            ? $block['block_contract']['media_strategy']
            : [];

        self::assertSame(
            'block visual for rewards: neon poker chips, mahjong tiles, bonus cards, and live table prize UI',
            (string)($media['image_subject'] ?? '')
        );
        self::assertSame('tight section crop with cyan-magenta prize glow', (string)($media['image_treatment'] ?? ''));
    }

    /**
     * @param array<string, mixed> $page
     * @return list<array<string, mixed>>
     */
    private function dynamicPageBlockNodes(array $page): array
    {
        $blocks = [];
        foreach ($page as $key => $node) {
            if (!\is_string($key) || !\is_array($node)) {
                continue;
            }
            if (!\array_key_exists('block_key', $node) && !\array_key_exists('block_contract', $node)) {
                continue;
            }
            $blocks[] = $node;
        }

        return $blocks;
    }

    /**
     * @param array<string, mixed> $page
     * @param list<array<string, mixed>> $blocks
     * @return array<string, mixed>
     */
    private function pageWithBlocks(array $page, array $blocks): array
    {
        foreach ($blocks as $index => $block) {
            $key = (string)($block['block_key'] ?? ('block_' . $index));
            $page[$key] = $block;
        }

        return $page;
    }
}
