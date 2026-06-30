<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Contract;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\Meta\Model\Meta;
use Weline\Theme\Controller\Backend\ThemeEditor;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\ThemeLayoutVersion;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\EditorLockService;
use Weline\Theme\Service\PreviewTokenService;
use Weline\Theme\Service\ThemeCacheGenerator;
use Weline\Theme\Service\ThemeLayoutService;
use Weline\Theme\Service\ThemeLayoutVersionService;
use Weline\Theme\Service\WidgetPositionResolver;
use Weline\Widget\Service\WidgetRegistry;

class ThemeEditorUpdateConfigContractTest extends TestCore
{
    private function buildController(bool $updateResult): ThemeEditor
    {
        $themeMock = new class(['id' => 1]) extends WelineTheme {
            public function getActiveTheme(?string $area = null): static
            {
                return $this->setData(['id' => 1]);
            }

            public function clearData(bool $with_query = true): static
            {
                return $this;
            }

            public function clearQuery(string $type = ''): static
            {
                return $this;
            }
        };

        $layoutService = $this->createMock(ThemeLayoutService::class);
        $layoutService->method('updateWidgetConfig')->willReturn($updateResult);
        $layoutService->method('getWidgetByLayoutId')->willReturn([
            'layout_id' => 10,
            'widget_module' => 'Weline_Theme',
            'widget_code' => 'demo',
            'config' => [],
        ]);

        $cacheGenerator = $this->createMock(ThemeCacheGenerator::class);
        $positionResolver = $this->createMock(WidgetPositionResolver::class);
        $widgetRegistry = $this->createMock(WidgetRegistry::class);
        $themeLayout = $this->getMockBuilder(ThemeLayout::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load'])
            ->addMethods(['reset'])
            ->getMock();
        $themeLayout->method('reset')->willReturnSelf();
        $themeLayout->method('load')->willReturnCallback(function (int|string $layoutId) use ($themeLayout): AbstractModel {
            $themeLayout->setData([
                'layout_id' => (int)$layoutId,
                'widget_module' => 'Weline_Theme',
                'widget_code' => 'demo',
                'area' => 'frontend',
            ]);
            return $themeLayout;
        });
        $versionService = new ThemeLayoutVersionService(
            $this->createMock(ThemeLayoutVersion::class),
            $layoutService,
            $themeLayout,
            $themeMock
        );
        $meta = $this->createMock(Meta::class);
        $previewTokenService = $this->createMock(PreviewTokenService::class);
        $editorLockService = $this->createMock(EditorLockService::class);

        $controller = new ThemeEditor(
            $themeMock,
            $layoutService,
            $versionService,
            $cacheGenerator,
            $positionResolver,
            $widgetRegistry,
            $themeLayout,
            $meta,
            $previewTokenService,
            $editorLockService
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
