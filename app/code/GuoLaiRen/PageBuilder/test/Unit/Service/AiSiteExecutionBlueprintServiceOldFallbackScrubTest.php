<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\AiSiteExecutionBlueprintService;
use GuoLaiRen\PageBuilder\Service\AiSitePageBlueprintService;
use PHPUnit\Framework\TestCase;

final class AiSiteExecutionBlueprintServiceOldFallbackScrubTest extends TestCase
{
    public function testApplyStageOneIssueFallbacksScrubsOldBlockFallbackValues(): void
    {
        $service = new AiSiteExecutionBlueprintService(new AiSitePageBlueprintService());
        $method = new \ReflectionMethod($service, 'applyStageOneIssueFallbacks');
        $method->setAccessible(true);

        $planJson = [
            'pages' => [
                Page::TYPE_REFUND_POLICY => [
                    'page_label' => 'Refund Policy',
                    'page_goal' => 'Deliver clear and actionable page content for visitors.',
                    'theme_alignment_summary' => 'Refund Policy follows shared_prompt_context and keeps policy copy scannable.',
                    'blocks' => [
                        [
                            'block_key' => 'refund-overview',
                            'content' => 'Visible refund-overview content rendered for users.',
                            'field_plan' => [
                                ['field' => 'title', 'sample' => 'Visible title content for refund-overview', 'implementation_note' => 'Use a clear legal heading.'],
                                ['field' => 'description', 'sample' => 'Visible description content for refund-overview', 'implementation_note' => 'Summarize the rule in plain language.'],
                                ['field' => 'button_text', 'sample' => 'Visible button_text content for refund-overview', 'implementation_note' => 'Keep the action tied to the request path.'],
                            ],
                            'execution_script' => [
                                'core_copy' => 'Core copy for refund-overview stays visible and actionable.',
                                'feature_points' => [
                                    'Visible refund-overview content rendered on the page',
                                    'Shared theme typography and spacing applied to refund-overview',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $issues = [
            ['page_type' => Page::TYPE_REFUND_POLICY, 'block_key' => '__page__', 'field_path' => 'page_goal'],
            ['page_type' => Page::TYPE_REFUND_POLICY, 'block_key' => 'refund-overview', 'field_path' => 'content'],
            ['page_type' => Page::TYPE_REFUND_POLICY, 'block_key' => 'refund-overview', 'field_path' => 'execution_script.core_copy'],
            ['page_type' => Page::TYPE_REFUND_POLICY, 'block_key' => 'refund-overview', 'field_path' => 'field_plan.sample', 'field_index' => 0],
        ];

        $repaired = $method->invoke($service, $planJson, $issues, 'en_US');
        $page = $repaired['pages'][Page::TYPE_REFUND_POLICY] ?? [];
        $block = $page['blocks'][0] ?? [];

        self::assertSame(
            'Explain refund eligibility, timing, and request steps so customers can act with confidence.',
            (string)($page['page_goal'] ?? '')
        );
        self::assertStringNotContainsString('rendered for users', (string)($block['content'] ?? ''));
        self::assertStringContainsString('customer-facing details', (string)($block['content'] ?? ''));
        self::assertStringNotContainsString('stays visible and actionable', (string)($block['execution_script']['core_copy'] ?? ''));
        self::assertStringContainsString('why they can trust it', (string)($block['execution_script']['core_copy'] ?? ''));
        self::assertStringNotContainsString('Visible title content for refund-overview', (string)($block['field_plan'][0]['sample'] ?? ''));
    }
}
