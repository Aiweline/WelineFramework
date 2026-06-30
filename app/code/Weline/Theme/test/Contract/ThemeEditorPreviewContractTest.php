<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Contract;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Session;
use Weline\Framework\UnitTest\TestCore;
use Weline\Meta\Model\Meta;
use Weline\Theme\Controller\Backend\ThemeEditor;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\ThemeLayoutVersion;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\EditorLockService;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\PreviewRequestInspector;
use Weline\Theme\Service\PreviewTokenService;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemeCacheGenerator;
use Weline\Theme\Service\ThemeLayoutService;
use Weline\Theme\Service\ThemeLayoutVersionService;
use Weline\Theme\Service\WidgetPositionResolver;
use Weline\Widget\Service\WidgetRegistry;

class ThemeEditorPreviewContractTest extends TestCore
{
    private function buildController(array $availableWidgets): ThemeEditor
    {
        $themePath = BP . 'app/code/Weline/Theme/view/theme';
        $themeMock = new class([
            'id' => 1,
            'name' => 'Weline Theme',
            'module_name' => 'Weline_Theme',
            'path' => $themePath,
        ]) extends WelineTheme {
            public function getActiveTheme(?string $area = null): static
            {
                return $this->setData($this->themeData());
            }

            public function clearData(bool $with_query = true): static
            {
                return $this;
            }

            public function clearQuery(string $type = ''): static
            {
                return $this;
            }

            public function load(int|string $field_or_pk_value, $value = null): AbstractModel
            {
                $this->setData($this->themeData());
                return $this;
            }

            public function reset(): static
            {
                return $this;
            }

            public function select(string $fields = ''): static
            {
                return $this;
            }

            public function fetch(string $model_class = ''): static
            {
                return $this;
            }

            public function getItems(): array
            {
                return [$this];
            }

            public function getThemeChain(): array
            {
                return [$this];
            }

            private function themeData(): array
            {
                return [
                    'id' => 1,
                    'name' => 'Weline Theme',
                    'module_name' => 'Weline_Theme',
                    'path' => BP . 'app/code/Weline/Theme/view/theme',
                ];
            }
        };

        $layoutService = $this->createMock(ThemeLayoutService::class);
        $layoutService->method('hasDraft')->willReturn(true);
        $layoutService->method('getFullDraftLayout')->willReturn([]);
        $layoutService->method('getAvailableWidgets')->willReturn($availableWidgets);

        $cacheGenerator = $this->createMock(ThemeCacheGenerator::class);
        $positionResolver = $this->createMock(WidgetPositionResolver::class);
        $widgetRegistry = $this->createMock(WidgetRegistry::class);
        $themeLayout = $this->createMock(ThemeLayout::class);
        $versionService = new ThemeLayoutVersionService(
            $this->createMock(ThemeLayoutVersion::class),
            $layoutService,
            $themeLayout,
            $themeMock
        );
        $meta = $this->createMock(Meta::class);
        $previewTokenService = $this->createMock(PreviewTokenService::class);
        $editorLockService = $this->createMock(EditorLockService::class);

        $request = ObjectManager::getInstance(Request::class);
        $previewContext = new PreviewContextService(
            $request,
            ObjectManager::getInstance(Session::class),
            $previewTokenService,
            $themeMock,
            new PreviewRequestInspector($request)
        );
        ObjectManager::setInstance(PreviewContextService::class, $previewContext);
        $themeContext = new ThemeContextService($themeMock, $previewContext);
        ObjectManager::setInstance(ThemeContextService::class, $themeContext);

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

    protected function tearDown(): void
    {
        ObjectManager::removeInstance(PreviewContextService::class);
        ObjectManager::removeInstance(ThemeContextService::class);
        parent::tearDown();
    }

    public function testIndexIncludesPreviewHtml(): void
    {
        self::initRequest('/theme/backend/theme-editor/index');
        $request = ObjectManager::getInstance(Request::class);
        $request->setGet('theme_id', 1);
        $request->setGet('frontend_theme_id', 1);
        $request->setGet('backend_theme_id', 1);
        $request->setGet('editor_area', PreviewContextService::AREA_FRONTEND);
        $request->setGet('shell', PreviewContextService::SHELL_THEME_EDITOR);

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
