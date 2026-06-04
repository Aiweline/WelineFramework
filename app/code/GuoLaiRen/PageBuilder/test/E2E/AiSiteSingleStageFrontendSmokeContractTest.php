<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\E2E;

use GuoLaiRen\PageBuilder\Test\Unit\View\Support\AiSiteWorkspaceScriptReader;
use PHPUnit\Framework\TestCase;

final class AiSiteSingleStageFrontendSmokeContractTest extends TestCase
{
    public function testWorkspaceFrontendDoesNotExposeRemovedPlanStageLabels(): void
    {
        $moduleRoot = \dirname(__DIR__, 2);
        $files = [
            $moduleRoot . '/Controller/Backend/AiSiteAgent.php',
            $moduleRoot . '/Queue/AiSitePlanQueue.php',
            $moduleRoot . '/Service/AiSiteAgentQueueObserverStreamService.php',
            $moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace.phtml',
            $moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace-preview-unavailable.phtml',
            $moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/layout.phtml',
            $moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/modals.phtml',
            $moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/script-main.phtml',
            $moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/script-main-core.phtml',
            $moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/script-main-boot.phtml',
            $moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml',
            $moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/stages/sections/plan-inline-panel-body.phtml',
            $moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/stages/sections/plan-inline-panel-head.phtml',
            $moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/stages/sections/visual-edit-card.phtml',
        ];

        foreach ($files as $file) {
            self::assertFileExists($file);
            $content = \file_get_contents($file);
            self::assertIsString($content);
            self::assertDoesNotMatchRegularExpression('/方案1|阶段一方案|第二阶段|第三阶段|阶段三|共享任务|整个阶段/u', $content, $file);
        }
    }

    public function testConfirmedBlueprintPreviewUsesNonEmptySingleStageFallbacks(): void
    {
        $moduleRoot = \dirname(__DIR__, 2);
        $script = AiSiteWorkspaceScriptReader::loadBundledJavaScript();
        self::assertStringContainsString('resolvePlanJsonArtifactsFromWorkspaceState', $script);
        self::assertStringContainsString('hasPlanJsonFlowEvidence', $script);
        self::assertStringContainsString('shouldUsePlanJsonFlow', $script);
        self::assertStringContainsString('planJson:', $script);
    }

    public function testStageOnePreviewReadsPrunedStructuredPlanFields(): void
    {
        $moduleRoot = \dirname(__DIR__, 2);
        $script = AiSiteWorkspaceScriptReader::loadBundledJavaScript();
        self::assertStringContainsString('pickStructuredPlanRoot', $script);
        self::assertStringContainsString('plan.structured', $script);
        self::assertStringContainsString('payload.structured_plan', $script);
        self::assertStringContainsString('payload.plan_json', $script);
        self::assertStringContainsString('parsed.plan_json', $script);
    }

    public function testConfirmedPlanModalBindsPreviewInteractionsAfterRendering(): void
    {
        $moduleRoot = \dirname(__DIR__, 2);
        $script = AiSiteWorkspaceScriptReader::loadBundledJavaScript();
        self::assertStringContainsString('bindPreviewActionButtons(planRenderedContent);', $script);
        self::assertStringContainsString('bindPreviewTabButtons(planRenderedContent);', $script);
        self::assertStringContainsString('bindPreviewSortables(planRenderedContent);', $script);
        self::assertStringContainsString('bindStageOnePlanFieldEditors(planRenderedContent);', $script);
    }

    public function testPlanPreviewBlockActionsUseDirectHandlersWithoutInlineClickFallbacks(): void
    {
        $moduleRoot = \dirname(__DIR__, 2);
        $script = AiSiteWorkspaceScriptReader::loadBundledJavaScript();
        self::assertStringContainsString('function bindPreviewActionButtonDirectHandlers(root)', $script);
        self::assertStringContainsString("root.querySelectorAll('.pb-ai-preview-action-btn')", $script);
        self::assertStringContainsString("button.dataset.pbPreviewActionButtonBound = '1';", $script);
        self::assertStringContainsString('handlePreviewActionClick(button);', $script);
        self::assertStringContainsString('document.body.appendChild(modalEl);', $script);
        self::assertStringContainsString('detail.__pbBlockOpKeepOpen !== true', $script);
        self::assertStringContainsString('payload.__pbBlockOpKeepOpen = true;', $script);
        self::assertStringContainsString('markPlanBlockMutationStatus(\'pending\', \'\');', $script);
        self::assertStringContainsString('function dispatchBlockOperationModalDetail(detail, modalEl)', $script);
        self::assertStringNotContainsString('onclick="window.PbAiWorkspacePreviewAction', $script);
    }

    public function testPlanPreviewBlockActionToolbarIsVisibleByDefault(): void
    {
        $moduleRoot = \dirname(__DIR__, 2);
        $layout = \file_get_contents($moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/layout.phtml');

        self::assertIsString($layout);
        self::assertMatchesRegularExpression('/\\.pb-ai-plan-preview-actions\\s*\\{(?<rule>.*?)\\}/s', $layout);
        \preg_match('/\\.pb-ai-plan-preview-actions\\s*\\{(?<rule>.*?)\\}/s', $layout, $matches);
        $rule = (string)($matches['rule'] ?? '');
        self::assertStringContainsString('opacity: 1;', $rule);
        self::assertStringContainsString('pointer-events: auto;', $rule);
        self::assertStringContainsString('transform: none;', $rule);
        self::assertStringNotContainsString('opacity: 0;', $rule);
        self::assertStringNotContainsString('pointer-events: none;', $rule);
    }
}
