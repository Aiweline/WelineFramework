<?php

declare(strict_types=1);

namespace Weline\Theme\test\Unit\Region;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Region\FragmentRegionProvider;
use Weline\Theme\Region\GlobalRegion;
use Weline\Theme\Region\GlobalRegionHtmlInjector;
use Weline\Theme\Region\GlobalRegionSwitch;

/**
 * 两条路径必须等价：编译期锚点（布局产物里）与运行期出口注入（布局没有锚点时）。
 *
 * 线上实测过「页面布局根本不经 com 编译物」，真正生效的是出口注入；所以这里钉死
 * 「出口注入产出的片段集合」与「直接渲染同一批 region」完全一致——任何一条路径退化，
 * 用例就会红。
 */
class GlobalRegionRuntimePathTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/weline_region_runtime_' . \bin2hex(\random_bytes(4));
        \mkdir($this->root . '/app', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
        GlobalRegion::reset();
        FragmentRegionProvider::flushScanCache();
    }

    public function testExitInjectionProducesSameFragmentsAsRegionRender(): void
    {
        $this->put(
            'app/code/Demo/Foo/view/theme/base/body_end/ops/01-a.phtml',
            "<?php\n/**\n * @widget.code {demo-a}\n */\n?><div>a</div>",
        );
        $this->put(
            'app/code/Demo/Foo/view/theme/base/body_end/ops/02-b.phtml',
            "<?php\n/**\n * @widget.code {demo-b}\n */\n?><div>b</div>",
        );
        $this->put(
            'app/code/Demo/Foo/view/theme/base/body_end/ops/03-off.phtml',
            "<?php\n/**\n * @widget.code {demo-off}\n */\n?><div>off</div>",
        );

        $switch = new GlobalRegionSwitch([
            'items' => [
                'base.body_end.ops.01-a' => true,
                'base.body_end.ops.02-b' => true,
                'base.body_end.ops.03-off' => false,
            ],
        ]);
        GlobalRegion::useProjectRoot($this->root);
        GlobalRegion::useSwitch($switch);
        FragmentRegionProvider::flushScanCache();

        $direct = GlobalRegion::render('body_end');
        $injected = (new GlobalRegionHtmlInjector())->ensure(
            "<!doctype html><html><head><title>t</title></head><body><p>x</p></body></html>"
        );

        $ids = static function (string $html): array {
            \preg_match_all('/data-global-region-async="([^"]+)"/', $html, $m);

            return \array_values($m[1]);
        };

        self::assertSame(['base.body_end.ops.01-a', 'base.body_end.ops.02-b'], $ids($direct), '只出启用项');
        self::assertSame($ids($direct), $ids($injected), '出口注入与区域渲染必须一致');
        self::assertStringContainsString('data-weline-global-region-hydrator', $injected, '注入结果带水合器');
    }

    public function testInjectionIsNoOpWhenAnchorsAlreadyProducedFragments(): void
    {
        $this->put(
            'app/code/Demo/Foo/view/theme/base/body_end/ops/01-a.phtml',
            "<?php\n/**\n * @widget.code {demo-a}\n */\n?><div>a</div>",
        );

        GlobalRegion::useProjectRoot($this->root);
        GlobalRegion::useSwitch(new GlobalRegionSwitch(['items' => ['base.body_end.ops.01-a' => true]]));
        FragmentRegionProvider::flushScanCache();

        $withAnchors = '<html><body><div data-global-region-async="base.body_end.ops.01-a" '
            . 'data-global-region-endpoint="/x" hidden></div></body></html>';
        $result = (new GlobalRegionHtmlInjector())->ensure($withAnchors);

        self::assertSame($withAnchors, $result, '已有锚点产物时不再补一份');
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
        foreach (\scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            \is_dir($path) ? $this->removeDir($path) : @\unlink($path);
        }
        @\rmdir($dir);
    }
}
