<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;

final class ThemeScopedReleaseBatchEndpointContractTest extends TestCase
{
    public function testControllerAndEditorShellExposeOneBatchPublishEndpoint(): void
    {
        $root = \dirname(__DIR__, 4);
        $controller = (string)\file_get_contents($root . '/Controller/Backend/ThemeEditor.php');
        $template = (string)\file_get_contents($root . '/view/templates/backend/ThemeEditor/index.phtml');

        self::assertStringContainsString('public function postPublishScopedReleaseBatch()', $controller);
        self::assertStringContainsString("scopedWorkspacePayload('publish_batch')", $controller);
        self::assertStringContainsString("'publish_batch' => \$service->publishBatch(", $controller);
        self::assertStringContainsString('data-api-publish-scoped-release-batch=', $template);
        self::assertStringContainsString('publish-scoped-release-batch', $template);
    }

    public function testCompatibilityPublisherDelegatesOneCompleteBatch(): void
    {
        $controller = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Controller/Backend/ThemeEditor.php'
        );
        $start = \strpos($controller, 'private function publishPendingScopedResources(');
        $end = \strpos($controller, 'private function replaceScopedLayoutDraftFromSnapshot(', (int)$start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $method = \substr($controller, (int)$start, (int)$end - (int)$start);

        self::assertStringContainsString('ThemeEditorContext::RESOURCES', $method);
        self::assertSame(1, \substr_count($method, '$requests->publishBatch('));
        self::assertStringNotContainsString('$requests->publish([', $method);
    }
}
