<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Test\TestCore;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Service\LayoutEntity\EntityRenderBinding;
use Weline\Theme\Service\SlotRendererService;

final class SlotRendererBindingAndDiagnosticsTest extends TestCore
{
    public function testValidSiblingAndContainerAreNotReportedAsUnavailable(): void
    {
        $service = (new \ReflectionClass(SlotRendererService::class))->newInstanceWithoutConstructor();
        $good = str_repeat('a', 32);
        $bad = str_repeat('b', 32);
        $parent = str_repeat('c', 32);
        $service->syncUnavailableWidgetsFromHtml('<div class="widget-wrapper" data-node-uid="' . $parent . '" data-widget-code="container">'
            . '<div class="widget-wrapper" data-node-uid="' . $good . '" data-widget-code="account"><a>My account</a></div>'
            . '<div class="widget-wrapper" data-node-uid="' . $bad . '" data-widget-code="retired" data-widget-module="Weline_Theme" data-unavailable="1">'
            . '<div class="widget-unavailable-tip" data-unavailable-reason="missing_definition">Missing definition</div></div></div>');
        $diagnostics = $service->getUnavailableWidgets();
        self::assertSame([$bad], array_column($diagnostics, 'node_uid'));
        self::assertSame('missing_definition', $diagnostics[0]['reason']);
        self::assertSame('retired', $diagnostics[0]['widget_code']);
    }

    public function testScriptExamplesDoNotCreateUnavailableWidgets(): void
    {
        $service = (new \ReflectionClass(SlotRendererService::class))->newInstanceWithoutConstructor();
        $uid = str_repeat('a', 32);
        $service->syncUnavailableWidgetsFromHtml('<script>const sample = \'<div class="widget-wrapper" data-node-uid="'
            . $uid . '" data-unavailable="1"><div class="widget-unavailable-tip">Example</div></div>\';</script>');
        self::assertSame([], $service->getUnavailableWidgets());
    }

    public function testNestedChromeUsesPinnedBindingAndExcludesExplicitlyRemovedNodes(): void
    {
        $this->assertBoundNodes(false);
    }

    public function testEmptyPinnedChromeDoesNotResurrectPublishedNodes(): void
    {
        $this->assertBoundNodes(true);
    }

    public function testExplicitPreviewUsesItsOwnerScopeAndVersion(): void
    {
        $oldContext = Context::getCurrent();
        Context::enter(new Context());
        $path = tempnam(sys_get_temp_dir(), 'preview-chrome-');
        try {
            $identity = new ThemeVersionIdentity(3, 'actual-owner', 'default', 'frontend', 10, ThemeVersionIdentity::MODE_DRAFT, 1);
            $binding = new EntityRenderBinding($identity, 'chrome', '', 's-unit', 'c-unit', '', $path, '', '', '', '');
            RequestContext::set('theme.layout_entity.preview_entity', [
                'theme_id' => 3, 'chrome_version_id' => 10, 'chrome_scope' => 'actual-owner', 'scope' => 'request-scope',
            ]);
            // v3 投影键以 identity（含 owner/V/mode/R）为种子；预览时 selection 会把 scope/version
            // 覆写为 owner 值（actual-owner/10）。若覆写失效，键会落到 wrong-legacy-scope/9 而查不中。
            RequestContext::set(
                'theme.layout_entity.chrome_source.' . hash('sha256', json_encode([3, 'actual-owner', 10, true], JSON_THROW_ON_ERROR)),
                ['path' => $path, 'binding' => $binding, 'scope' => 'actual-owner', 'version_id' => 10, 'preview' => true],
            );
            $chrome = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityChrome::class);
            self::assertTrue(method_exists($chrome, 'resolveRenderSource'), 'Root and nested slots need the same version selector.');
            $source = $chrome->resolveRenderSource(3, 'wrong-legacy-scope', 9, false);
            self::assertSame($binding, $source['binding']);
            self::assertSame('actual-owner', $source['scope']);
            self::assertSame(10, $source['version_id']);
            self::assertTrue($source['preview']);
        } finally {
            unlink($path);
            $oldContext !== null ? Context::enter($oldContext) : Context::leave();
        }
    }

    public function testNearestChromeBindingWinsOverTheLastRenderedAncestor(): void
    {
        $oldContext = Context::getCurrent();
        Context::enter(new Context());
        $paths = [tempnam(sys_get_temp_dir(), 'near-chrome-'), tempnam(sys_get_temp_dir(), 'old-chrome-')];
        $bindings = [];
        foreach ($paths as $index => $path) {
            $uid = str_repeat($index === 0 ? 'd' : 'e', 32);
            file_put_contents($path, json_encode([$uid => ['node_uid' => $uid, 'widget_module' => 'Weline_Theme',
                'widget_type' => 'footer', 'widget_code' => $index === 0 ? 'footer-faq-link' : 'retired',
                'slot_id' => 'footer-help-links', 'area' => 'footer']], JSON_THROW_ON_ERROR));
            $identity = new ThemeVersionIdentity(3, 'owner-' . $index, 'default', 'frontend', $index + 1, ThemeVersionIdentity::MODE_DRAFT, 1);
            $bindings[] = new EntityRenderBinding($identity, 'chrome', '', 's-unit', basename($path), '', $path, '', '', '', '');
        }
        try {
            // v3：该方法经 resolveRenderSources 取候选链（首绑最近），末位已渲染祖先不得覆盖它。
            $this->seedChromeSourceProjection($bindings);
            RequestContext::set('theme.layout_entity.rendered_chrome_binding', $bindings[1]);
            $reflection = new \ReflectionClass(SlotRendererService::class);
            $slots = $reflection->getMethod('loadSharedChromeSlotWidgetsFromEntity')->invoke($reflection->newInstanceWithoutConstructor(), 3, 'frontend');
            self::assertSame([str_repeat('d', 32)], array_column($slots['footer-help-links'] ?? [], 'node_uid'));
        } finally {
            foreach ($paths as $path) { unlink($path); }
            $oldContext !== null ? Context::enter($oldContext) : Context::leave();
        }
    }

    public function testChromeSourcesRestoreNearestFirstBindingsOnRepeatedLookup(): void
    {
        $oldContext = Context::getCurrent();
        Context::enter(new Context());
        try {
            $sources = [];
            foreach (['fixture-scope', 'default.default.default'] as $index => $scope) {
                $identity = new ThemeVersionIdentity(3, $scope, 'default', 'frontend', 10 - $index, ThemeVersionIdentity::MODE_DRAFT, 1);
                $binding = new EntityRenderBinding($identity, 'chrome', '', 's-unit', 'config-' . $index, '', '', '', '', '', '');
                $sources[] = ['binding' => $binding, 'scope' => $scope, 'version_id' => 10 - $index, 'path' => '', 'preview' => true];
                RequestContext::set('theme.layout_entity.chrome_source.' . hash('sha256', json_encode([3, $scope, null, true], JSON_THROW_ON_ERROR)), $sources[$index]);
            }
            $scopes = $this->createMock(\Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface::class);
            $scopes->method('fromStorageScope')->willReturn(null);
            $chrome = new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityChrome(
                (new \ReflectionClass(\Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPointerResolver::class))->newInstanceWithoutConstructor(),
                new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths(), null, $scopes,
            );
            self::assertTrue(method_exists($chrome, 'resolveRenderSources'), 'Root projection and nested reads need the same inherited sources on cache hits.');
            self::assertSame($sources, $chrome->resolveRenderSources(3, 'fixture-scope', true));
            RequestContext::remove('theme.layout_entity.rendered_chrome_bindings');
            $chrome->resolveRenderSources(3, 'fixture-scope', true);
            self::assertSame(array_column($sources, 'binding'), RequestContext::get('theme.layout_entity.rendered_chrome_bindings'));
        } finally {
            $oldContext !== null ? Context::enter($oldContext) : Context::leave();
        }
    }

    private function assertBoundNodes(bool $empty): void
    {
        $oldContext = Context::getCurrent();
        Context::enter(new Context());
        $path = tempnam(sys_get_temp_dir(), 'bound-chrome-');
        $active = str_repeat('d', 32);
        $inactive = str_repeat('e', 32);
        $nodes = $empty ? [] : [
            $active => ['node_uid' => $active, 'widget_module' => 'Weline_Theme', 'widget_type' => 'footer',
                'widget_code' => 'footer-faq-link', 'slot_id' => 'footer-help-links', 'area' => 'footer', 'is_active' => true],
            $inactive => ['node_uid' => $inactive, 'widget_module' => 'Weline_Theme', 'widget_type' => 'footer',
                'widget_code' => 'retired', 'slot_id' => 'footer-help-links', 'area' => 'footer', 'is_active' => false],
        ];
        file_put_contents($path, json_encode($nodes, JSON_THROW_ON_ERROR));
        try {
            $identity = new ThemeVersionIdentity(3, 'actual-owner', 'default', 'frontend', 10, ThemeVersionIdentity::MODE_DRAFT, 1);
            $binding = new EntityRenderBinding($identity, 'chrome', '', 's-unit', basename($path), '', $path, '', '', '', '');
            // v3：固定槽读取改走 resolveRenderSources 投影，不再直读 rendered_chrome_binding。
            $this->seedChromeSourceProjection([$binding]);
            $reflection = new \ReflectionClass(SlotRendererService::class);
            $service = $reflection->newInstanceWithoutConstructor();
            $slots = $reflection->getMethod('loadSharedChromeSlotWidgetsFromEntity')->invoke($service, 3, 'frontend');
            self::assertSame($empty ? [] : [$active], array_column($slots['footer-help-links'] ?? [], 'node_uid'));
            self::assertSame($empty ? [] : ['footer-help-links'], array_keys($slots));
        } finally {
            unlink($path);
            $oldContext !== null ? Context::enter($oldContext) : Context::leave();
        }
    }

    /**
     * v3 后 loadSharedChromeSlotWidgetsFromEntity 经 ThemeLayoutEntityChrome::resolveRenderSources
     * 读投影缓存，不再直读 rendered_chrome_binding(s)。这里按同一 scope/preview 计算出投影键并播种，
     * 使用例仍能验证「绑定链顺序与槽归属」这一原意。
     *
     * @param list<EntityRenderBinding> $bindings
     */
    private function seedChromeSourceProjection(array $bindings): void
    {
        $reflection = new \ReflectionClass(SlotRendererService::class);
        $probe = $reflection->newInstanceWithoutConstructor();
        $storageScope = (string)$reflection->getMethod('resolveStorageScopeForSharedChrome')->invoke($probe, 'frontend');
        $preview = (bool)$reflection->getMethod('isEditorPreviewRequest')->invoke($probe);
        $selection = RequestContext::get('theme.layout_entity.preview_entity');
        $sources = [];
        foreach ($bindings as $binding) {
            $sources[] = [
                'path' => $binding->templatePath,
                'binding' => $binding,
                'scope' => $binding->identity->canonicalScope,
                'version_id' => $binding->identity->themeVersionId,
                'preview' => $preview,
            ];
        }
        RequestContext::set(
            'theme.layout_entity.chrome_sources.' . hash('sha256', json_encode([3, $storageScope, $preview, $selection], JSON_THROW_ON_ERROR)),
            $sources,
        );
    }
}
