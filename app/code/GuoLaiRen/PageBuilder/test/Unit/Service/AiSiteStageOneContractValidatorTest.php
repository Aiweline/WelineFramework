<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteStageOneContractValidator;
use PHPUnit\Framework\TestCase;

final class AiSiteStageOneContractValidatorTest extends TestCase
{
    public function testVisibleCopyMustUseContentLocale(): void
    {
        $validator = new AiSiteStageOneContractValidator();
        $report = $validator->validatePagePlan(
            'home_page',
            $this->pagePlanWithBlock([
                'content' => "\u{8FD9}\u{662F}\u{4E0D}\u{5E94}\u{8FDB}\u{5165}\u{8461}\u{8404}\u{7259}\u{8BED}\u{9875}\u{9762}\u{7684}\u{4E2D}\u{6587}\u{6B63}\u{6587}\u{3002}",
                'field_plan' => [
                    ['field' => 'description', 'sample' => "\u{8FD9}\u{662F}\u{4E2D}\u{6587}\u{5B57}\u{6BB5}\u{793A}\u{4F8B}\u{3002}"],
                ],
                'execution_script' => [
                    'core_copy' => "\u{8FD9}\u{662F}\u{4E2D}\u{6587}\u{6838}\u{5FC3}\u{6587}\u{6848}\u{3002}",
                ],
            ]),
            $this->singleHeroContract('pt_BR')
        );

        self::assertFalse((bool)($report['passed'] ?? true));
        self::assertContains(
            'visible_copy_locale_mismatch',
            \array_map(static fn(array $issue): string => (string)($issue['code'] ?? ''), $report['issues'] ?? [])
        );
    }

    public function testMissingBuildPlanBodyCopyIsBlocking(): void
    {
        $validator = new AiSiteStageOneContractValidator();
        $report = $validator->validatePagePlan(
            'home_page',
            $this->pagePlanWithBlock([
                'content' => '',
                'field_plan' => [
                    ['field' => 'headline', 'sample' => 'Download safely'],
                ],
                'execution_script' => [
                    'core_copy' => '',
                ],
            ]),
            $this->singleHeroContract('en_US')
        );

        self::assertFalse((bool)($report['passed'] ?? true));
        self::assertContains(
            'missing_visible_body_copy',
            \array_map(static fn(array $issue): string => (string)($issue['code'] ?? ''), $report['issues'] ?? [])
        );
        self::assertGreaterThan(0, (int)($report['blocking_issue_count'] ?? 0));
    }

    public function testDashboardWorkflowSubjectWithBadgeDetailIsNotIconOnly(): void
    {
        $validator = new AiSiteStageOneContractValidator();
        $report = $validator->validatePagePlan(
            'home_page',
            $this->pagePlanWithBlock([
                'image_intent' => [
                    'needs_image' => true,
                    'image_role' => 'hero_image',
                    'image_subject' => 'opsflow ai onepass dashboard showing approval route cards, live status timeline, and an exception alert badge',
                    'placement' => 'media_panel',
                    'visual_atmosphere' => 'premium operational SaaS interface',
                    'image_treatment' => 'crisp product mockup with subtle depth',
                    'reuse_policy' => 'reuse_when_intent_matches',
                    'css_motif' => '',
                ],
            ]),
            $this->singleHeroContract('en_US')
        );

        self::assertNotContains(
            'icon_only_image_subject',
            \array_map(static fn(array $issue): string => (string)($issue['code'] ?? ''), $report['issues'] ?? [])
        );
    }

    public function testPureIconSubjectStillFailsImageIntentContract(): void
    {
        $validator = new AiSiteStageOneContractValidator();
        $report = $validator->validatePagePlan(
            'home_page',
            $this->pagePlanWithBlock([
                'image_intent' => [
                    'needs_image' => true,
                    'image_role' => 'hero_image',
                    'image_subject' => 'blue sparkle icon badge',
                    'placement' => 'media_panel',
                    'visual_atmosphere' => 'premium operational SaaS interface',
                    'image_treatment' => 'crisp product mockup with subtle depth',
                    'reuse_policy' => 'reuse_when_intent_matches',
                    'css_motif' => '',
                ],
            ]),
            $this->singleHeroContract('en_US')
        );

        self::assertContains(
            'icon_only_image_subject',
            \array_map(static fn(array $issue): string => (string)($issue['code'] ?? ''), $report['issues'] ?? [])
        );
    }

    /**
     * @param array<string, mixed> $blockPatch
     * @return array<string, mixed>
     */
    private function pagePlanWithBlock(array $blockPatch): array
    {
        return [
            'page_goal' => 'Present the download value.',
            'theme_alignment_summary' => 'Dark gaming landing page.',
            'page_design_plan' => ['layout' => 'hero'],
            'blocks' => [[
                ...$this->baseHeroBlock(),
                ...$blockPatch,
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function baseHeroBlock(): array
    {
        return [
            'block_key' => 'hero',
            'page_flow_role' => 'opening',
            'content' => 'Download safely.',
            'field_plan' => [
                ['field' => 'description', 'sample' => 'Download the APK from the official source.'],
            ],
            'execution_script' => [
                'core_copy' => 'Download the APK from the official source.',
            ],
            'design_tags' => [
                'visual' => ['split hero'],
                'motion' => ['hover'],
                'interaction' => ['button'],
                'texture' => ['dark surface'],
                'responsive' => ['mobile stack'],
            ],
            'visual_signature' => [
                'composition_pattern' => 'split hero',
                'spatial_rhythm' => 'copy and media',
                'media_strategy' => 'generated image',
                'surface_treatment' => 'dark surface',
                'interaction_pattern' => 'button hover',
            ],
            'image_intent' => [
                'needs_image' => true,
                'image_role' => 'hero_image',
                'image_subject' => 'Teen Patti players',
                'placement' => 'media_panel',
                'visual_atmosphere' => 'premium gaming',
                'image_treatment' => 'rounded crop',
                'reuse_policy' => 'reuse_when_intent_matches',
                'css_motif' => '',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function singleHeroContract(string $contentLocale): array
    {
        return [
            'content_locale' => $contentLocale,
            'page_contracts' => [
                'home_page' => [
                    'page_type' => 'home_page',
                    'min_blocks' => 1,
                    'max_blocks' => 1,
                    'target_blocks' => 1,
                    'required_block_keys' => ['hero'],
                    'forbidden_block_keys' => [],
                    'field_plan_count' => 1,
                    'required_design_tag_keys' => ['visual', 'motion', 'interaction', 'texture', 'responsive'],
                    'requires_visual_signature' => true,
                    'visual_signature_keys' => [
                        'composition_pattern',
                        'spatial_rhythm',
                        'media_strategy',
                        'surface_treatment',
                        'interaction_pattern',
                    ],
                    'requires_image_intent' => true,
                    'image_intent_keys' => [
                        'needs_image',
                        'image_role',
                        'image_subject',
                        'placement',
                        'visual_atmosphere',
                        'image_treatment',
                        'reuse_policy',
                        'css_motif',
                    ],
                    'first_block_requires_generated_image' => true,
                ],
            ],
        ];
    }
}
