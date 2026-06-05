<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteStageOnePromptContractRenderer;
use PHPUnit\Framework\TestCase;

final class AiSiteStageOnePromptContractRendererTest extends TestCase
{
    public function testPageContractLocksDirectPlanJsonPageShapeAndExactBlockKeys(): void
    {
        $lines = (new AiSiteStageOnePromptContractRenderer())->renderPageContract($this->contract(), 'home_page');
        $prompt = \implode("\n", $lines);

        self::assertStringContainsString(
            'return exactly one JSON object representing plan_json.pages.home_page',
            $prompt
        );
        self::assertStringContainsString(
            'do not wrap it in plan_json, pages, page, home_page, blocks, sections, components, or markdown',
            $prompt
        );
        self::assertStringContainsString(
            'a top-level "home_page" key inside this returned page object is invalid',
            $prompt
        );
        self::assertStringContainsString(
            '["hero_download","game_showcase_or_features","trust_security","player_reviews","faq_or_rules","final_download_cta","bonus_steps"]',
            $prompt
        );
        self::assertStringContainsString('Complete page return skeleton', $prompt);
        self::assertStringContainsString('"page_design_plan"', $prompt);
        self::assertStringContainsString('"hero_download":{"block_key":"hero_download"', $prompt);
        self::assertStringContainsString('Top-level keys named block_key, page_flow_role, content, design_tags, visual_signature, image_intent, field_plan, or execution_script', $prompt);
        self::assertStringContainsString('Home first-block lock', $prompt);
        self::assertStringContainsString('the first dynamic block under plan_json.pages.home_page must be the Banner/Hero block', $prompt);
        self::assertStringContainsString('never place text-only stats, FAQ, reviews, support, or other body content before the home Banner/Hero', $prompt);
    }

    public function testRepairContractNamesNestedPageTypeWrapperFailure(): void
    {
        $lines = (new AiSiteStageOnePromptContractRenderer())->renderRepairContract($this->contract(), [
            'issues' => [
                [
                    'code' => 'invalid_block_count',
                    'path' => 'pages.home_page',
                    'actual' => 1,
                    'target' => 7,
                ],
                [
                    'code' => 'target_block_count_mismatch',
                    'path' => 'pages.home_page',
                    'actual' => 1,
                    'expected' => 7,
                ],
                [
                    'code' => 'missing_required_block_key',
                    'path' => 'pages.home_page.home_page.hero_download',
                ],
            ],
        ]);
        $prompt = \implode("\n", $lines);

        self::assertStringContainsString('representing plan_json.pages.{page_type}', $prompt);
        self::assertStringContainsString('Do not wrap the page in plan_json, pages, page, {page_type}', $prompt);
        self::assertStringContainsString('paths look like pages.{page_type}.{page_type}', $prompt);
        self::assertStringContainsString('actual=1 with target_block_count_mismatch', $prompt);
        self::assertStringContainsString('Delete that wrapper and rebuild direct block keys', $prompt);
        self::assertStringContainsString('paths look like pages.{page_type}.visual_signature.page_flow_role', $prompt);
        self::assertStringContainsString('returned one block object at page top level', $prompt);
    }

    /**
     * @return array<string, mixed>
     */
    private function contract(): array
    {
        return [
            'contract_version' => 'test-v1',
            'page_contracts' => [
                'home_page' => [
                    'min_blocks' => 6,
                    'max_blocks' => 8,
                    'target_blocks' => 7,
                    'required_block_keys' => [
                        'hero_download',
                        'game_showcase_or_features',
                        'trust_security',
                        'player_reviews',
                        'faq_or_rules',
                        'final_download_cta',
                    ],
                    'recommended_optional_block_keys' => [
                        'bonus_steps',
                        'responsible_play',
                    ],
                    'field_plan_count' => 3,
                ],
            ],
        ];
    }
}
