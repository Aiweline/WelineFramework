<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Integration;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use Weline\Framework\Manager\ObjectManager;

final class AiSiteWorkbenchWorkspaceUrlPathIntegrationTest extends AbstractAiSiteWorkbenchIntegrationHarness
{
    public function testWorkspaceUsesOriginRelativeUrlsForFrontendFetchEndpoints(): void
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

        foreach ([
            'stateJsonUrl',
            'mergeScopeUrl',
            'replaceScopeUrl',
            'setStageUrl',
            'runVirtualThemeUrl',
            'startRegeneratePageUrl',
            'startRefineComponentUrl',
            'updateBlockConfigUrl',
            'switchPreviewPageUrl',
            'publishCheckUrl',
            'operationSseBaseUrl',
        ] as $varName) {
            $value = $this->extractJsonStringVariable($html, $varName);
            self::assertStringStartsWith(
                '/',
                $value,
                $varName . ' should stay origin-relative so workspace fetch/EventSource requests reuse the current browser origin.'
            );
            self::assertDoesNotMatchRegularExpression(
                '#^(?:https?:)?//#i',
                $value,
                $varName . ' should not emit an absolute URL into workspace.phtml.'
            );
        }
    }

    private function extractJsonStringVariable(string $html, string $variableName): string
    {
        $pattern = '/var\s+' . \preg_quote($variableName, '/') . '\s*=\s*(.+?);/';
        self::assertSame(
            1,
            \preg_match($pattern, $html, $matches),
            'Failed to locate JS variable "' . $variableName . '" in workspace HTML.'
        );

        $decoded = \json_decode($matches[1], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsString($decoded, 'Workspace JS variable "' . $variableName . '" should decode to a string.');

        return $decoded;
    }
}
