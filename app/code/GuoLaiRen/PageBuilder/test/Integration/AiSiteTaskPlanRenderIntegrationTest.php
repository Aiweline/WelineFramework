<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Integration;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use Weline\Framework\Manager\ObjectManager;

final class AiSiteTaskPlanRenderIntegrationTest extends AbstractAiSiteWorkbenchIntegrationHarness
{
    public function testWorkspaceRendersSecondStageTaskPlanFlowHooks(): void
    {
        $createPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-create-session',
            'POST',
            'postCreateSession'
        );

        self::assertTrue((bool)($createPayload['success'] ?? false), \json_encode($createPayload, \JSON_UNESCAPED_UNICODE));
        $publicId = (string)($createPayload['public_id'] ?? '');
        self::assertNotSame('', $publicId);

        $session = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($session);
        $this->sessionService->mergeScope($session->getId(), 1, [
            'plan_markdown' => "# Demo plan\n\n- scope item",
            'plan_confirmed' => 1,
        ]);
        self::assertTrue($this->sessionService->setStage($session->getId(), 1, 'visual_edit'));

        $this->prepareBackendRequest(
            '/pagebuilder/backend/ai-site-agent/workspace',
            'GET',
            'workspace',
            ['public_id' => $publicId]
        );

        /** @var AiSiteAgent $controller */
        $controller = ObjectManager::getInstance(AiSiteAgent::class);
        $html = $controller->workspace();

        self::assertIsString($html);
        self::assertStringContainsString('var planSseUrl =', $html);
        self::assertStringContainsString('pb-ai-plan-sse-terminal', $html);
        self::assertStringContainsString('pb-ai-plan-mode-refine', $html);
        self::assertStringContainsString('pb-ai-plan-mode-rebuild', $html);
        self::assertStringContainsString('function startPlanModeStream(mode', $html);
        self::assertStringContainsString('function bindPlanSseTerminalHandlers()', $html);
        self::assertStringContainsString('refine', $html);
        self::assertStringContainsString('rebuild', $html);
        self::assertStringContainsString('var startTaskPlanUrl =', $html);
        self::assertStringContainsString('var confirmTaskPlanUrl =', $html);
        self::assertStringContainsString('var taskPlanSseUrl =', $html);
        self::assertStringContainsString('var hasVirtualThemePlanState =', $html);
        self::assertStringContainsString('var taskPlanConfirmedState =', $html);
        self::assertStringContainsString('pb-ai-stage-status-summary', $html);
        self::assertStringContainsString('pb-ai-stage-status-plan-badge', $html);
        self::assertStringContainsString('pb-ai-stage-status-task-plan-badge', $html);
        self::assertStringContainsString('pb-ai-stage-status-build-badge', $html);
        self::assertStringContainsString('function updateStageStatusSummary(workspaceState)', $html);
        self::assertStringContainsString('pb-ai-task-plan-panel-collapse', $html);
        self::assertStringContainsString('pb-ai-task-plan-accordion', $html);
        self::assertStringContainsString('function isTaskPlanRequiredResponse(data)', $html);
        self::assertStringContainsString('function startTaskPlanGenerationForBuild(triggerBtn, selectedTypes, options)', $html);
        self::assertStringContainsString('function confirmCurrentTaskPlanAndMaybeBuild(triggerBtn, selectedTypes, options)', $html);
        self::assertStringContainsString("workspaceTerminal.on('log', function (event) {", $html);
        self::assertStringContainsString('function normalizeQueuedStageWorkspaceLogEntry(entry)', $html);
        self::assertStringContainsString('function mirrorWorkspaceLogToQueuedStageUi(entry)', $html);
        self::assertStringContainsString('function startTaskPlanModeStream(mode', $html);
        self::assertStringContainsString("var persistedTaskPlanStructured = (workspaceState.task_plan_structured && typeof workspaceState.task_plan_structured === 'object')", $html);
        self::assertStringContainsString('if (isNonEmptyObject(payload.structured)) {', $html);
        self::assertStringContainsString('var taskPlanDraftStructured = hasNonEmptyPlanArtifact(persistedTaskPlanStructured)', $html);
        self::assertStringContainsString('function isTaskPlanStructuredRoot(planRoot)', $html);
        self::assertStringContainsString('function renderTaskPlanStructuredPreviewHtml(planRoot)', $html);
        self::assertStringContainsString('function bindTaskPlanFieldEditors(container)', $html);
        self::assertStringContainsString('function applyTaskPlanFieldEditorValue(editor)', $html);
        self::assertStringContainsString('data-pb-task-plan-field-edit="1"', $html);
        self::assertStringContainsString('taskPlanFieldEditPending', $html);
        self::assertStringContainsString("var pageTasks = planRoot.page_tasks && typeof planRoot.page_tasks === 'object' ? planRoot.page_tasks : {};", $html);
        self::assertStringContainsString("tab.setAttribute('data-generation-status', 'idle');", $html);
        self::assertStringContainsString('function applyPreviewTabGenerationStatus(tab)', $html);
        self::assertStringContainsString("var pageCompleted = payload.page_completed !== false;", $html);
        self::assertStringContainsString('detect_bootstrap_task_plan', $html);
        self::assertStringContainsString('refine_task_plan', $html);
        self::assertStringContainsString('rebuild_task_plan', $html);
        self::assertStringContainsString('pb-ai-task-plan-sse-terminal', $html);
        self::assertStringContainsString('pb-ai-go-publish-stage', $html);
        self::assertStringContainsString('pb-ai-publish-stage-entry-hint', $html);
        self::assertStringContainsString('function syncPublishStageEntryFromWorkspaceState(workspaceState)', $html);
        self::assertStringContainsString('阶段二任务计划已保存，是否立即开始构建？', $html);
    }
}
