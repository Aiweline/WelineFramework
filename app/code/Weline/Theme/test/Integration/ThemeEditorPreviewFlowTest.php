<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Integration;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;
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

class ThemeEditorPreviewFlowTest extends TestCore
{
    private function buildController(): ThemeEditor
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
        $layoutService->method('updateWidgetConfig')->willReturn(true);
        $layoutService->method('getWidgetByLayoutId')->willReturn([
            'layout_id' => 22,
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
                'theme_id' => 1,
                'page_type' => 'homepage',
                'status' => ThemeLayout::STATUS_DRAFT,
                'layout_option' => 'default',
                'scope' => 'default.default.default',
                'locale_code' => '',
                'target_type' => 'global',
                'target_id' => 0,
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

    public function testConfigSaveReturnsOnlyTargetPreview(): void
    {
        $backendPrefix = \trim((string)(\Weline\Framework\App\Env::getAreaRoutePrefix('backend') ?? ''), '/');
        self::assertNotSame('', $backendPrefix);
        $requestPath = '/' . $backendPrefix . '/theme/backend/theme-editor/update-config';
        self::initRequest($requestPath);
        $request = ObjectManager::getInstance(Request::class);
        \Weline\Framework\Runtime\RequestContext::setId('theme-editor-preview-flow');
        $request->getServer();
        $request->setServer('WELINE_ORIGIN_REQUEST_URI', $requestPath);
        $request->setServer('REQUEST_URI', $requestPath);
        $request->setPost('layout_id', 22);
        $request->setPost('config', ['title' => 'flow-test']);
        $request->setPost('editor_context', $this->layoutEditorContext(1, 'homepage'));

        $controller = $this->buildController();
        $response = $controller->postUpdateConfig();
        $payload = json_decode(is_string($response) ? $response : '', true);

        $this->assertIsArray($payload);
        $this->assertTrue($payload['success'] ?? false, json_encode($payload, JSON_UNESCAPED_UNICODE) ?: 'invalid response');
        $this->assertArrayHasKey('preview_html', $payload);
        $this->assertArrayNotHasKey('layout_html', $payload);
    }

    private function layoutEditorContext(int $themeId, string $layoutType): array
    {
        return [
            'scope' => ['identity' => \Weline\Framework\Runtime\ScopeIdentity::global()->toArray()],
            'area' => 'frontend',
            'resource_type' => 'layout',
            'theme_id' => $themeId,
            'layout_type' => $layoutType,
            'layout_option' => 'default',
            'locale' => 'default',
            'target_type' => 'global',
            'target_id' => 0,
        ];
    }
}
