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
        self::assertStringContainsString('function bindWorkspacePreviewMessages()', $html);
        self::assertStringContainsString('function resolveWorkspaceVisualComponentContext(payload)', $html);
        self::assertStringContainsString("var componentCode = String((payload && payload.component) || '').trim();", $html);
        self::assertStringContainsString("var region = String((payload && payload.region) || '').trim();", $html);
        self::assertStringContainsString("var rawIndex = String((payload && payload.index) || '').trim();", $html);
        self::assertStringContainsString("if (payload.type === 'pb-component-action') {", $html);
        self::assertStringContainsString('block_id: refineComponentState.componentCode,', $html);
        self::assertStringContainsString('component_code: refineComponentState.componentCode,', $html);
        self::assertStringContainsString("formData.append('component_code', componentCode);", $html);
    }
}
