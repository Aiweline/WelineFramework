<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Integration;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use Weline\Framework\Manager\ObjectManager;

final class AiSiteWorkbenchPendingResumeIntegrationTest extends AbstractAiSiteWorkbenchIntegrationHarness
{
    public function testWorkspacePromptsBeforeContinuingPendingTasksOrObservingRunningOperation(): void
    {
        $createPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-create-session',
            'POST',
            'postCreateSession'
        );

        self::assertTrue((bool)($createPayload['success'] ?? false), \json_encode($createPayload, \JSON_UNESCAPED_UNICODE));
        $publicId = (string)($createPayload['public_id'] ?? '');
        self::assertNotSame('', $publicId);

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
        self::assertStringContainsString('function startPlanGenerationForSelection(triggerBtn, selectedTypes)', $html);
        self::assertStringContainsString('function confirmCurrentPlanAndMaybeBuild()', $html);
        self::assertStringContainsString('id="pb-ai-confirm-plan"', $html);
        self::assertStringContainsString('function maybePromptWorkspaceContinuation(data)', $html);
        self::assertStringContainsString('function requestExplicitResumeBuild()', $html);
        self::assertStringContainsString("showWorkspaceResumeConfirm(messages.resumePendingPrompt)", $html);
        self::assertStringContainsString("showWorkspaceResumeConfirm(messages.resumeRunningPrompt)", $html);
        self::assertStringContainsString('BackendConfirm.show(message, {', $html);
        self::assertStringNotContainsString('function maybeAutoStartBuildAfterWorkspaceSnapshot(data)', $html);
        self::assertStringNotContainsString('autoResumeActiveOperation', $html);
    }
}
