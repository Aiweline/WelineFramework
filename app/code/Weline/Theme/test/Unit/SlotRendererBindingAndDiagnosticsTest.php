<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Test\TestCore;
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
            $binding = new EntityRenderBinding(3, 'actual-owner', '', 'tv10', 's-unit', 'c-unit', 'chrome', $path, '', '', '', '');
            RequestContext::set('theme.layout_entity.preview_entity', [
                'theme_id' => 3, 'chrome_version_id' => 10, 'chrome_scope' => 'actual-owner', 'scope' => 'request-scope',
            ]);
            RequestContext::set('theme.layout_entity.chrome_binding.' . hash('sha256', json_encode([3, 'actual-owner', 10], JSON_THROW_ON_ERROR)), $binding);
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
            $bindings[] = new EntityRenderBinding(3, 'owner-' . $index, '', 'tv' . $index, 's-unit', basename($path), 'chrome', '', $path, '', '', '');
        }
        try {
            RequestContext::set('theme.layout_entity.rendered_chrome_bindings', $bindings);
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
                $binding = new EntityRenderBinding(3, $scope, '', 'tv' . (10 - $index), 's-unit', 'config-' . $index, 'chrome', '', '', '', '', '');
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
            $binding = new EntityRenderBinding(3, 'actual-owner', '', 'tv10', 's-unit', basename($path), 'chrome', '', $path, '', '', '');
            RequestContext::set('theme.layout_entity.rendered_chrome_binding', $binding);
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
}
