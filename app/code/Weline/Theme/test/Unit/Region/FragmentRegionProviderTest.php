<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Region;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Region\FragmentRegionProvider;
use Weline\Theme\Region\GlobalRegion;
use Weline\Theme\Region\GlobalRegionFragment;
use Weline\Theme\Region\GlobalRegionFragmentScanner;
use Weline\Theme\Region\GlobalRegionSwitch;

final class FragmentRegionProviderTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/weline-global-prov-' . \bin2hex(\random_bytes(6));
        \mkdir($this->root, 0775, true);
        FragmentRegionProvider::flushScanCache();
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
        FragmentRegionProvider::flushScanCache();
    }

    private function put(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;
        $dir = \dirname($path);
        if (!\is_dir($dir)) {
            \mkdir($dir, 0775, true);
        }
        \file_put_contents($path, $contents);
    }

    private function remove(string $path): void
    {
        if (!\is_dir($path)) {
            return;
        }
        foreach ((array)\scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            if (\is_dir($child) && !\is_link($child)) {
                $this->remove($child);
                continue;
            }
            @\unlink($child);
        }
        @\rmdir($path);
    }

    private function themeRoots(): array
    {
        return [['source' => GlobalRegionFragment::SOURCE_THEME, 'path' => $this->root . '/theme']];
    }

    public function testRendersEnabledFragmentsInContractOrder(): void
    {
        $this->put('theme/head/ops/02-second.phtml', '<meta name="second" content="2">');
        $this->put('theme/head/ops/01-first.phtml', '<meta name="first" content="1">');

        $html = (new FragmentRegionProvider('head', $this->themeRoots()))->render();

        self::assertStringContainsString('<meta name="first" content="1">', $html);
        self::assertStringContainsString('<meta name="second" content="2">', $html);
        self::assertLessThan(
            \strpos($html, '<meta name="second"'),
            \strpos($html, '<meta name="first"'),
            '01- prefix must render before 02-',
        );
    }

    public function testOnlyTheRequestedRegionIsRendered(): void
    {
        $this->put('theme/head/ops/01-head.phtml', '<meta name="in-head" content="1">');
        $this->put('theme/body_end/ops/01-tail.phtml', '<div id="in-tail"></div>');

        $html = (new FragmentRegionProvider('head', $this->themeRoots()))->render();

        self::assertStringContainsString('in-head', $html);
        self::assertStringNotContainsString('in-tail', $html);
    }

    public function testModuleFragmentsStayOffUntilEnabled(): void
    {
        $this->put('module/head/analytics/01-pixel.phtml', '<meta name="pixel" content="on">');

        $roots = [['source' => GlobalRegionFragment::SOURCE_MODULE, 'path' => $this->root . '/module']];

        self::assertSame('', (new FragmentRegionProvider('head', $roots))->render(), 'module default is off');

        $enabled = new FragmentRegionProvider('head', $roots, static fn(GlobalRegionFragment $f): bool => true);
        self::assertStringContainsString('content="on"', $enabled->render());
    }

    public function testBrokenFragmentDoesNotBreakTheRegion(): void
    {
        $this->put('theme/head/ops/01-broken.phtml', '<?php throw new \RuntimeException("boom"); ?>');
        $this->put('theme/head/ops/02-fine.phtml', '<meta name="fine" content="1">');

        $html = (new FragmentRegionProvider('head', $this->themeRoots()))->render();

        self::assertStringContainsString('<meta name="fine" content="1">', $html);
    }

    public function testNewFragmentIsDiscoveredWithoutRestartingWorkers(): void
    {
        $this->put('theme/head/ops/01-first.phtml', '<meta name="first" content="1">');
        $provider = new FragmentRegionProvider('head', $this->themeRoots());
        self::assertCount(1, $provider->fragments());

        $this->put('theme/head/ops/02-late.phtml', '<meta name="late" content="2">');
        // Directory mtime granularity is one second on some filesystems.
        @\touch($this->root . '/theme/head/ops', \time() + 2);

        self::assertCount(
            2,
            $provider->fragments(),
            'dropping a fragment in place must not require restarting every worker',
        );
    }

    public function testRemovedFragmentDisappearsWithoutRestart(): void
    {
        $this->put('theme/head/ops/01-first.phtml', '<meta name="first" content="1">');
        $provider = new FragmentRegionProvider('head', $this->themeRoots());
        self::assertCount(1, $provider->fragments());

        @\unlink($this->root . '/theme/head/ops/01-first.phtml');
        @\touch($this->root . '/theme/head/ops', \time() + 2);

        self::assertSame([], $provider->fragments());
    }

    public function testGlobalRegionSelfBootstrapsThemeFragments(): void
    {
        $this->put('app/design/Demo/theme/frontend/widget/base/head/ops/01-x.phtml', '<meta name="boot" content="1">');
        GlobalRegion::useProjectRoot($this->root);
        FragmentRegionProvider::flushScanCache();

        try {
            self::assertStringContainsString('<meta name="boot" content="1">', GlobalRegion::render('head'));
            self::assertSame('', GlobalRegion::render('head'), 'still once per request');
        } finally {
            GlobalRegion::reset();
            FragmentRegionProvider::flushScanCache();
        }
    }

    public function testSwitchTreeControlsWhatRenders(): void
    {
        $this->put('app/code/Demo/Foo/view/theme/base/head/analytics/01-pixel.phtml', '<meta name="pixel" content="on">');

        GlobalRegion::useProjectRoot($this->root);
        // Pin an empty switch: the fixture reuses the real fragment id, and a dev machine
        // with that item enabled would otherwise flip the "off by default" expectation.
        GlobalRegion::useSwitch(new GlobalRegionSwitch([]));
        FragmentRegionProvider::flushScanCache();
        try {
            self::assertSame('', GlobalRegion::render('head'), 'module fragment is off by default');
        } finally {
            GlobalRegion::reset();
            FragmentRegionProvider::flushScanCache();
        }

        GlobalRegion::useProjectRoot($this->root);
        GlobalRegion::useSwitch(new GlobalRegionSwitch(['items' => ['base.head.analytics.01-pixel' => true]]));
        FragmentRegionProvider::flushScanCache();
        try {
            self::assertStringContainsString('<meta name="pixel" content="on">', GlobalRegion::render('head'));
        } finally {
            GlobalRegion::reset();
            FragmentRegionProvider::flushScanCache();
        }
    }

    public function testDefaultRootsCoverModuleAndThemeLayouts(): void
    {
        // Build a miniature project: the real repo has no module fragment yet, so a
        // fixture tree is the only way to assert both shapes and their order.
        $this->put('app/code/Demo/Foo/view/theme/base/head/ops/01-x.phtml', '<meta name="m" content="1">');
        $this->put('app/design/Demo/theme/frontend/widget/base/head/ops/01-x.phtml', '<meta name="t" content="1">');

        $roots = GlobalRegionFragmentScanner::defaultRoots($this->root);
        $sources = \array_column($roots, 'source');

        self::assertSame(
            [GlobalRegionFragment::SOURCE_MODULE, GlobalRegionFragment::SOURCE_THEME],
            $sources,
            'modules are scanned first so the theme root overrides them',
        );
        self::assertStringEndsWith('/app/code/Demo/Foo/view/theme/base', $roots[0]['path']);
        self::assertStringEndsWith('/app/design/Demo/theme/frontend/widget/base', $roots[1]['path']);
    }
}
