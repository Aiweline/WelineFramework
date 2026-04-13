<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Integration;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use Weline\Framework\Manager\ObjectManager;

final class AiSiteWorkbenchPendingResumeIntegrationTest extends AbstractAiSiteWorkbenchIntegrationHarness
{
    public function testWorkspaceAutoContinuesPendingTasksWithoutConfirmationDialog(): void
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
        self::assertStringContainsString('function maybeAutoStartBuildAfterWorkspaceSnapshot(data)', $html);
        self::assertStringContainsString('return executeAutoStartBuildAfterWorkspaceSnapshot(data);', $html);
        self::assertStringNotContainsString(
            'BackendConfirm.show',
            $html,
            'Entering the workspace should auto-continue unfinished build tasks instead of blocking on a confirmation dialog.'
        );
        self::assertStringNotContainsString('检测到未完成任务，是否继续生成？', $html);
    }
}
