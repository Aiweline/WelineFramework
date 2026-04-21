<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

final class AiSiteAgentSharedTabTemplateTest extends TestCase
{
    public function testSharedThemeTabRendersThemeDesignContractFields(): void
    {
        $moduleRoot = \dirname(__DIR__, 3);
        $script = \file_get_contents($moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/script-main.phtml');

        self::assertIsString($script);
        self::assertStringContainsString('function renderThemeSummarySection(planRoot)', $script);
        self::assertStringContainsString('planRoot.theme_design', $script);
        self::assertStringContainsString('themeDesign && themeDesign.theme_purpose', $script);
        self::assertStringContainsString('themeDesign && themeDesign.color_scheme', $script);
        self::assertStringContainsString('themeDesign && themeDesign.selection_reason', $script);
        self::assertStringContainsString('themeDesign && themeDesign.forbidden_styles', $script);
        self::assertStringContainsString('previewLabels.themePurpose', $script);
        self::assertStringContainsString('previewLabels.colorSystem', $script);
        self::assertStringContainsString('previewLabels.selectionReason', $script);
        self::assertStringContainsString('previewLabels.forbiddenStyles', $script);
    }

    public function testSharedThemeTabRendersReasonDisclosureEntrypoints(): void
    {
        $moduleRoot = \dirname(__DIR__, 3);
        $script = \file_get_contents($moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/script-main.phtml');

        self::assertIsString($script);
        self::assertStringContainsString('function renderPreviewReasonDisclosure(label, reasonItems)', $script);
        self::assertStringContainsString('class="pb-ai-reason-disclosure d-inline-block ms-2"', $script);
        self::assertStringContainsString('>?</summary>', $script);
        self::assertStringContainsString('renderPreviewSectionHeadingWithReason(previewLabels.themeSummary, selectionReason)', $script);
        self::assertStringContainsString('reasonItems: collectPreviewReasonItems(headerPlan)', $script);
        self::assertStringContainsString('reasonItems: collectPreviewReasonItems(footerPlan)', $script);
    }

    public function testStageTwoTaskPlanCardsRenderQuestionMarkReasonEntrypoints(): void
    {
        $moduleRoot = \dirname(__DIR__, 3);
        $script = \file_get_contents($moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/script-main.phtml');

        self::assertIsString($script);
        self::assertStringContainsString('function collectTaskPlanPlanningReasonItems(task, blockTask, planContext)', $script);
        self::assertStringContainsString('blockTaskObj.planning_reason', $script);
        self::assertStringContainsString('taskObj.planning_reason', $script);
        self::assertStringContainsString('contextObj.planning_reason', $script);
        self::assertStringContainsString('taskObj.reason', $script);
        self::assertStringContainsString('contextObj.reason', $script);
        self::assertStringContainsString('var planningReasonItems = collectTaskPlanPlanningReasonItems(task, blockTask, planContext);', $script);
        self::assertStringContainsString('renderPreviewReasonDisclosure(previewLabels.reason, planningReasonItems)', $script);
        self::assertStringContainsString('>?</summary>', $script);
    }

    public function testCurrentPageRefineUsesDedicatedPageApi(): void
    {
        $moduleRoot = \dirname(__DIR__, 3);
        $controller = \file_get_contents($moduleRoot . '/Controller/Backend/AiSiteAgent.php');
        $workspace = \file_get_contents($moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace.phtml');
        $script = \file_get_contents($moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/script-main.phtml');

        self::assertIsString($controller);
        self::assertIsString($workspace);
        self::assertIsString($script);
        self::assertStringContainsString('refine_plan_page_url', $controller);
        self::assertStringContainsString('public function postRefinePlanPage(): string', $controller);
        self::assertStringContainsString('return $this->handleRefinePlanPage();', $controller);
        self::assertStringContainsString('$refinePlanPageUrl', $workspace);
        self::assertStringContainsString('var refinePlanPageUrl', $script);
        self::assertStringContainsString('function shouldUsePlanPageRefineApi(mode, actionContext)', $script);
        self::assertStringContainsString("String(context.scopeKind || '').trim() === 'page'", $script);
        self::assertStringContainsString('function startPlanPageRefineRequest(promptText, targetScope, actionContext)', $script);
        self::assertStringContainsString('postForm(refinePlanPageUrl', $script);
        self::assertStringContainsString('page_type: pageType', $script);
        self::assertStringContainsString('instruction: instruction', $script);
    }
}
