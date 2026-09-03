<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Contract;

use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Test\TestCore;
use Weline\Meta\Model\Meta;
use Weline\SystemConfig\Api\Scope\ScopeContext;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\SystemConfig\Api\Scope\ScopeIdentityCatalogInterface;
use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;
use Weline\Theme\Api\TargetTypeProviderInterface;
use Weline\Theme\Controller\Backend\ThemeEditor;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\EditorLockService;
use Weline\Theme\Service\PreviewTokenService;
use Weline\Theme\Service\Scoped\ThemeEditorContextFactory;
use Weline\Theme\Service\ThemeCacheGenerator;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemeLayoutService;
use Weline\Theme\Service\ThemeLayoutVersionService;
use Weline\Theme\Service\ThemeTargetTypeRegistry;
use Weline\Theme\Service\WidgetPositionResolver;
use Weline\Widget\Service\WidgetRegistry;

class ThemeEditorRemoveOrphanWidgetsScopeContractTest extends TestCore
{
    private function buildController(ThemeLayout $themeLayout): ThemeEditor
    {
        $themeMock = $this->getMockBuilder(WelineTheme::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['clearData', 'load', 'getId'])
            ->addMethods(['clearQuery'])
            ->getMock();
        $layoutService = $this->createMock(ThemeLayoutService::class);
        $cacheGenerator = $this->createMock(ThemeCacheGenerator::class);
        $positionResolver = $this->createMock(WidgetPositionResolver::class);
        $widgetRegistry = $this->createMock(WidgetRegistry::class);
        $meta = $this->createMock(Meta::class);
        $editorLockService = $this->createMock(EditorLockService::class);
        $versionService = ObjectManager::getInstance(ThemeLayoutVersionService::class);
        $previewTokenService = ObjectManager::getInstance(PreviewTokenService::class);
        $themeMock->method('clearData')->willReturnSelf();
        $themeMock->method('clearQuery')->willReturnSelf();
        $themeMock->method('load')->willReturnSelf();
        $themeMock->method('getId')->willReturn(1);

        $identity = ScopeIdentity::global();
        $scopeHierarchy = $this->createMock(ScopeHierarchyInterface::class);
        $scopeHierarchy->method('contextFromClaims')->willReturn(new ScopeContext(
            identity: $identity,
            storageScope: 'default.default.default',
            storeMode: ScopeIdentity::MODE_NORMAL,
            fallbackStorageScopes: ['default.default.default'],
        ));
        $scopeCatalog = $this->createMock(ScopeIdentityCatalogInterface::class);
        $scopeCatalog->method('authoritativeIdentity')->willReturnCallback(
            static fn(ScopeIdentity $candidate): ScopeIdentity => $candidate,
        );
        $themeContext = $this->getMockBuilder(ThemeContextService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['themeSupportsArea'])
            ->getMock();
        $themeContext->method('themeSupportsArea')->willReturn(true);
        $targetProvider = $this->createMock(TargetTypeProviderInterface::class);
        $targetProvider->method('canUseLayoutType')->willReturn(true);
        $targetTypes = $this->getMockBuilder(ThemeTargetTypeRegistry::class)
            ->onlyMethods(['get', 'isValidTarget'])
            ->getMock();
        $targetTypes->method('get')->willReturn($targetProvider);
        $targetTypes->method('isValidTarget')->willReturn(true);
        $workspaces = $this->createMock(ThemeScopedWorkspaceInterface::class);
        $workspaces->method('load')->willReturn(['draft_payload' => ['theme_id' => 1]]);
        ObjectManager::setInstance(ThemeEditorContextFactory::class, new ThemeEditorContextFactory(
            $scopeHierarchy,
            $scopeCatalog,
            $themeMock,
            $themeContext,
            $targetTypes,
            $workspaces,
        ));
        $eventsManager = $this->createMock(EventsManager::class);
        $eventsManager->method('dispatch')->willReturnSelf();
        ObjectManager::setInstance(EventsManager::class, $eventsManager);

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
        ObjectManager::removeInstance(EventsManager::class);
        ObjectManager::removeInstance(ThemeEditorContextFactory::class);
        ObjectManager::removeInstance(Request::class);
        RequestContext::resetWelineVars();
        parent::tearDown();
    }

    public function testRemoveOrphanWidgetsScopesDeleteToCurrentPageTypeAndStatus(): void
    {
        $backendPrefix = \trim((string)(\Weline\Framework\App\Env::getAreaRoutePrefix('backend') ?? ''), '/');
        self::assertNotSame('', $backendPrefix);
        $requestPath = '/' . $backendPrefix . '/theme/backend/theme-editor/remove-orphan-widgets';
        ObjectManager::removeInstance(Request::class);
        RequestContext::resetWelineVars();
        self::initRequest($requestPath);
        $request = ObjectManager::getInstance(Request::class);
        RequestContext::setId('theme-editor-remove-orphan-contract');
        $request->getServer();
        $request->setServer('WELINE_ORIGIN_REQUEST_URI', $requestPath);
        $request->setServer('REQUEST_URI', $requestPath);
        $request->setPost('theme_id', 1);
        $request->setPost('slot_ids', ['header']);
        $request->setPost('page_type', 'category');
        $request->setPost('status', ThemeLayout::STATUS_DRAFT);
        $request->setPost('editor_context', $this->layoutEditorContext(1, 'category'));

        $whereCalls = [];
        $fetchArrayCalls = 0;

        $themeLayout = $this->getMockBuilder(ThemeLayout::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['delete', 'getConnection'])
            ->addMethods(['clearQuery', 'where', 'select', 'fetchArray', 'fetch'])
            ->getMock();

        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects(self::once())
            ->method('tableExist')
            ->with(ThemeLayout::schema_table)
            ->willReturn(true);
        $connection = $this->createMock(ConnectionFactory::class);
        $connection->expects(self::once())->method('getConnector')->willReturn($connector);
        $themeLayout->method('getConnection')->willReturn($connection);

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
        self::assertTrue($payload['success'] ?? false, json_encode($payload, JSON_UNESCAPED_UNICODE) ?: 'invalid response');
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
