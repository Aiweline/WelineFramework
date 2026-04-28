<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class AiSiteWorkspaceVisualEditFrontendLoopTest extends TestCase
{
    public function testWorkspaceEditModalLoadsVirtualMetadataSavesConfigAndRefreshesPreview(): void
    {
        $script = $this->workspaceScript();

        $modalBody = $this->extractFunctionBody($script, 'openWorkspaceVisualComponentConfigModal');

        self::assertStringContainsString('var context = resolveWorkspaceVisualComponentContext(payload);', $modalBody);
        self::assertStringContainsString('var layoutUrlObj = new URL(visualComponentLayoutFieldsUrl, window.location.href);', $modalBody);
        self::assertStringContainsString("layoutUrlObj.searchParams.set('public_id', context.public_id);", $modalBody);
        self::assertStringContainsString("layoutUrlObj.searchParams.set('page_type', context.page_type);", $modalBody);
        self::assertStringContainsString('var layoutResult = await getJson(layoutUrlObj.toString());', $modalBody);

        self::assertStringContainsString('var metadataUrlObj = new URL(visualComponentMetadataUrl, window.location.href);', $modalBody);
        self::assertStringContainsString("metadataUrlObj.searchParams.set('public_id', context.public_id);", $modalBody);
        self::assertStringContainsString("metadataUrlObj.searchParams.set('page_type', context.page_type);", $modalBody);
        self::assertStringContainsString("metadataUrlObj.searchParams.set('component_code', context.component_code);", $modalBody);
        self::assertStringContainsString("metadataUrlObj.searchParams.set('style_code', styleCode);", $modalBody);
        self::assertStringContainsString("metadataUrlObj.searchParams.set('region', context.region);", $modalBody);
        self::assertStringContainsString("metadataUrlObj.searchParams.set('index', String(context.index));", $modalBody);
        self::assertStringContainsString('var metadataResult = await getJson(metadataUrlObj.toString());', $modalBody);

        self::assertStringContainsString('var saveResult = await postJson(visualComponentUpdateConfigUrl, {', $modalBody);
        self::assertStringContainsString('public_id: context.public_id,', $modalBody);
        self::assertStringContainsString('page_type: context.page_type,', $modalBody);
        self::assertStringContainsString('component_code: context.component_code,', $modalBody);
        self::assertStringContainsString('region: context.region,', $modalBody);
        self::assertStringContainsString('index: context.index,', $modalBody);
        self::assertStringContainsString('config: newConfig', $modalBody);
        self::assertStringContainsString('refreshEmbeddedPreviewPreservingScroll();', $modalBody);
    }

    public function testWorkspacePreviewClickAndMessageActionsOpenTheSharedBlockEditor(): void
    {
        $script = $this->workspaceScript();
        $bridgeBody = $this->extractFunctionBody($script, 'bindEmbeddedPreviewFrameBridge');
        $messageBody = $this->extractFunctionBody($script, 'bindWorkspacePreviewMessages');

        self::assertStringContainsString("doc.querySelectorAll('.component-actions [data-pb-action]')", $bridgeBody);
        self::assertStringContainsString('var payload = buildEmbeddedPreviewPayload(wrapper, button);', $bridgeBody);
        self::assertStringContainsString("if (action === 'edit-block' || action === 'open-editor') {", $bridgeBody);
        self::assertStringContainsString('openBlockEditorModal(payload);', $bridgeBody);

        self::assertStringContainsString("if (payload.type === 'pb-component-action') {", $messageBody);
        self::assertStringContainsString("if (String(payload.action || '') === 'edit-block' || String(payload.action || '') === 'open-editor') {", $messageBody);
        self::assertStringContainsString('openBlockEditorModal(payload);', $messageBody);
    }

    public function testVisualPreviewEmitsComponentActionPayloadWithBlockIdentity(): void
    {
        $source = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Service/PageRenderService.php');
        self::assertIsString($source);

        self::assertStringContainsString('type: "pb-component-action"', $source);
        self::assertStringContainsString('action: this.getAttribute("data-pb-action") || ""', $source);
        self::assertStringContainsString('component: wrapper.dataset.component || ""', $source);
        self::assertStringContainsString('region: wrapper.dataset.region || ""', $source);
        self::assertStringContainsString('index: wrapper.dataset.index || ""', $source);
        self::assertStringContainsString('page_type: wrapper.dataset.pageType || document.body.getAttribute("data-page-type") || ""', $source);
    }

    public function testVisualPreviewFrameUsesFixedDeviceViewportHeightInsteadOfDocumentScrollHeight(): void
    {
        $script = $this->workspaceScript();
        $syncBody = $this->extractFunctionBody($script, 'syncVisualPreviewFrameHeight');

        self::assertStringNotContainsString('function measureVisualPreviewDocumentHeight', $script);
        self::assertStringContainsString('var fixedHeight = getPreviewDeviceMinHeight();', $syncBody);
        self::assertStringContainsString("frame.style.minHeight = fixedHeight + 'px';", $syncBody);
        self::assertStringContainsString("frame.style.height = fixedHeight + 'px';", $syncBody);
        self::assertStringNotContainsString('scrollHeight', $syncBody);
        self::assertStringNotContainsString('clientHeight', $syncBody);
    }

    public function testPublishedVirtualThemeComponentsOverrideDefaultComponentRegistry(): void
    {
        $source = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Service/PageRenderService.php');
        self::assertIsString($source);

        $virtualRenderCall = '$virtualThemeHtml = $this->renderVirtualThemeComponentHtml($code, $config, $page, $styleSettings, $mode);';
        $componentConfigAssign = '$this->assign(\'component_config\', $config);';
        self::assertStringContainsString($virtualRenderCall, $source);
        self::assertStringContainsString($componentConfigAssign, $source);
        self::assertStringContainsString('$componentFile = null;', $source);
        self::assertStringContainsString('$componentPath = null;', $source);
        self::assertLessThan(
            \strpos($source, $componentConfigAssign),
            \strpos($source, $virtualRenderCall),
            'Virtual theme component HTML must be selected before the component template is fetched.'
        );
    }

    public function testPublishCompletionStaysInWorkspaceInsteadOfRedirectingToPageIndex(): void
    {
        $runtime = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        self::assertIsString($runtime);
        $finishBody = $this->extractFunctionBody($runtime, 'finishOperation');

        self::assertStringContainsString("if (operation === 'publish') {", $finishBody);
        self::assertLessThan(
            \strpos($finishBody, "var redirectUrl = '';"),
            \strpos($finishBody, "if (operation === 'publish') {"),
            'Publish completion must hydrate workspace state before generic redirect handling.'
        );
    }

    public function testPublishChecklistButtonIsBoundOnWorkspaceLoad(): void
    {
        $script = $this->workspaceScript();
        $definition = \strpos($script, 'function bindPublishStageLogic()');
        $boot = \strrpos($script, 'bindPublishStageLogic();');

        self::assertIsInt($definition);
        self::assertIsInt($boot);
        self::assertGreaterThan(
            $definition,
            $boot,
            'Publish checklist and preview buttons must be bound after the function is defined.'
        );
    }

    public function testConfirmedTaskPlanButtonUsesResumeAwareBuildFlow(): void
    {
        $script = $this->workspaceScript();
        $bindBody = $this->extractFunctionBody($script, 'bindPlanStageLogic');

        self::assertStringContainsString("var confirmTaskPlanBtn = document.getElementById('pb-ai-confirm-task-plan');", $bindBody);
        self::assertStringContainsString('ensureTaskPlanConfirmedBeforeBuild(currentPlanTriggerButton, currentPlanSelection, {});', $bindBody);
        self::assertStringNotContainsString('confirmCurrentTaskPlanAndMaybeBuild(currentPlanTriggerButton, currentPlanSelection);', $bindBody);
    }

    public function testPublishStageStillKeepsPublishControlsAfterRemovingAiQualityRepairEntry(): void
    {
        $layout = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/layout.phtml');
        self::assertIsString($layout);
        self::assertStringNotContainsString('id="pb-ai-rebuild-publish-quality"', $layout);

        $visualEditCard = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/stages/sections/visual-edit-card.phtml');
        self::assertIsString($visualEditCard);
        self::assertStringNotContainsString('id="pb-ai-rebuild-publish-quality"', $visualEditCard);

        $publishCard = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/stages/sections/publish-card.phtml');
        self::assertIsString($publishCard);
        self::assertStringNotContainsString('id="pb-ai-rebuild-publish-quality"', $publishCard);
        self::assertStringContainsString('$pbAiLatestBuildFailed', $publishCard);
        self::assertStringContainsString('$pbAiPublishDisabled', $publishCard);

        $script = $this->workspaceScript();
        $bindBody = $this->extractFunctionBody($script, 'bindPublishStageLogic');
        $visualEditBindBody = $this->extractFunctionBody($script, 'bindVisualEditStageLogic');
        $syncBody = $this->extractFunctionBody($script, 'syncPublishRepairButtonDisabledState');
        $publishStartSyncBody = $this->extractFunctionBody($script, 'syncPublishStartButtonFromWorkspaceState');
        $terminalBody = $this->extractFunctionBody($script, 'markBuildOperationTerminalForUi');
        $resetBody = $this->extractFunctionBody($script, 'resetBuildStartUi');

        self::assertStringContainsString('bindPublishRepairButton();', $bindBody);
        self::assertStringContainsString('bindPublishRepairButton();', $visualEditBindBody);
        $publishRepairBindBody = $this->extractFunctionBody($script, 'bindPublishRepairButton');
        self::assertStringContainsString("var publishRepairBtn = document.getElementById('pb-ai-rebuild-publish-quality');", $publishRepairBindBody);
        self::assertStringContainsString('startPublishStageQualityRepair(publishRepairBtn);', $publishRepairBindBody);
        self::assertStringContainsString("document.getElementById('pb-ai-rebuild-publish-quality')", $syncBody);
        self::assertStringContainsString('isPublishRepairOperationLocked()', $syncBody);
        self::assertStringContainsString('isOperationRunning = false;', $terminalBody);
        self::assertStringContainsString('isPublishBlockingOperationName(normalizedOperation)', $terminalBody);
        self::assertStringContainsString("previousOperation === 'build'", $terminalBody);
        self::assertStringContainsString('latestWorkspaceState.active_operation = active;', $terminalBody);
        self::assertStringContainsString('latestWorkspaceState.publish_blocked_by_latest_ai_failure = true;', $terminalBody);
        self::assertStringContainsString('syncPublishRepairButtonDisabledState();', $terminalBody);
        self::assertStringContainsString('syncPublishStageEntryFromWorkspaceState(latestWorkspaceState);', $terminalBody);
        self::assertStringContainsString('syncPublishRepairButtonDisabledState();', $resetBody);
        self::assertStringContainsString('hasPublishBlockingLatestBuildFailureFromWorkspaceState(state)', $publishStartSyncBody);
        self::assertStringContainsString("document.querySelectorAll('.pb-ai-set-stage[data-stage=\"publish\"]')", $publishStartSyncBody);
        self::assertStringContainsString('function hasPublishBlockingLatestBuildFailureFromWorkspaceState', $script);
        self::assertStringContainsString('function hasPublishBlockingAiOperationRunningFromWorkspaceState', $script);
        self::assertStringContainsString('messages.publishBlockedLatestBuildFailure', $visualEditBindBody);
        self::assertStringContainsString('hasPublishBlockingAiOperationRunning: function (state)', $script);
        self::assertStringContainsString('markBuildOperationTerminalForUi: markBuildOperationTerminalForUi', $script);

        $runtime = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        self::assertIsString($runtime);
        self::assertStringContainsString('function resolveLivePreviewBridge()', $runtime);
        self::assertStringContainsString('function syncTerminalActiveOperationForRuntimeUi(active)', $runtime);
        self::assertStringContainsString('var workspaceSnapshotUrl =', $runtime);
        self::assertStringContainsString('function startDeferredQueueStatePoll(operation)', $runtime);
        self::assertStringContainsString('submitWorkspaceForm(workspaceSnapshotUrl, { public_id: publicId })', $runtime);
        self::assertStringContainsString('startDeferredQueueStatePoll(operation);', $runtime);
        self::assertStringContainsString('stopDeferredQueueStatePoll();', $runtime);
        self::assertStringContainsString('function normalizeTerminalOperationStatus(status)', $runtime);
        self::assertStringContainsString("return 'error';", $runtime);
        self::assertStringContainsString('syncTerminalActiveOperationForRuntimeUi(data.active_operation);', $runtime);
        self::assertStringContainsString("markBuildOperationTerminalForRuntimeUi(normalizedDoneOperation, 'error', lastServerError);", $runtime);
        self::assertStringContainsString("markBuildOperationTerminalForRuntimeUi(operation, 'error', lastServerError);", $runtime);
        self::assertStringContainsString('function hasPublishBlockingAiStateForRuntime()', $runtime);
        self::assertStringContainsString('function syncPublishStartButtonForRuntime()', $runtime);
        self::assertStringContainsString("messages.publishBlockedLatestBuildFailure", $runtime);

        $controller = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php');
        self::assertIsString($controller);
        self::assertStringContainsString('resolvePublishBlockingAiFailureFromWorkspaceState', $controller);
        self::assertStringContainsString('publish_blocked_by_latest_ai_failure', $controller);
        self::assertStringContainsString('Latest AI build completed successfully', $controller);
        self::assertStringContainsString('Current workspace is not ready to publish. Finish AI page generation first.', $controller);

        $sessionService = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Service/AiSiteAgentSessionService.php');
        self::assertIsString($sessionService);
        self::assertStringContainsString("'latest_build_failed'", $sessionService);
        self::assertStringContainsString("'latest_build_failure'", $sessionService);
        self::assertStringContainsString("'publish_blocked_by_latest_ai_failure'", $sessionService);
        self::assertStringContainsString("'publish_blocked_reason'", $sessionService);
    }

    public function testTaskPlanConfirmEndpointIsIdempotentForCompactedConfirmedBuildBlueprint(): void
    {
        $source = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php');
        self::assertIsString($source);
        $body = $this->extractFunctionBody($source, 'handleConfirmTaskPlan');
        $idempotentGuard = \strpos($body, '$this->buildTaskService->hasConfirmedTaskPlanForBuild($scope)');
        $notReadyError = \strpos($body, "'TASK_PLAN_NOT_READY'");

        self::assertIsInt($idempotentGuard);
        self::assertIsInt($notReadyError);
        self::assertLessThan(
            $notReadyError,
            $idempotentGuard,
            'Repeated task-plan confirmation must reuse compacted confirmed build blueprints before reporting a missing draft.'
        );
    }

    private function workspaceScript(): string
    {
        $script = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-main.phtml');
        self::assertIsString($script);

        return $script;
    }

    private function extractFunctionBody(string $script, string $functionName): string
    {
        $needle = 'function ' . $functionName . '(';
        $start = \strpos($script, $needle);
        self::assertIsInt($start, $functionName . ' must exist.');
        $next = \strpos($script, "\n    function ", $start + \strlen($needle));
        if ($next === false) {
            return \substr($script, $start);
        }

        return \substr($script, $start, $next - $start);
    }
}
