<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\AiSiteExecutionBlueprintService;
use GuoLaiRen\PageBuilder\Service\AiSitePageBlueprintService;
use PHPUnit\Framework\TestCase;

final class AiSiteExecutionBlueprintServiceRefundPolicyGoalRepairTest extends TestCase
{
    public function testChinesePageGoalHelpersAlwaysReturnStrings(): void
    {
        $service = new AiSiteExecutionBlueprintService(new AiSitePageBlueprintService());
        $goalMethod = new \ReflectionMethod($service, 'resolvePageGoal');
        $goalMethod->setAccessible(true);
        $whyMethod = new \ReflectionMethod($service, 'resolvePageWhy');
        $whyMethod->setAccessible(true);
        $keywordsMethod = new \ReflectionMethod($service, 'buildSecondaryKeywords');
        $keywordsMethod->setAccessible(true);

        self::assertSame(
            '承接核心意图，说明价值，并露出主要转化动作。',
            $goalMethod->invoke($service, Page::TYPE_HOME, '首页', 'zh_Hans_CN')
        );
        self::assertSame(
            '首页是核心流量入口，需要统一价值叙事与导航。',
            $whyMethod->invoke($service, Page::TYPE_HOME, '首页', 'zh_Hans_CN')
        );
        self::assertSame(
            ['首页 指南', '首页 常见问题', '品牌介绍', '核心卖点'],
            $keywordsMethod->invoke($service, Page::TYPE_HOME, '首页')
        );
    }

    public function testRepairAiStageOnePlanJsonBeforeValidationReplacesPromptLikeRefundPolicyGoal(): void
    {
        $service = new AiSiteExecutionBlueprintService(new AiSitePageBlueprintService());
        $method = new \ReflectionMethod($service, 'repairAiStageOnePlanJsonBeforeValidation');
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
                            'content' => 'Explain refund windows, proof requirements, and request routes for customers.',
                            'field_plan' => [
                                ['field' => 'title', 'sample' => 'Refund overview', 'implementation_note' => 'Use a clear legal heading.'],
                                ['field' => 'description', 'sample' => 'Customers can confirm when refunds apply and what evidence is required.', 'implementation_note' => 'Summarize the key rule in plain language.'],
                                ['field' => 'button_text', 'sample' => 'Start a refund request', 'implementation_note' => 'Keep the action tied to the refund process.'],
                            ],
                            'execution_script' => [
                                'core_copy' => 'Customers can review eligibility, timing, and request steps without reading internal instructions.',
                                'feature_points' => [
                                    'Show refund windows by order state.',
                                    'List the documents or proof customers need.',
                                    'Link the next action to the support request path.',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'theme_design' => [
                'theme_purpose' => 'Build trust with clear guidance.',
                'selection_reason' => 'Matches the need for trustworthy policy pages.',
                'color_scheme' => ['name' => 'Slate', 'primary' => '#223344', 'accent' => '#77aa88'],
                'typography_spacing_radius' => ['font_family' => 'Source Sans 3', 'spacing_scale' => 'comfortable', 'radius_scale' => 'soft'],
                'tone_of_voice' => 'Clear and reassuring',
                'cta_tone' => 'Helpful and direct',
                'forbidden_styles' => ['flashy gradients'],
            ],
        ];

        $repaired = $method->invoke(
            $service,
            $planJson,
            [Page::TYPE_REFUND_POLICY],
            'en_US',
            'Need a refund policy page that clearly explains eligibility and request steps.'
        );

        $goal = (string)($repaired['pages'][Page::TYPE_REFUND_POLICY]['page_goal'] ?? '');
        self::assertSame(
            'Explain refund eligibility, timing, and request steps so customers can act with confidence.',
            $goal
        );
    }

    public function testRepairAiStageOnePlanJsonBeforeValidationReplacesOldChineseRefundPolicyGoal(): void
    {
        $service = new AiSiteExecutionBlueprintService(new AiSitePageBlueprintService());
        $repairMethod = new \ReflectionMethod($service, 'repairAiStageOnePlanJsonBeforeValidation');
        $repairMethod->setAccessible(true);
        $weakMethod = new \ReflectionMethod($service, 'isWeakStageOnePageGoal');
        $weakMethod->setAccessible(true);

        $planJson = [
            'pages' => [
                Page::TYPE_REFUND_POLICY => [
                    'page_label' => '退款政策',
                    'page_goal' => '为访客提供清晰且可执行的页面内容。',
                    'theme_alignment_summary' => '退款政策保持清晰、可信、可快速扫描。',
                    'blocks' => [
                        [
                            'block_key' => 'refund-overview',
                            'content' => '清楚说明退款条件、处理时效和申请入口。',
                            'field_plan' => [
                                ['field' => 'title', 'sample' => '退款政策概览', 'implementation_note' => '使用清晰标题。'],
                                ['field' => 'description', 'sample' => '客户可以确认退款适用条件和所需材料。', 'implementation_note' => '用短句说明关键规则。'],
                                ['field' => 'button_text', 'sample' => '提交退款申请', 'implementation_note' => '动作连接到退款申请流程。'],
                            ],
                            'execution_script' => [
                                'core_copy' => '客户可以直接了解适用条件、处理时效和申请步骤。',
                                'feature_points' => [
                                    '展示不同订单状态的退款窗口。',
                                    '列出客户需要准备的材料。',
                                    '提供联系支持或提交申请的下一步。',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'theme_design' => [
                'theme_purpose' => '建立信任并清楚呈现政策。',
                'selection_reason' => '适合可信、清楚的政策页面。',
                'color_scheme' => ['name' => 'Slate', 'primary' => '#223344', 'accent' => '#77aa88'],
                'typography_spacing_radius' => ['font_family' => 'Source Sans 3', 'spacing_scale' => 'comfortable', 'radius_scale' => 'soft'],
                'tone_of_voice' => '清晰可靠',
                'cta_tone' => '直接有帮助',
                'forbidden_styles' => ['夸张动效'],
            ],
        ];

        $repaired = $repairMethod->invoke(
            $service,
            $planJson,
            [Page::TYPE_REFUND_POLICY],
            'zh_Hans_CN',
            '需要一个清楚说明退款条件、时效和申请入口的退款政策页面。'
        );

        $goal = (string)($repaired['pages'][Page::TYPE_REFUND_POLICY]['page_goal'] ?? '');
        self::assertSame('退款政策清楚呈现适用条件、处理时效和申请路径，帮助客户判断并提交请求。', $goal);
        self::assertFalse($weakMethod->invoke($service, $goal, Page::TYPE_REFUND_POLICY));
    }
}
