<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Integration;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use Weline\Framework\Manager\ObjectManager;

final class AiSiteWorkbenchBlockSseChatIntegrationTest extends AbstractAiSiteWorkbenchIntegrationHarness
{
    public function testWorkspaceAutoAppliesBlockSseResultToCurrentBlock(): void
    {
        $modals = (string)\file_get_contents(
            BP . 'app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/modals.phtml'
        );
        $script = (string)\file_get_contents(
            BP . 'app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-main.phtml'
        );

        self::assertStringContainsString('var pendingBlockSseResult = null;', $script);
        self::assertStringContainsString('var pendingBlockSseStart = null;', $script);
        self::assertStringContainsString('blockSseDoneConfirm', $script);
        self::assertStringContainsString('id="pb-ai-block-sse-confirm-start"', $modals);
        self::assertStringContainsString('function confirmPendingBlockSseStart()', $script);
        self::assertStringContainsString("startBtn.addEventListener('click', confirmPendingBlockSseStart);", $script);
        self::assertStringContainsString("terminal.on('open', function ()", $script);
        self::assertStringContainsString('confirmLabel: messages.blockSseConfirmRebuild', $script);
        self::assertStringContainsString('confirmLabel: messages.blockSseConfirmRefine', $script);
        self::assertStringContainsString("pendingBlockSseResult = payload && typeof payload === 'object' ? payload : null;", $script);
        self::assertStringContainsString("function applyPendingBlockSseResultWithSnapshot(options)", $script);
        self::assertStringContainsString("return fetchWorkspaceSnapshotStateForBlockRefresh().then(function () {", $script);
        self::assertStringContainsString("applyPendingBlockSseResultWithSnapshot({", $script);
        self::assertStringContainsString("contextEl.textContent = applied ? messages.blockSseApplied : messages.blockSseDoneConfirm;", $script);
        self::assertStringContainsString("if (!pendingBlockSseResult || !effectiveState || !effectiveState.virtual_pages_by_type)", $script);
        self::assertStringContainsString("updateVirtualBlockState(targetPageType, nextBlock);", $script);
        self::assertStringContainsString("replaceCurrentBlockHtml(targetPageType, nextBlock);", $script);
        self::assertStringContainsString("toast('success', messages.blockSseApplied);", $script);
    }

    public function testBlockQueueObserverKeepsStreamOpenUntilCompletion(): void
    {
        /** @var AiSiteAgent $controller */
        $controller = ObjectManager::getInstance(AiSiteAgent::class);
        $method = new \ReflectionMethod(AiSiteAgent::class, 'shouldKeepQueuedObserverStreamOpen');
        $method->setAccessible(true);

        self::assertTrue((bool)$method->invoke($controller, 'block_regenerate'));
        self::assertTrue((bool)$method->invoke($controller, 'plan'));
        self::assertTrue((bool)$method->invoke($controller, 'task_plan'));
        self::assertTrue((bool)$method->invoke($controller, 'build'));
        self::assertTrue((bool)$method->invoke($controller, 'block_partial_patch'));
        self::assertTrue((bool)$method->invoke($controller, 'regenerate_page'));
    }
}
