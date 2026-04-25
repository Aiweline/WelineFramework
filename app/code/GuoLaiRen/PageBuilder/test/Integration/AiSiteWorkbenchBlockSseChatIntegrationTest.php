<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Integration;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use Weline\Framework\Manager\ObjectManager;

final class AiSiteWorkbenchBlockSseChatIntegrationTest extends AbstractAiSiteWorkbenchIntegrationHarness
{
    public function testWorkspaceIncludesConfirmBeforeApplyingBlockSseResult(): void
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
        self::assertStringContainsString('var pendingBlockSseResult = null;', $html);
        self::assertStringContainsString('var pendingBlockSseStart = null;', $html);
        self::assertStringContainsString('blockSseDoneConfirm', $html);
        self::assertStringContainsString('id="pb-ai-block-sse-confirm-start"', $html);
        self::assertStringContainsString('id="pb-ai-block-sse-apply"', $html);
        self::assertStringContainsString('function confirmPendingBlockSseStart()', $html);
        self::assertStringContainsString("startBtn.addEventListener('click', confirmPendingBlockSseStart);", $html);
        self::assertStringContainsString("terminal.on('open', function ()", $html);
        self::assertStringContainsString('confirmLabel: messages.blockSseConfirmRebuild', $html);
        self::assertStringContainsString('confirmLabel: messages.blockSseConfirmRefine', $html);
        self::assertStringContainsString("pendingBlockSseResult = payload && typeof payload === 'object' ? payload : null;", $html);
        self::assertStringContainsString("applyBtn.classList.remove('d-none');", $html);
        self::assertStringContainsString("contextEl.textContent = messages.blockSseDoneConfirm;", $html);
        self::assertStringContainsString("if (!pendingBlockSseResult || !pendingBlockSseResult.state || !pendingBlockSseResult.state.virtual_pages_by_type)", $html);
        self::assertStringContainsString("updateVirtualBlockState(blockRefreshState.pageType, nextBlock);", $html);
        self::assertStringContainsString("replaceCurrentBlockHtml(blockRefreshState.pageType, nextBlock);", $html);
        self::assertStringContainsString("toast('success', messages.blockSseApplied);", $html);
    }
}
