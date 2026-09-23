<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Api\Layout\LayoutIdentity;
use Weline\Theme\Service\LayoutEntity\EntityRenderBinding;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityChrome;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityConfigStore;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutSlotTreeBuilder;
use Weline\Theme\Service\SlotRendererService;

final class SharedChromeAssetBindingTest extends TestCase
{
    public function testAncestorHeadBindingDoesNotDiscardCurrentScopeLayoutAssets(): void
    {
        require_once dirname(__DIR__, 3) . '/Service/SlotRendererService.php';
        foreach (['EntityRenderBinding', 'ThemeLayoutEntityChrome', 'ThemeLayoutEntityConfigStore', 'ThemeLayoutEntityPaths', 'ThemeLayoutSlotTreeBuilder'] as $class) {
            require_once dirname(__DIR__, 3) . '/Service/LayoutEntity/' . $class . '.php';
        }
        $previous = Context::getCurrent();
        Context::enter(new Context());
        $files = [tempnam(sys_get_temp_dir(), 'chrome-local-'), tempnam(sys_get_temp_dir(), 'chrome-parent-')];
        try {
            $uid = str_repeat('a', 32);
            $assets = 'Weline_Theme::css/widgets/widget-product-cross-sell-default.css,Weline_Theme::js/widgets/widget-product-cross-sell-default-0.js';
            file_put_contents($files[0], json_encode([$uid => ['node_uid' => $uid, 'widget_module' => 'Weline_Theme', 'widget_type' => 'header', 'widget_code' => 'account', 'area' => 'header', 'slot_id' => 'user-area', 'layout_source' => $assets, 'config' => ['_layout_source' => $assets]]]));
            file_put_contents($files[1], json_encode([str_repeat('b', 32) => ['widget_module' => 'Weline_Theme', 'widget_type' => 'header', 'widget_code' => 'account', 'area' => 'header', 'slot_id' => 'user-area']]));
            $scope = 'website.default.default';
            $bindings = [];
            foreach ($files as $i => $file) {
                $bindings[] = new EntityRenderBinding(3, $i === 0 ? $scope : 'default.default.default', '', 'tv' . $i, 's' . $i, basename($file), 'chrome', '', $file, '', '', '');
            }
            RequestContext::set(LayoutIdentity::REQUEST_CONTEXT_KEY, new LayoutIdentity(scope: $scope));
            RequestContext::set('theme.layout_entity.rendered_chrome_binding', $bindings[1]);
            $key = 'theme.layout_entity.chrome_sources.' . hash('sha256', json_encode([3, $scope, false, null], JSON_THROW_ON_ERROR));
            RequestContext::set($key, array_map(static fn($binding) => ['binding' => $binding], $bindings));
            $store = new ThemeLayoutEntityConfigStore(new ThemeLayoutEntityPaths());
            ObjectManager::setInstance(ThemeLayoutEntityConfigStore::class, $store);
            foreach ([ThemeLayoutEntityChrome::class, ThemeLayoutSlotTreeBuilder::class] as $class) {
                $instance = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
                ObjectManager::setInstance($class, $instance);
            }
            $renderer = (new \ReflectionClass(SlotRendererService::class))->newInstanceWithoutConstructor();
            self::assertSame($store, ObjectManager::getInstance(ThemeLayoutEntityConfigStore::class));
            self::assertNotEmpty($store->readBoundConfig($bindings[1]));
            self::assertNotEmpty(ObjectManager::getInstance(ThemeLayoutSlotTreeBuilder::class)->nodesToAreaLayout($store->readBoundConfig($bindings[1])));
            $slots = (new \ReflectionMethod($renderer, 'loadSharedChromeSlotWidgetsFromEntity'))->invoke($renderer, 3, 'frontend');
            self::assertSame('account', $slots['user-area'][0]['widget_code'] ?? null);
            self::assertSame($uid, $slots['user-area'][0]['node_uid'] ?? null);
            self::assertSame($assets, $slots['user-area'][0]['config']['_layout_source'] ?? null);
        } finally {
            foreach ($files as $file) { unlink($file); }
            if ($previous !== null) { Context::enter($previous); } else { Context::leave(); }
        }
    }
}
