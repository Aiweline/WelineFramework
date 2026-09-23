<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit\LayoutEntity;
use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\EntityRenderBinding;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityConfigStore;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths;
require_once dirname(__DIR__, 7) . '/vendor/autoload.php';
final class ThemeLayoutEntityBoundConfigTest extends TestCase
{
    public function testAncestorChromeAssetsUseEveryRenderedBinding(): void
    {
        $files = [tempnam(sys_get_temp_dir(), 'chrome-assets-'), tempnam(sys_get_temp_dir(), 'chrome-assets-')];
        $previous = \Weline\Framework\Context::getCurrent();
        \Weline\Framework\Context::enter(new \Weline\Framework\Context());
        try {
            $bindings = [];
            foreach ($files as $index => $file) {
                file_put_contents($file, json_encode(['layout_css' => ['scope'.$index.'.css']]));
                $bindings[] = new EntityRenderBinding(1, 'scope'.$index, '', 'tv'.$index, 's'.$index, basename($file), 'chrome', '', '', $file, '', '');
            }
            \Weline\Framework\Runtime\RequestContext::set('theme.layout_entity.rendered_chrome_bindings', $bindings);
            $paths = new ThemeLayoutEntityPaths();
            $head = new \Weline\Theme\Service\LayoutEntity\ThemeLayoutStorefrontHeadAssets(new ThemeLayoutEntityConfigStore($paths), new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityAssetCollector(null), $paths);
            $result = (new \ReflectionMethod($head, 'renderedChromeAssets'))->invoke($head, 1);
            self::assertSame(['scope0.css', 'scope1.css'], $result['layout_css']);
        } finally {
            foreach ($files as $file) { unlink($file); }
            if ($previous !== null) { \Weline\Framework\Context::enter($previous); } else { \Weline\Framework\Context::leave(); }
        }
    }

    public function testEmptyAssetManifestsCanBeMergedForHeadRendering(): void
    {
        $paths = new ThemeLayoutEntityPaths();
        $head = new \Weline\Theme\Service\LayoutEntity\ThemeLayoutStorefrontHeadAssets(
            new ThemeLayoutEntityConfigStore($paths),
            new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityAssetCollector(null),
            $paths,
        );
        self::assertStringContainsString('data-weline-widget-assets-fp', $head->mergeAndEmit([], []));
    }

    public function testAssetsUseTheBindingAlreadySelectedForTheBody(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'theme-bound-assets-');
        $previous = \Weline\Framework\Context::getCurrent();
        \Weline\Framework\Context::enter(new \Weline\Framework\Context());
        try {
            $assets = ['layout_css' => ['old-structure.css']];
            file_put_contents($file, json_encode($assets));
            $binding = new EntityRenderBinding(1, 'scope', 'identity', 'r1', 's1', 'c1', 'page', '', '', $file, '', '');
            $key = 'theme.layout_entity.page_binding.' . hash('sha256', json_encode([1, 'scope', 'identity', 'r1'], JSON_THROW_ON_ERROR));
            \Weline\Framework\Runtime\RequestContext::set($key, $binding);
            $store = new ThemeLayoutEntityConfigStore(new ThemeLayoutEntityPaths());
            self::assertSame($assets, $store->readPageAssets(1, 'scope', 'identity', 'r1'));
        } finally {
            unlink($file);
            if ($previous !== null) { \Weline\Framework\Context::enter($previous); }
            else { \Weline\Framework\Context::leave(); }
        }
    }

    public function testOldBindingKeepsConfigurationWhenNewRevisionAppears(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'theme-bound-');
        $next = tempnam(sys_get_temp_dir(), 'theme-bound-');
        try {
            $uid = str_repeat('a', 32);
            $old = [$uid => ['widget_module' => 'Weline_Theme', 'widget_code' => 'text', 'config' => ['label' => 'old']]];
            $new = [$uid => ['widget_module' => 'Weline_Theme', 'widget_code' => 'text', 'config' => ['label' => 'new']]];
            file_put_contents($file, json_encode($old));
            file_put_contents($next, json_encode($new));
            $binding = new EntityRenderBinding(1, 'scope', 'identity', 'r1', 's1', basename($file), 'page', '', $file, '', '', '');
            $newBinding = new EntityRenderBinding(1, 'scope', 'identity', 'r1', 's1', basename($next), 'page', '', $next, '', '', '');
            $store = new ThemeLayoutEntityConfigStore(new ThemeLayoutEntityPaths());
            self::assertSame($old, $store->readBoundConfig($binding));
            self::assertSame($new, $store->readBoundConfig($newBinding));
            self::assertSame($old, $store->readBoundConfig($binding));
        } finally {
            unlink($file);
            unlink($next);
        }
    }
}
