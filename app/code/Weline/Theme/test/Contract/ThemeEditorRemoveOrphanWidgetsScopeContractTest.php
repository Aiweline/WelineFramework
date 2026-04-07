<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Contract;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\Meta\Model\Meta;
use Weline\Theme\Controller\Backend\ThemeEditor;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\EditorLockService;
use Weline\Theme\Service\PreviewTokenService;
use Weline\Theme\Service\ThemeCacheGenerator;
use Weline\Theme\Service\ThemeLayoutService;
use Weline\Theme\Service\ThemeLayoutVersionService;
use Weline\Theme\Service\WidgetPositionResolver;
use Weline\Widget\Service\WidgetRegistry;

class ThemeEditorRemoveOrphanWidgetsScopeContractTest extends TestCore
{
    private function buildController(ThemeLayout $themeLayout): ThemeEditor
    {
        $themeMock = $this->createMock(WelineTheme::class);
        $layoutService = $this->createMock(ThemeLayoutService::class);
        $versionService = $this->createMock(ThemeLayoutVersionService::class);
        $cacheGenerator = $this->createMock(ThemeCacheGenerator::class);
        $positionResolver = $this->createMock(WidgetPositionResolver::class);
        $widgetRegistry = $this->createMock(WidgetRegistry::class);
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

    public function testRemoveOrphanWidgetsScopesDeleteToCurrentPageTypeAndStatus(): void
    {
        self::initRequest('/theme/backend/theme-editor/remove-orphan-widgets');
        $request = ObjectManager::getInstance(Request::class);
        $request->setPost('theme_id', 9);
        $request->setPost('slot_ids', ['header']);
        $request->setPost('page_type', 'category');
        $request->setPost('status', ThemeLayout::STATUS_DRAFT);

        $whereCalls = [];
        $fetchArrayCalls = 0;

        $themeLayout = $this->getMockBuilder(ThemeLayout::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['clearQuery', 'where', 'select', 'fetchArray', 'delete', 'fetch'])
            ->getMock();

        $themeLayout->method('clearQuery')->willReturnSelf();
        $themeLayout->method('select')->willReturnSelf();
        $themeLayout->method('delete')->willReturnSelf();
        $themeLayout->method('fetch')->willReturn([]);
        $themeLayout->method('where')->willReturnCallback(function (...$args) use (&$whereCalls, $themeLayout) {
            $whereCalls[] = $args;
            return $themeLayout;
        });
        $themeLayout->method('fetchArray')->willReturnCallback(function () use (&$fetchArrayCalls) {
            $fetchArrayCalls++;

            return $fetchArrayCalls % 2 === 1 ? [['layout_id' => 1]] : [];
        });

        $controller = $this->buildController($themeLayout);
        $response = $controller->postRemoveOrphanWidgets();
        $payload = json_decode(is_string($response) ? $response : '', true);

        self::assertIsArray($payload);
        self::assertTrue($payload['success'] ?? false);
        self::assertSame(1, $payload['deleted_count'] ?? 0);
        self::assertTrue($this->containsWhereCall($whereCalls, 'page_type', 'category'));
        self::assertTrue($this->containsWhereCall($whereCalls, 'status', ThemeLayout::STATUS_DRAFT));
    }

    private function containsWhereCall(array $whereCalls, string $field, mixed $value): bool
    {
        foreach ($whereCalls as $call) {
            if (($call[0] ?? null) === $field && ($call[1] ?? null) === $value) {
                return true;
            }
        }

        return false;
    }
}
