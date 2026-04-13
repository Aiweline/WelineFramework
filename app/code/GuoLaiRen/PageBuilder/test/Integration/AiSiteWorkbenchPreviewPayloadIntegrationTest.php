<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Integration;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use Weline\Framework\Manager\ObjectManager;

final class AiSiteWorkbenchPreviewPayloadIntegrationTest extends AbstractAiSiteWorkbenchIntegrationHarness
{
    public function testWorkspaceNormalizesPreviewActionPayloadsToLogicalBlockIds(): void
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
        self::assertStringContainsString('function resolveLogicalBlockId(pageType, componentCode, region, index)', $html);
        self::assertStringContainsString('function normalizePreviewActionPayload(payload)', $html);
        self::assertStringContainsString(
            "var rawComponent = String((payload && payload.raw_component) || (payload && payload.component) || '');",
            $html
        );
        self::assertMatchesRegularExpression(
            '/return\s+normalizePreviewActionPayload\(\s*\{/',
            $html,
            'Embedded preview payloads should be normalized before block actions use them.'
        );
        self::assertMatchesRegularExpression(
            '/var\s+actionPayload\s*=\s*normalizePreviewActionPayload\(payload\);/',
            $html,
            'Posted preview actions should be normalized before refine/regenerate/editor flows consume them.'
        );
        self::assertStringContainsString('frontendHandlerError', $html);
        self::assertStringContainsString('function handleWorkspaceAsyncError(error, fallbackMessage)', $html);
        self::assertStringContainsString('pbWorkspaceRequestError', $html);
    }
}
