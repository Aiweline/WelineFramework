<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Contract;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\Theme\Controller\Backend\ThemeEditor;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeCacheGenerator;
use Weline\Theme\Service\ThemeLayoutService;
use Weline\Theme\Service\WidgetPositionResolver;
use Weline\Widget\Service\WidgetRegistry;

class ThemeEditorUpdateConfigContractTest extends TestCore
{
    private function buildController(bool $updateResult): ThemeEditor
    {
        $themeMock = $this->createMock(WelineTheme::class);
        $themeMock->method('getActiveTheme')->willReturn(new class {
            public function getId(): int
            {
                return 1;
            }
        });

        $layoutService = $this->createMock(ThemeLayoutService::class);
        $layoutService->method('updateWidgetConfig')->willReturn($updateResult);

        $cacheGenerator = $this->createMock(ThemeCacheGenerator::class);
        $positionResolver = $this->createMock(WidgetPositionResolver::class);
        $widgetRegistry = $this->createMock(WidgetRegistry::class);

        $controller = new ThemeEditor(
            $themeMock,
            $layoutService,
            $cacheGenerator,
            $positionResolver,
            $widgetRegistry
        );
        $controller->__init();
        return $controller;
    }

    public function testUpdateConfigReturnsPreviewHtml(): void
    {
        self::initRequest('/theme/backend/theme-editor/update-config');
        $request = ObjectManager::getInstance(Request::class);
        $request->setPost('layout_id', 10);
        $request->setPost('config', ['title' => 'demo']);

        $controller = $this->buildController(true);
        $response = $controller->postUpdateConfig();
        $payload = json_decode(is_string($response) ? $response : '', true);

        $this->assertIsArray($payload);
        $this->assertTrue($payload['success'] ?? false);
        $this->assertArrayHasKey('preview_html', $payload);
    }
}
