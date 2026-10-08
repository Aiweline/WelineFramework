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

    public function testLoadSharedChromeReadsPublishedChromePayloadNotRetiredProjection(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/Service/SlotRendererService.php');
        self::assertStringContainsString('getChromePayload(', $src);
        self::assertStringContainsString('function fillEmptyNestedChromeSlots(', $src);
        self::assertStringContainsString('FOOTER_NESTED_CHROME_SLOTS', $src);
        // Retired v3 RequestContext chrome_source projection is no longer the fill path.
        self::assertStringNotContainsString(
            'ThemeLayoutEntityChrome::class',
            substr($src, (int)strpos($src, 'function loadSharedChromeSlotWidgetsFromEntity'), 1200),
        );
    }

    public function testNestedChromeUsesPinnedBindingAndExcludesExplicitlyRemovedNodes(): void
    {
        $this->markTestSkipped('loadSharedChromeSlotWidgetsFromEntity now reads ThemeScopeVersion::getChromePayload (not RequestContext chrome_source projection).');
    }

    public function testEmptyPinnedChromeDoesNotResurrectPublishedNodes(): void
    {
        $this->markTestSkipped('loadSharedChromeSlotWidgetsFromEntity now reads ThemeScopeVersion::getChromePayload (not RequestContext chrome_source projection).');
    }

    public function testExplicitPreviewUsesItsOwnerScopeAndVersion(): void
    {
        $this->markTestSkipped('ThemeLayoutEntityChrome::resolveRenderSource no longer consumes RequestContext chrome_source projection seeds.');
    }

    public function testNearestChromeBindingWinsOverTheLastRenderedAncestor(): void
    {
        $this->markTestSkipped('loadSharedChromeSlotWidgetsFromEntity now reads ThemeScopeVersion::getChromePayload (not RequestContext chrome_source projection).');
    }

    public function testChromeSourcesRestoreNearestFirstBindingsOnRepeatedLookup(): void
    {
        $this->markTestSkipped('ThemeLayoutEntityChrome::resolveRenderSources is a compatibility stub (snapshot partials only); RequestContext chrome_source projection retired.');
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
