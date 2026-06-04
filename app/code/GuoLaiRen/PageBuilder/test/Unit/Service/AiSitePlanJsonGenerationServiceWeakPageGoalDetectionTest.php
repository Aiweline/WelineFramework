<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\AiSitePlanJsonGenerationService;
use GuoLaiRen\PageBuilder\Service\AiSitePageBlueprintService;
use PHPUnit\Framework\TestCase;

final class AiSitePlanJsonGenerationServiceWeakPageGoalDetectionTest extends TestCase
{
    public function testDetailedBriefUsesLocalRequirementExpansion(): void
    {
        $service = new AiSitePlanJsonGenerationService(new AiSitePageBlueprintService());
        $shouldUse = new \ReflectionMethod($service, 'shouldUseLocalStageOneRequirementExpansion');
        $shouldUse->setAccessible(true);
        $builder = new \ReflectionMethod($service, 'buildLocalStageOneRequirementExpansion');
        $builder->setAccessible(true);

        $pageTypes = [Page::TYPE_HOME, Page::TYPE_ABOUT, Page::TYPE_CONTACT, Page::TYPE_BLOG_LIST];
        $brief = 'India Card Game APK Guide recommends safe rummy, teen patti, ludo, carrom, chess, and casual card game APK downloads for Indian Android players.';
        $scope = [
            'brief_description' => $brief,
            'site_title' => 'India Card Game APK Guide',
        ];
        $websiteProfile = [
            'brief_description' => $brief,
            'site_title' => 'India Card Game APK Guide',
        ];

        self::assertTrue($shouldUse->invoke($service, $scope, $websiteProfile, $pageTypes, $brief));

        $expansion = $builder->invoke($service, $scope, $websiteProfile, $pageTypes, $brief);
        self::assertNotSame('', (string)($expansion['expanded_brief'] ?? ''));
        self::assertNotSame('', (string)($expansion['planning_summary'] ?? ''));
        self::assertNotSame('', (string)($expansion['primary_cta'] ?? ''));
        self::assertCount(\count($pageTypes), $expansion['page_strategy'] ?? []);
    }

    public function testCollectStageOneIssuesMarksRemovedRefundPolicyPageGoalAsInvalid(): void
    {
        $service = new AiSitePlanJsonGenerationService(new AiSitePageBlueprintService());
        $method = new \ReflectionMethod($service, 'collectAiStageOneProblemIssues');
        $method->setAccessible(true);

        $planJson = [
            'pages' => [
                Page::TYPE_REFUND_POLICY => [
                    'page_goal' => 'Deliver clear and actionable page content for visitors.',
                    'theme_alignment_summary' => 'Refund Policy follows shared_prompt_context and keeps policy copy scannable.',
                    'refund_overview' => [
                            'block_key' => 'refund-overview',
                            'content' => 'The refund overview block shows concrete customer-facing details, trust signals, and the next action on the page.',
                            'field_plan' => [
                                ['field' => 'title', 'sample' => 'Refund overview', 'implementation_note' => 'Use a clear legal heading.'],
                                ['field' => 'description', 'sample' => 'Customers can confirm when refunds apply and what evidence is required.', 'implementation_note' => 'Summarize the key rule in plain language.'],
                                ['field' => 'button_text', 'sample' => 'Start a refund request', 'implementation_note' => 'Keep the action tied to the request path.'],
                            ],
                            'execution_script' => [
                                'core_copy' => 'The refund overview block explains what customers get, why they can trust it, and which next step they can take now.',
                                'feature_points' => [
                                    'Show refund windows by order state.',
                                    'List the documents or proof customers need.',
                                ],
                            ],
                    ],
                ],
            ],
        ];

        $issues = $method->invoke($service, $planJson, [Page::TYPE_REFUND_POLICY], 'Need a refund policy page.', 'en_US');

        self::assertIsArray($issues);
        self::assertNotEmpty($issues);
        self::assertSame('page_goal', (string)($issues[0]['field_path'] ?? ''));
    }
}
