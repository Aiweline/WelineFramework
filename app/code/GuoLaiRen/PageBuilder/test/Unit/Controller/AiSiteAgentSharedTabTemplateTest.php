<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

final class AiSiteAgentSharedTabTemplateTest extends TestCase
{
    public function testSharedThemeTabRendersThemeDesignContractFields(): void
    {
        $moduleRoot = \dirname(__DIR__, 3);
        $script = \GuoLaiRen\PageBuilder\Test\Unit\View\Support\AiSiteWorkspaceScriptReader::loadBundledJavaScript();

        self::assertIsString($script);
        self::assertStringContainsString('function renderThemeDirectEditSection(planRoot, sharedBlocksHtml)', $script);
        self::assertStringContainsString('planRoot.theme_design', $script);
        self::assertStringContainsString('structured.theme_design.theme_purpose', $script);
        self::assertStringContainsString('root.theme_design && root.theme_design.color_scheme', $script);
        self::assertStringContainsString('themePurpose:', $script);
        self::assertStringContainsString('theme_design.theme_purpose', $script);
        self::assertStringContainsString('previewLabels.colorSystem', $script);
        self::assertStringContainsString('previewLabels.visualKeywords', $script);
        self::assertStringContainsString('previewLabels.toneOfVoice', $script);
    }

    public function testSharedThemeTabDoesNotRenderReasonDisclosureEntrypoints(): void
    {
        $moduleRoot = \dirname(__DIR__, 3);
        $script = \GuoLaiRen\PageBuilder\Test\Unit\View\Support\AiSiteWorkspaceScriptReader::loadBundledJavaScript();

        self::assertIsString($script);
        self::assertStringNotContainsString('function renderPreviewReasonDisclosure(label, reasonItems)', $script);
        self::assertStringNotContainsString('class="pb-ai-reason-disclosure d-inline-block ms-2"', $script);
        self::assertStringNotContainsString('reasonItems: collectPreviewReasonItems(headerPlan)', $script);
        self::assertStringNotContainsString('reasonItems: collectPreviewReasonItems(footerPlan)', $script);
        self::assertStringContainsString('renderThemeDirectEditSection(planRoot, sharedBlocksHtml)', $script);
        self::assertStringContainsString('themePurpose:', $script);
    }

    public function testPlanJsonPreviewRendersSingleStageDesignDetailsWithoutStageTwoTaskPlan(): void
    {
        $moduleRoot = \dirname(__DIR__, 3);
        $script = \GuoLaiRen\PageBuilder\Test\Unit\View\Support\AiSiteWorkspaceScriptReader::loadBundledJavaScript();

        self::assertIsString($script);
        self::assertStringContainsString('function planJsonPreviewHtml(markdownText, payload, options)', $script);
        self::assertStringContainsString('previewLabels.designDetails', $script);
        self::assertStringContainsString('previewLabels.implementationNote', $script);
        self::assertStringContainsString('function normalizePlanSharedBlocks(planRoot)', $script);
        self::assertStringContainsString('function buildSharedBlockFromStageOnePlan', $script);
        self::assertStringNotContainsString('function renderPreviewReasonDisclosure(label, reasonItems)', $script);
        self::assertStringNotContainsString('function collectPreviewReasonItems(value)', $script);
        self::assertStringNotContainsString('renderPreviewReasonDisclosure(previewLabels.reason, reasonItems)', $script);
        self::assertStringNotContainsString('collectTaskPlanPlanningReasonItems', $script);
        self::assertStringNotContainsString('renderTaskPlan', $script);
    }

    public function testConfirmedPlanModalUsesPlanJsonArtifactsAndBindsPreviewTabs(): void
    {
        $moduleRoot = \dirname(__DIR__, 3);
        $script = \GuoLaiRen\PageBuilder\Test\Unit\View\Support\AiSiteWorkspaceScriptReader::loadBundledJavaScript();

        self::assertIsString($script);
        self::assertStringContainsString('resolvePlanJsonArtifactsFromWorkspaceState(stateForPlanJson)', $script);
        self::assertStringNotContainsString("planJsonPreviewHtml('', { structured: planData, json: planData })", $script);
        self::assertStringNotContainsString("displayKind === 'projection'", $script);
        self::assertStringContainsString('bindPreviewTabButtons(planRenderedContent);', $script);
        self::assertStringContainsString('bindPreviewActionButtons(planRenderedContent);', $script);
    }

    public function testPlanJsonBlockCardsReadCanonicalGeneratedContentFallbacks(): void
    {
        $script = \GuoLaiRen\PageBuilder\Test\Unit\View\Support\AiSiteWorkspaceScriptReader::loadBundledJavaScript();

        self::assertIsString($script);
        self::assertStringContainsString('function stripPreviewHtmlToText(value)', $script);
        self::assertStringContainsString('fields.body', $script);
        self::assertStringContainsString('defaultConfig.body', $script);
        self::assertStringContainsString('aiData.body', $script);
        self::assertStringContainsString('stripPreviewHtmlToText(block && block.html)', $script);
        self::assertStringContainsString('function extractPreviewReadableText(value, depth)', $script);
        self::assertStringContainsString('extractPreviewReadableText(block && block.components)', $script);
        self::assertStringContainsString('extractPreviewReadableText(block && block.field_plan)', $script);
        self::assertStringContainsString('extractPreviewReadableText(block)', $script);
    }

    public function testCurrentPageRefineUsesDedicatedPageApi(): void
    {
        $moduleRoot = \dirname(__DIR__, 3);
        $controller = \file_get_contents($moduleRoot . '/Controller/Backend/AiSiteAgent.php');
        $workspace = \file_get_contents($moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace.phtml');
        $script = \GuoLaiRen\PageBuilder\Test\Unit\View\Support\AiSiteWorkspaceScriptReader::loadBundledJavaScript();

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

    public function testConfirmUpdatePlanRequiresTargetDomain(): void
    {
        $moduleRoot = \dirname(__DIR__, 3);
        $controller = \file_get_contents($moduleRoot . '/Controller/Backend/AiSiteAgent.php');
        $layout = \file_get_contents($moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/layout.phtml');
        $script = \GuoLaiRen\PageBuilder\Test\Unit\View\Support\AiSiteWorkspaceScriptReader::loadBundledJavaScript();

        self::assertIsString($controller);
        self::assertIsString($layout);
        self::assertIsString($script);
        self::assertStringContainsString('for="pb-ai-target-domain"', $layout);
        self::assertStringContainsString('pb-ai-target-domain-sync', $layout);
        self::assertStringContainsString('id="pb-ai-target-domain"', $layout);
        self::assertStringContainsString('function isTargetDomainRequiredForPlanActionSatisfied()', $script);
        self::assertStringContainsString('if (!isTargetDomainRequiredForPlanActionSatisfied()) {', $script);
        self::assertStringContainsString('setTargetDomainFieldInvalid(true);', $script);
        self::assertStringContainsString('messages.domainRequiredForPlanAction', $script);
        self::assertStringContainsString('TARGET_DOMAIN_REQUIRED', $controller);
    }

    public function testConfirmUpdatePlanStartsPlanFlowWithoutDependingOnBuildUrl(): void
    {
        $moduleRoot = \dirname(__DIR__, 3);
        $workspace = \file_get_contents($moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace.phtml');
        $script = \GuoLaiRen\PageBuilder\Test\Unit\View\Support\AiSiteWorkspaceScriptReader::loadBundledJavaScript();
        $compiledScriptPath = $moduleRoot . '/view/tpl/zh_Hans_CN/templates/Backend/AiSiteAgent/workspace/com_script-main.phtml';
        $compiledScript = \is_file($compiledScriptPath) ? \file_get_contents($compiledScriptPath) : null;

        self::assertIsString($workspace);
        self::assertIsString($script);
        self::assertStringContainsString('$runVirtualThemeUrl = (string)($this->getData(\'run_virtual_theme_url\') ?? $this->getData(\'start_build_url\') ?? \'\');', $workspace);
        self::assertStringNotContainsString('if (!runVirtualThemeUrl) { return; }', $script);
        self::assertStringContainsString('if (!startPlanUrl) {', $script);
        self::assertStringContainsString('toast(\'error\', messages.planStartUnavailable);', $script);
        self::assertStringContainsString('startPlanGenerationForSelection(triggerBtn, pageTypes);', $script);
        if (\is_string($compiledScript)) {
            self::assertStringNotContainsString('if (!runVirtualThemeUrl) { return; }', $compiledScript);
            self::assertStringContainsString('if (!startPlanUrl) {', $compiledScript);
            self::assertStringContainsString('toast(\'error\', messages.planStartUnavailable);', $compiledScript);
        }
    }

    public function testPlanJsonConfirmWorkspaceShowsPlanJsonTaskProgress(): void
    {
        $moduleRoot = \dirname(__DIR__, 3);
        $workspace = \file_get_contents($moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace.phtml');
        $layout = \file_get_contents($moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/layout.phtml');
        $script = \GuoLaiRen\PageBuilder\Test\Unit\View\Support\AiSiteWorkspaceScriptReader::loadBundledJavaScript();
        $runtime = \file_get_contents($moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        $buildQueueScript = \file_get_contents($moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/script-build-queue-progress.phtml');

        self::assertIsString($workspace);
        self::assertIsString($layout);
        self::assertIsString($script);
        self::assertIsString($runtime);
        self::assertIsString($buildQueueScript);
        self::assertStringContainsString("$currentStage !== 'plan'", $layout);
        self::assertStringContainsString('id="pb-ai-task-progress-heading"', $layout);
        self::assertStringContainsString('data-task-progress-summary="build"', $layout);
        self::assertStringContainsString('var planConfirmedState =', $script);
        self::assertStringContainsString('$state[\'plan_json\'][\'confirmed\']', $script);
        self::assertStringContainsString('function resolvePlanJsonFromWorkspaceState(workspaceState)', $script);
        self::assertStringNotContainsString('window.BackendConfirm.show(messages.taskPlanConfirmStartBuildQuestion', $script);
        self::assertStringContainsString('function setRunVirtualThemeButtonsDisabled(disabled)', $script);
        self::assertStringContainsString('ensurePlanJsonConfirmedBeforeBuild(triggerBtn, selectedTypes, options)', $script);
        self::assertStringContainsString('startConfirmedBuild(triggerBtn || currentPlanTriggerButton, normalizedTypes, Object.assign({}, opts, {}));', $script);
        self::assertStringContainsString("window.__pbBuildQueueProgress = {", $buildQueueScript);
        self::assertStringContainsString("syncFromWorkspaceState: syncFromWorkspaceState", $buildQueueScript);
        self::assertStringContainsString("window.__pbBuildQueueProgress.syncFromSsePayload(operation, payload || {}, eventKind);", $runtime);
        self::assertStringContainsString("workspace/script-build-queue-progress.phtml", $workspace);
    }
}
