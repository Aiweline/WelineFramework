<?php

declare(strict_types=1);

namespace Weline\Theme\test\Unit\Region;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Region\FragmentRegionProvider;
use Weline\Theme\Region\GlobalRegion;
use Weline\Theme\Region\GlobalRegionFragmentScanner;
use Weline\Theme\Region\GlobalRegionSwitch;

/**
 * Locks the async half of the contract: base fragments ship a placeholder, only
 * fragments that declare `@global-region.eager {true}` are rendered inline.
 */
class GlobalRegionAsyncTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/weline_region_async_' . \bin2hex(\random_bytes(4));
        \mkdir($this->root . '/app', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
        GlobalRegion::reset();
        FragmentRegionProvider::flushScanCache();
    }

    public function testEagerAnnotationIsParsed(): void
    {
        $this->put(
            'app/code/Demo/Foo/view/theme/base/head/analytics/01-pixel.phtml',
            "<?php\n/**\n * @widget.code {demo-pixel}\n * @global-region.eager {true}\n */\n?><meta name=\"p\" content=\"1\">",
        );

        $fragments = (new GlobalRegionFragmentScanner(GlobalRegionFragmentScanner::defaultRoots($this->root)))->scan()['fragments'];
        self::assertCount(1, $fragments);
        self::assertTrue($fragments[0]->eager);
        self::assertSame('demo-pixel', $fragments[0]->widgetCode);
    }

    public function testFragmentsDefaultToAsync(): void
    {
        $this->put(
            'app/code/Demo/Foo/view/theme/base/body_end/ops/01-banner.phtml',
            "<?php\n/**\n * @widget.code {demo-banner}\n */\n?><div>inline</div>",
        );

        $fragments = (new GlobalRegionFragmentScanner(GlobalRegionFragmentScanner::defaultRoots($this->root)))->scan()['fragments'];
        self::assertCount(1, $fragments);
        self::assertFalse($fragments[0]->eager);
    }

    public function testAsyncFragmentRendersPlaceholderInsteadOfMarkup(): void
    {
        $this->put(
            'app/code/Demo/Foo/view/theme/base/body_end/ops/01-banner.phtml',
            "<?php\n/**\n * @widget.code {demo-banner}\n */\n?><div>inline-markup</div>",
        );

        GlobalRegion::useProjectRoot($this->root);
        GlobalRegion::useSwitch(new GlobalRegionSwitch(['items' => ['base.body_end.ops.01-banner' => true]]));
        FragmentRegionProvider::flushScanCache();

        $html = GlobalRegion::render('body_end');
        self::assertStringContainsString('data-global-region-async="base.body_end.ops.01-banner"', $html);
        self::assertStringContainsString('fragment=base.body_end.ops.01-banner', $html);
        self::assertStringNotContainsString('inline-markup', $html);
    }

    public function testEagerFragmentRendersInline(): void
    {
        $this->put(
            'app/code/Demo/Foo/view/theme/base/head/analytics/01-pixel.phtml',
            "<?php\n/**\n * @widget.code {demo-pixel}\n * @global-region.eager {true}\n */\n?><meta name=\"pixel-inline\" content=\"1\">",
        );

        GlobalRegion::useProjectRoot($this->root);
        GlobalRegion::useSwitch(new GlobalRegionSwitch(['items' => ['base.head.analytics.01-pixel' => true]]));
        FragmentRegionProvider::flushScanCache();

        $html = GlobalRegion::render('head');
        self::assertStringContainsString('pixel-inline', $html);
        self::assertStringNotContainsString('data-global-region-async', $html);
    }

    /**
     * 回归：水合器必须与「本区有异步占位」同生，不能依赖跨调用的静态标记。
     *
     * 线上曾经出现「同一请求里更早一次渲染（输出被丢弃）把脚本吃掉，最终页面只剩占位、
     * 没有水合器」——所有全局组件因此停摆。这里直接渲染两次并要求每次都带水合器。
     */
    public function testHydratorAccompaniesEveryAsyncRender(): void
    {
        $this->put(
            'app/code/Demo/Foo/view/theme/base/body_end/ops/01-banner.phtml',
            "<?php\n/**\n * @widget.code {demo-banner}\n */\n?><div>banner</div>",
        );

        GlobalRegion::useProjectRoot($this->root);
        GlobalRegion::useSwitch(new GlobalRegionSwitch(['items' => ['base.body_end.ops.01-banner' => true]]));
        FragmentRegionProvider::flushScanCache();
        $provider = FragmentRegionProvider::forProject($this->root, 'body_end', static fn(): bool => true);

        $first = $provider->render();
        $second = $provider->render();
        self::assertStringContainsString('data-weline-global-region-hydrator', $first);
        self::assertStringContainsString('data-weline-global-region-hydrator', $second);
    }

    /** 禁止回潮：同名片段启用后，旧部件的槽渲染必须让位。 */
    public function testReplacesWidgetOnlyWhenFragmentIsEnabled(): void
    {
        $this->put(
            'app/code/Demo/Foo/view/theme/base/head/analytics/01-pixel.phtml',
            "<?php\n/**\n * @widget.code {demo-pixel}\n * @global-region.eager {true}\n */\n?><meta name=\"p\" content=\"1\">",
        );

        GlobalRegion::useProjectRoot($this->root);
        FragmentRegionProvider::flushScanCache();

        GlobalRegion::useSwitch(new GlobalRegionSwitch([]));
        self::assertFalse(GlobalRegion::replacesWidget('demo-pixel'), 'module fragment is off by default');

        // 判定按请求记忆（生产里开关在一个请求内不变），换开关后重开一次请求态。
        GlobalRegion::reset();
        GlobalRegion::useProjectRoot($this->root);
        FragmentRegionProvider::flushScanCache();
        GlobalRegion::useSwitch(new GlobalRegionSwitch(['items' => ['base.head.analytics.01-pixel' => true]]));
        self::assertTrue(GlobalRegion::replacesWidget('demo-pixel'));
        self::assertFalse(GlobalRegion::replacesWidget('other-widget'));
    }

    /**
     * 水合器必须在片段水合后激活其声明的模块。
     *
     * weline.js 的 data-weline-load 扫描只在 DOMContentLoaded 跑一次；异步片段
     * （div[data-global-region-endpoint] 取回后 innerHTML）注入时那一轮早已结束。
     * 若水合器不主动把新内容里的声明交给原生加载器，部件脚本永远不加载 ——
     * 表现是「按钮可见、点击无任何反应」（2026-11-03 实测：进店音乐 / 悬浮客服）。
     */
    public function testHydratorActivatesDeclaredModulesOfHydratedFragment(): void
    {
        $js = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/view/statics/js/global-region-async.js'
        );
        self::assertStringContainsString('function activateDeclaredModules(', $js);
        // 必须在 hydrate 内、innerHTML 写入之后调用（拿到已水合的节点）。
        self::assertStringContainsString('node.innerHTML = html;', $js);
        $innerPos = \strpos($js, 'node.innerHTML = html;');
        $activatePos = \strpos($js, 'activateDeclaredModules(node);');
        self::assertNotFalse($innerPos);
        self::assertNotFalse($activatePos);
        self::assertGreaterThan($innerPos, $activatePos, '模块激活必须发生在 innerHTML 写入之后');
        // 走原生加载入口 Weline.load，不自建加载链路。
        self::assertStringContainsString('loader.load', $js);
        self::assertStringContainsString("collect('data-weline-load')", $js);
        self::assertStringContainsString("collect('data-weline-declare')", $js);
    }

    /**
     * 水合内容里若有声明式挂载宿主（data-weline-mount），水合后必须让它被扫描到。
     *
     * weline.js 的 mount 扫描同样只在 DOMContentLoaded 跑一次；基座浮层片段必须
     * 非 eager 才能进浮层槽，因此宿主必然落在异步片段里 —— 不扫就永远停在 pending。
     * 只扫水合节点、不 force：已 ready 的宿主由扫描器自身跳过，force 会二次挂载。
     */
    public function testHydratorActivatesMountHostsOfHydratedFragment(): void
    {
        $js = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/view/statics/js/global-region-async.js'
        );
        // 声明式挂载宿主的模块清单与 data-weline-load 是两套属性，必须两套都收集。
        self::assertStringContainsString("collect('data-weline-mount-load')", $js);
        // 必须把扫描范围限定到水合节点，不能整页重扫（整页 force 重扫会二次挂已 ready 的宿主）。
        self::assertStringContainsString('mount.scan({ root:', $js);
        self::assertStringNotContainsString('mount.scan({ root: root, force: true }', $js);
        // 必须发生在 innerHTML 写入之后。
        self::assertGreaterThan(
            \strpos($js, 'node.innerHTML = html;'),
            \strpos($js, 'mount.scan({ root:')
        );
    }

    /**
     * 主题已经声明过同名浮层槽时，外来片段必须并入它，不得再造第二个同 id 壳。
     *
     * StorefrontFloatLayerHost::publishedSlotLayerMarkup 会输出
     * data-slot-id="storefront-float-<dir>" 的静态壳；片段自己又被 adoptSlot 补上一个。
     * 并壳成双后，「取首个匹配」的消费者会落进空容器（ego 实测 12 槽 / 4 组重复 id）。
     */
    public function testAdoptSlotMergesIntoThemeDeclaredSlot(): void
    {
        $js = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/view/statics/js/global-region-async.js'
        );
        // 主题侧已声明同名槽（StorefrontFloatLayerHost::publishedSlotLayerMarkup 产出）。
        self::assertStringContainsString(
            "layer.querySelector('[data-slot-id=\"storefront-float-' + dir + '\"]')",
            $js
        );
        // 并入后必须移除自己，否则 layer 内仍留一个空壳同 id。
        self::assertStringContainsString('slot.remove();', $js);
        // 并入必须先于「新建壳」分支。
        self::assertLessThan(
            \strpos($js, "slot.classList.add('w-storefront-float-layer__slot--' + dir);"),
            \strpos($js, 'slot.remove();')
        );
    }

    private function put(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;
        $dir = \dirname($path);
        if (!\is_dir($dir)) {
            \mkdir($dir, 0777, true);
        }
        \file_put_contents($path, $contents);
    }

    private function removeDir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        $items = \scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            \is_dir($path) ? $this->removeDir($path) : @\unlink($path);
        }
        @\rmdir($dir);
    }
}
