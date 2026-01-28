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

class ThemeEditorPreviewContractTest extends TestCore
{
    private function buildController(array $availableWidgets): ThemeEditor
    {
        $themeMock = $this->createMock(WelineTheme::class);
        $themeMock->method('reset')->willReturnSelf();
        $themeMock->method('load')->willReturnSelf();
        $themeMock->method('select')->willReturnSelf();
        $themeMock->method('fetch')->willReturnSelf();
        $themeMock->method('getItems')->willReturn([]);
        $themeMock->method('getActiveTheme')->willReturn(new class {
            public function getId(): int
            {
                return 1;
            }
        });

        $layoutService = $this->createMock(ThemeLayoutService::class);
        $layoutService->method('hasDraft')->willReturn(true);
        $layoutService->method('getFullDraftLayout')->willReturn([]);
        $layoutService->method('getAvailableWidgets')->willReturn($availableWidgets);

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

    public function testIndexIncludesPreviewHtml(): void
    {
        self::initRequest('/theme/backend/theme-editor/index');
        $request = ObjectManager::getInstance(Request::class);
        $request->setGet('theme_id', 1);

        $availableWidgets = [
            'content' => [
                'widgets' => [
                    [
                        'code' => 'demo',
                        'name' => 'Demo Widget',
                        'template' => '',
                        'params' => [],
                        'module' => 'Weline_Theme',
                    ],
                ],
            ],
        ];

        $controller = $this->buildController($availableWidgets);
        $html = $controller->index();

        $this->assertIsString($html);
        $this->assertStringContainsString('widget-preview-placeholder', $html);
        $this->assertStringContainsString('Demo Widget', $html);
    }
}
