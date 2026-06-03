<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Controller;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AiSiteAgentBlockSseRouteContractTest extends TestCase
{
    public function testBlockSseEndpointsExposeCanonicalGetRoutesAndLegacyGetCompatibility(): void
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
                $methodName . ' must exist so EventSource GET subscriptions can resolve both canonical and legacy block SSE URLs.'
            );
        }
    }

    public function testBlockSseApplyMatchesSharedComponentBlocksAndReplacesPreviewWrapper(): void
    {
        $scriptSource = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-main.phtml');
        self::assertIsString($scriptSource);

        self::assertStringContainsString('function blockMatchesComponentCode(pageType, block, componentCode)', $scriptSource);
        self::assertStringContainsString('findVirtualBlockInList(pageType, pageState.blocks, blockId)', $scriptSource);
        self::assertStringContainsString("resolveSharedComponentRegionFromCode(pageType, String(candidate || ''))", $scriptSource);
        self::assertStringContainsString('updateVirtualBlockState(context.page_type, refreshedBlock)', $scriptSource);
    }

    public function testPreviewStageToolbarKeepsStageLevelActionsWired(): void
    {
        $scriptSource = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-main.phtml');
        self::assertIsString($scriptSource);

        self::assertStringContainsString('function buildPreviewStageToolbar(stage)', $scriptSource);
        self::assertStringContainsString('pb-ai-plan-preview-stage-toolbar', $scriptSource);
        self::assertStringContainsString("buildPreviewActionButton('refine-stage'", $scriptSource);
        self::assertStringContainsString("buildPreviewActionButton('rebuild-stage'", $scriptSource);
    }
}
