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
        self::assertStringContainsString('function startPlanGenerationForSelection(triggerBtn, selectedTypes)', $html, 'missing plan start function');
        self::assertStringContainsString('function confirmCurrentPlanAndMaybeBuild()', $html, 'missing confirm/build function');
        self::assertStringContainsString('id="pb-ai-confirm-plan"', $html, 'missing confirm plan button');
        self::assertStringNotContainsString('function maybeAutoStartBuildAfterWorkspaceSnapshot(data)', $html);
        self::assertStringNotContainsString('autoResumeActiveOperation', $html);
    }

    public function testResumePromptSuppressesTerminalOrCompleteGeneratedWorkspace(): void
    {
        $script = (string)\file_get_contents(
            BP . 'app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-main.phtml'
        );

        self::assertStringContainsString('function allExpectedPageTypesHaveGeneratedSurface(workspaceState)', $script);
        self::assertStringContainsString('isTerminalActiveOperationStatus(activeStatus)', $script);
        self::assertStringContainsString('allExpectedPageTypesHaveGeneratedSurface(latestWorkspaceState)', $script);
        self::assertStringContainsString('function scheduleVisualEditResumePrompt()', $script);
        self::assertStringContainsString('function startOrObserveBuildFromVisualEditEntry()', $script);
        self::assertStringContainsString('BackendConfirm.show(confirmMessage, {', $script);
    }

    public function testSseErrorHandlersDoNotCloseTheStream(): void
    {
        $mainScript = (string)\file_get_contents(
            BP . 'app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-main.phtml'
        );
        $runtimeScript = (string)\file_get_contents(
            BP . 'app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml'
        );

        self::assertEventBlocksDoNotContain($mainScript, "terminal.on('error'", 'terminal.stop({ suppressTransportError: true });');
        self::assertEventBlocksDoNotContain($mainScript, "terminal.on('failed'", 'terminal.stop({ suppressTransportError: true });');
        self::assertEventBlocksDoNotContain($runtimeScript, "source.addEventListener('error'", 'closeOperationSource(source);');
        self::assertEventBlocksDoNotContain($runtimeScript, 'source.onerror = function ()', 'closeOperationSource(source);');
    }

    private static function assertEventBlocksDoNotContain(string $script, string $marker, string $forbidden): void
    {
        $offset = 0;
        $found = false;
        while (($start = \strpos($script, $marker, $offset)) !== false) {
            $found = true;
            $end = \strpos($script, "\n        });", $start);
            if ($end === false) {
                $end = \strpos($script, "\n        };", $start);
            }
            self::assertNotFalse($end, 'Could not find event block end for ' . $marker);
            $block = \substr($script, $start, $end - $start);
            self::assertStringNotContainsString($forbidden, $block, $marker . ' should not close SSE itself');
            $offset = $end + 1;
        }
        self::assertTrue($found, 'Could not find event marker ' . $marker);
    }
}
