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
        $script = \GuoLaiRen\PageBuilder\Test\Unit\View\Support\AiSiteWorkspaceScriptReader::loadBundledJavaScript();

        self::assertStringNotContainsString('var pendingBlockSseResult = null;', $script);
        self::assertStringNotContainsString('var pendingBlockSseStart = null;', $script);
        self::assertStringContainsString('blockSseDoneConfirm', $script);
        self::assertStringContainsString('id="pb-ai-block-sse-confirm-start"', $modals);
        self::assertStringNotContainsString('function confirmPendingBlockSseStart()', $script);
        self::assertStringNotContainsString("startBtn.addEventListener('click', confirmPendingBlockSseStart);", $script);
        self::assertStringNotContainsString("terminal.on('open', function ()", $script);
        self::assertStringNotContainsString('confirmLabel: messages.blockSseConfirmRebuild', $script);
        self::assertStringNotContainsString('confirmLabel: messages.blockSseConfirmRefine', $script);
        self::assertStringNotContainsString('pendingBlockSseResult', $script);
        self::assertStringNotContainsString('applyPendingBlockSseResultWith', $script);
        self::assertStringNotContainsString('ForBlockRefresh', $script);
        self::assertStringContainsString("resolveUpdatedBlockFromResponse(context.page_type, context.block_id, saveResult);", $script);
        self::assertStringContainsString("updateVirtualBlockState(context.page_type, refreshedBlock);", $script);
        self::assertStringContainsString("replaceCurrentBlockHtml(context.page_type, refreshedBlock);", $script);
    }

    public function testBlockQueueObserverKeepsStreamOpenUntilCompletion(): void
    {
        /** @var AiSiteAgent $controller */
        $controller = ObjectManager::getInstance(AiSiteAgent::class);
        $method = new \ReflectionMethod(AiSiteAgent::class, 'shouldKeepQueuedObserverStreamOpen');
        $method->setAccessible(true);

        self::assertTrue((bool)$method->invoke($controller, 'plan'));
        self::assertFalse((bool)$method->invoke($controller, 'block_regenerate'));
        self::assertFalse((bool)$method->invoke($controller, 'build'));
        self::assertFalse((bool)$method->invoke($controller, 'block_partial_patch'));
        self::assertFalse((bool)$method->invoke($controller, 'regenerate_page'));
    }
}
