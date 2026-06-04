<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Controller;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use GuoLaiRen\PageBuilder\Test\Unit\View\Support\AiSiteWorkspaceScriptReader;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AiSiteAgentBlockSseRouteContractTest extends TestCase
{
    public function testBlockSseEndpointsExposeCanonicalGetRoutesAndRemovedGetCompatibility(): void
    {
        $controllerSource = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php');
        self::assertIsString($controllerSource);

        self::assertStringContainsString('public function getBlockRefineSse()', $controllerSource);
        self::assertStringContainsString('public function getBlockRegenerateSse()', $controllerSource);
        self::assertStringContainsString('public function getPostBlockRefineSse()', $controllerSource);
        self::assertStringContainsString('public function getPostBlockRegenerateSse()', $controllerSource);
        self::assertStringNotContainsString("start_block_refine_sse_url', \$this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-block-refine-sse')", $controllerSource);
        self::assertStringNotContainsString("start_block_regenerate_sse_url', \$this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-block-regenerate-sse')", $controllerSource);

        $reflection = new ReflectionClass(AiSiteAgent::class);
        foreach ([
            'getBlockRefineSse',
            'getPostBlockRefineSse',
            'getBlockRegenerateSse',
            'getPostBlockRegenerateSse',
        ] as $methodName) {
            self::assertTrue(
                $reflection->hasMethod($methodName),
                $methodName . ' must exist so EventSource GET subscriptions can resolve both canonical and removed block SSE URLs.'
            );
        }
    }

    public function testBlockSseApplyMatchesSharedComponentBlocksAndReplacesPreviewWrapper(): void
    {
        $scriptSource = AiSiteWorkspaceScriptReader::loadBundledJavaScript();

        self::assertStringContainsString('function blockMatchesComponentCode(pageType, block, componentCode)', $scriptSource);
        self::assertStringContainsString('findVirtualBlockInList(pageType, pageState.block_nodes, blockId)', $scriptSource);
        self::assertStringContainsString("resolveSharedComponentRegionFromCode(pageType, String(candidate || ''))", $scriptSource);
        self::assertStringContainsString('updateVirtualBlockState(context.page_type, refreshedBlock)', $scriptSource);
    }

    public function testPreviewStageToolbarKeepsStageLevelActionsWired(): void
    {
        $scriptSource = AiSiteWorkspaceScriptReader::loadBundledJavaScript();

        self::assertStringContainsString('function buildPreviewActionButton(action, label, tone, meta)', $scriptSource);
        self::assertStringContainsString("buildPreviewActionButton('refine'", $scriptSource);
        self::assertStringContainsString("buildPreviewActionButton('rebuild'", $scriptSource);
    }
}
