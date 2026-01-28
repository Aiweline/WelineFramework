<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Integration;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\Theme\Controller\Backend\ThemeEditor;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeCacheGenerator;
use Weline\Theme\Service\ThemeLayoutService;
use Weline\Theme\Service\WidgetPositionResolver;
use Weline\Widget\Service\WidgetRegistry;

class ThemeEditorPreviewFlowTest extends TestCore
{
    private function buildController(): ThemeEditor
    {
        $themeMock = $this->createMock(WelineTheme::class);
        $themeMock->method('getActiveTheme')->willReturn(new class {
            public function getId(): int
            {
                return 1;
            }
        });

        $layoutService = $this->createMock(ThemeLayoutService::class);
        $layoutService->method('updateWidgetConfig')->willReturn(true);

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

    public function testConfigSaveReturnsOnlyTargetPreview(): void
    {
        self::initRequest('/theme/backend/theme-editor/update-config');
        $request = ObjectManager::getInstance(Request::class);
        $request->setPost('layout_id', 22);
        $request->setPost('config', ['title' => 'flow-test']);

        $controller = $this->buildController();
        $response = $controller->postUpdateConfig();
        $payload = json_decode(is_string($response) ? $response : '', true);

        $this->assertIsArray($payload);
        $this->assertTrue($payload['success'] ?? false);
        $this->assertArrayHasKey('preview_html', $payload);
        $this->assertArrayNotHasKey('layout_html', $payload);
    }
}
