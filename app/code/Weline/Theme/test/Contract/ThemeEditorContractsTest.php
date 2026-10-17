<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Contract;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Session\Session;
use Weline\Framework\Test\TestCore;
use Weline\Meta\Model\Meta;
use Weline\SystemConfig\Api\Scope\ScopeContext;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\SystemConfig\Api\Scope\ScopeIdentityCatalogInterface;
use Weline\SystemConfig\Api\Scope\ScopeSelectorCatalogInterface;
use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;
use Weline\Theme\Api\TargetTypeProviderInterface;
use Weline\Theme\Controller\Backend\ThemeEditor;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\ThemeLayoutVersion;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\EditorLockService;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\PreviewRequestInspector;
use Weline\Theme\Service\PreviewTokenService;
use Weline\Theme\Service\Scoped\ThemeEditorContextFactory;
use Weline\Theme\Service\ThemeCacheGenerator;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemeLayoutService;
use Weline\Theme\Service\ThemeLayoutVersionService;
use Weline\Theme\Service\ThemeTargetTypeRegistry;
use Weline\Theme\Service\WidgetPositionResolver;
use Weline\Widget\Service\WidgetRegistry;

/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllThemeEditorContractsPass().
 */
final class ThemeEditorContractsTest extends TestCase
{
    public function testAllThemeEditorContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'ThemeEditorPreview::testIndexIncludesPreviewHtml' => function () {
            try {
                $this->assert_ThemeEditorPreview_testIndexIncludesPreviewHtml();
            } finally {
                $this->tearDown_ThemeEditorPreview();
            }
        },
            'ThemeEditorRemoveOrphanWidgetsScope::testRemoveOrphanWidgetsScopesDeleteToCurrentPageTypeAndStatus' => function () {
            try {
                $this->assert_ThemeEditorRemoveOrphanWidgetsScope_testRemoveOrphanWidgetsScopesDeleteToCurrentPageTypeAndStatus();
            } finally {
                $this->tearDown_ThemeEditorRemoveOrphanWidgetsScope();
            }
        },
            'ThemeEditorUpdateConfig::testUpdateConfigContractUsesScopedWriterNotLegacyLayoutId' => function () {
            $this->assert_ThemeEditorUpdateConfig_testUpdateConfigContractUsesScopedWriterNotLegacyLayoutId();
        },
        ];
    }

private function buildController_ThemeEditorPreview(array $availableWidgets): ThemeEditor
{
    $themePath = BP . 'app/code/Weline/Theme/view/theme';
    $themeMock = new class(['id' => 1, 'name' => 'Weline Theme', 'module_name' => 'Weline_Theme', 'path' => $themePath]) extends WelineTheme
    {
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
        public function load(int|string $field_or_pk_value, $value = null, bool $forceReload = false): AbstractModel
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
            return ['id' => 1, 'name' => 'Weline Theme', 'module_name' => 'Weline_Theme', 'path' => BP . 'app/code/Weline/Theme/view/theme'];
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
    $versionService = new ThemeLayoutVersionService($this->createMock(ThemeLayoutVersion::class), $layoutService, $themeLayout, $themeMock);
    $meta = $this->createMock(Meta::class);
    $previewTokenService = $this->createMock(PreviewTokenService::class);
    $editorLockService = $this->createMock(EditorLockService::class);
    $request = ObjectManager::getInstance(Request::class);
    $previewContext = new PreviewContextService($request, ObjectManager::getInstance(Session::class), $previewTokenService, $themeMock, new PreviewRequestInspector($request));
    ObjectManager::setInstance(PreviewContextService::class, $previewContext);
    $themeContext = new ThemeContextService($themeMock, $previewContext);
    ObjectManager::setInstance(ThemeContextService::class, $themeContext);
    $eventsManager = $this->createMock(EventsManager::class);
    $eventsManager->method('dispatch')->willReturnSelf();
    ObjectManager::setInstance(EventsManager::class, $eventsManager);
    $scopeCatalog = $this->createMock(ScopeSelectorCatalogInterface::class);
    $scopeCatalog->method('build')->willReturn(['selected_scope' => PreviewContextService::DEFAULT_SCOPE, 'selected_label' => 'Global', 'selected_identity' => ScopeIdentity::global()->toArray(), 'selected_kind' => ScopeIdentity::KIND_GLOBAL, 'selected_website_code' => '', 'selected_store_code' => '', 'selected_channel_code' => '', 'selected_store_mode' => ScopeIdentity::MODE_NORMAL, 'website_options' => [], 'store_options' => [], 'channel_options' => [], 'catalog_options' => [], 'options' => [], 'tree_options' => [], 'legacy_readonly' => true, 'legacy_scope' => PreviewContextService::DEFAULT_SCOPE]);
    ObjectManager::setInstance(ScopeSelectorCatalogInterface::class, $scopeCatalog);
    $controller = new ThemeEditor($themeMock, $layoutService, $versionService, $cacheGenerator, $positionResolver, $widgetRegistry, $themeLayout, $meta, $previewTokenService, $editorLockService);
    $controller->__init();
    return $controller;
}

private function tearDown_ThemeEditorPreview(): void
{
    ObjectManager::removeInstance(EventsManager::class);
    ObjectManager::removeInstance(ScopeSelectorCatalogInterface::class);
    ObjectManager::removeInstance(PreviewContextService::class);
    ObjectManager::removeInstance(ThemeContextService::class);
    ObjectManager::removeInstance(Request::class);
    RequestContext::resetWelineVars();
    parent::tearDown();
}

private function assert_ThemeEditorPreview_testIndexIncludesPreviewHtml(): void
{
    $backendPrefix = \trim((string) (\Weline\Framework\App\Env::getAreaRoutePrefix('backend') ?? ''), '/');
    self::assertNotSame('', $backendPrefix);
    $requestPath = '/' . $backendPrefix . '/theme/backend/theme-editor/index';
    ObjectManager::removeInstance(Request::class);
    RequestContext::resetWelineVars();
    self::initRequest($requestPath);
    $request = ObjectManager::getInstance(Request::class);
    RequestContext::setId('theme-editor-preview-contract');
    $request->getServer();
    $request->setServer('WELINE_ORIGIN_REQUEST_URI', $requestPath);
    $request->setServer('REQUEST_URI', $requestPath);
    $request->setGet('theme_id', 1);
    $request->setGet('frontend_theme_id', 1);
    $request->setGet('backend_theme_id', 1);
    $request->setGet('editor_area', PreviewContextService::AREA_FRONTEND);
    $request->setGet('shell', PreviewContextService::SHELL_THEME_EDITOR);
    $availableWidgets = ['content' => ['widgets' => [['code' => 'demo', 'name' => 'Demo Widget', 'template' => '', 'params' => [], 'module' => 'Weline_Theme']]]];
    $controller = $this->buildController_ThemeEditorPreview($availableWidgets);
    $html = $controller->index();
    $this->assertIsString($html);
    $this->assertStringContainsString('id="previewFrame"', $html);
    // Canvas starts blank; JS injects the authorized content URL with editor_context.
    $this->assertStringContainsString('src="about:blank"', $html);
    $this->assertStringNotContainsString('data-api-frontend-layout-preview=', $html);
    $this->assertStringNotContainsString('data-api-layout-preview=', $html);
    $this->assertStringContainsString('data-api-start-preview=', $html);
    $this->assertStringContainsString('id="widgetListLoading"', $html);
}

private function buildController_ThemeEditorRemoveOrphanWidgetsScope(ThemeLayout $themeLayout): ThemeEditor
{
    $themeMock = $this->getMockBuilder(WelineTheme::class)->disableOriginalConstructor()->onlyMethods(['clearData', 'load', 'getId'])->addMethods(['clearQuery'])->getMock();
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
    $scopeHierarchy->method('contextFromClaims')->willReturn(new ScopeContext(identity: $identity, storageScope: 'default.default.default', storeMode: ScopeIdentity::MODE_NORMAL, fallbackStorageScopes: ['default.default.default']));
    $scopeCatalog = $this->createMock(ScopeIdentityCatalogInterface::class);
    $scopeCatalog->method('authoritativeIdentity')->willReturnCallback(static fn(ScopeIdentity $candidate): ScopeIdentity => $candidate);
    $themeContext = $this->getMockBuilder(ThemeContextService::class)->disableOriginalConstructor()->onlyMethods(['themeSupportsArea'])->getMock();
    $themeContext->method('themeSupportsArea')->willReturn(true);
    $targetProvider = $this->createMock(TargetTypeProviderInterface::class);
    $targetProvider->method('canUseLayoutType')->willReturn(true);
    $targetTypes = $this->getMockBuilder(ThemeTargetTypeRegistry::class)->onlyMethods(['get', 'isValidTarget'])->getMock();
    $targetTypes->method('get')->willReturn($targetProvider);
    $targetTypes->method('isValidTarget')->willReturn(true);
    $workspaces = $this->createMock(ThemeScopedWorkspaceInterface::class);
    $workspaces->method('load')->willReturn(['draft_payload' => ['theme_id' => 1]]);
    ObjectManager::setInstance(ThemeEditorContextFactory::class, new ThemeEditorContextFactory($scopeHierarchy, $scopeCatalog, $themeMock, $themeContext, $targetTypes, $workspaces));
    $eventsManager = $this->createMock(EventsManager::class);
    $eventsManager->method('dispatch')->willReturnSelf();
    ObjectManager::setInstance(EventsManager::class, $eventsManager);
    $controller = new ThemeEditor($themeMock, $layoutService, $versionService, $cacheGenerator, $positionResolver, $widgetRegistry, $themeLayout, $meta, $previewTokenService, $editorLockService);
    $controller->__init();
    return $controller;
}

private function tearDown_ThemeEditorRemoveOrphanWidgetsScope(): void
{
    ObjectManager::removeInstance(EventsManager::class);
    ObjectManager::removeInstance(ThemeEditorContextFactory::class);
    ObjectManager::removeInstance(Request::class);
    RequestContext::resetWelineVars();
    parent::tearDown();
}

private function assert_ThemeEditorRemoveOrphanWidgetsScope_testRemoveOrphanWidgetsScopesDeleteToCurrentPageTypeAndStatus(): void
{
    $backendPrefix = \trim((string) (\Weline\Framework\App\Env::getAreaRoutePrefix('backend') ?? ''), '/');
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
    $request->setPost('editor_context', $this->layoutEditorContext_ThemeEditorRemoveOrphanWidgetsScope(1, 'category'));
    $whereCalls = [];
    $fetchArrayCalls = 0;
    $themeLayout = $this->getMockBuilder(ThemeLayout::class)->disableOriginalConstructor()->onlyMethods(['delete', 'getConnection'])->addMethods(['clearQuery', 'where', 'select', 'fetchArray', 'fetch'])->getMock();
    $connector = $this->createMock(ConnectorInterface::class);
    $connector->expects(self::once())->method('tableExist')->with(ThemeLayout::schema_table)->willReturn(true);
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
    $controller = $this->buildController_ThemeEditorRemoveOrphanWidgetsScope($themeLayout);
    $response = $controller->postRemoveOrphanWidgets();
    $payload = json_decode(is_string($response) ? $response : '', true);
    self::assertIsArray($payload);
    self::assertTrue($payload['success'] ?? false, json_encode($payload, JSON_UNESCAPED_UNICODE) ?: 'invalid response');
    self::assertSame(1, $payload['deleted_count'] ?? 0);
    self::assertTrue($this->containsWhereCall_ThemeEditorRemoveOrphanWidgetsScope($whereCalls, 'page_type', 'category'));
    self::assertTrue($this->containsWhereCall_ThemeEditorRemoveOrphanWidgetsScope($whereCalls, 'status', ThemeLayout::STATUS_DRAFT));
}

private function containsWhereCall_ThemeEditorRemoveOrphanWidgetsScope(array $whereCalls, string $field, mixed $value): bool
{
    foreach ($whereCalls as $call) {
        if (($call[0] ?? null) === $field && ($call[1] ?? null) === $value) {
            return true;
        }
    }
    return false;
}

private function layoutEditorContext_ThemeEditorRemoveOrphanWidgetsScope(int $themeId, string $layoutType): array
{
    return ['scope' => ['identity' => \Weline\Framework\Runtime\ScopeIdentity::global()->toArray()], 'area' => 'frontend', 'resource_type' => 'layout', 'theme_id' => $themeId, 'layout_type' => $layoutType, 'layout_option' => 'default', 'locale' => 'default', 'target_type' => 'global', 'target_id' => 0];
}

private function assert_ThemeEditorUpdateConfig_testUpdateConfigContractUsesScopedWriterNotLegacyLayoutId(): void
{
    $editor = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Controller/Backend/ThemeEditor.php');
    $start = strpos($editor, 'function postUpdateConfig(');
    self::assertNotFalse($start);
    $next = strpos($editor, "\n    public function ", $start + 10);
    $body = $next === false ? substr($editor, (int) $start) : substr($editor, (int) $start, $next - (int) $start);
    self::assertStringContainsString('$layoutWriter->updateWidgetConfig(', $body);
    self::assertStringContainsString("'node_uid' => \$saved['node_uid']", $body);
    self::assertStringNotContainsString('getWidgetByLayoutId', $body);
    self::assertStringNotContainsString('$this->layoutService->updateWidgetConfig', $body);
}
}
