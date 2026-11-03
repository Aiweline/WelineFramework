<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Region;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Region\GlobalRegionFragment;
use Weline\Theme\Region\GlobalRegionFragmentScanner;

final class GlobalRegionFragmentScannerTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/weline-global-frag-' . \bin2hex(\random_bytes(6));
        \mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    private function put(string $relative, string $contents = '<?= 1 ?>'): void
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

    public function testDerivesEverythingFromThePath(): void
    {
        $this->put('theme/head/analytics/01-pixel.phtml');
        $this->put('module/body_end/ops/store-music.phtml');

        $result = (new GlobalRegionFragmentScanner([
            ['source' => GlobalRegionFragment::SOURCE_MODULE, 'path' => $this->root . '/module'],
            ['source' => GlobalRegionFragment::SOURCE_THEME, 'path' => $this->root . '/theme'],
        ]))->scan();

        self::assertSame([], $result['problems']);
        self::assertCount(2, $result['fragments']);

        [$first, $second] = $result['fragments'];
        // head sorts before body_end, so the pixel fragment comes first.
        self::assertSame('base.head.analytics.01-pixel', $first->id);
        self::assertSame('head', $first->region);
        self::assertSame('analytics', $first->group);
        self::assertSame(1, $first->order);
        self::assertSame('pixel', $first->name);
        self::assertSame(GlobalRegionFragment::SOURCE_THEME, $first->source);
        self::assertTrue($first->defaultEnabled, 'theme fragments are on by default');

        self::assertSame('base.body_end.ops.store-music', $second->id);
        self::assertSame(\PHP_INT_MAX, $second->order, 'no numeric prefix sorts last');
        self::assertFalse($second->defaultEnabled, 'module fragments are off until a site opts in');
    }

    public function testThemeRootOverridesModuleFragmentWithSameId(): void
    {
        $this->put('module/head/analytics/01-pixel.phtml', 'module bytes');
        $this->put('theme/head/analytics/01-pixel.phtml', 'theme bytes');

        $result = (new GlobalRegionFragmentScanner([
            ['source' => GlobalRegionFragment::SOURCE_MODULE, 'path' => $this->root . '/module'],
            ['source' => GlobalRegionFragment::SOURCE_THEME, 'path' => $this->root . '/theme'],
        ]))->scan();

        self::assertCount(1, $result['fragments'], 'same id must not be emitted twice');
        $fragment = $result['fragments'][0];
        self::assertSame(GlobalRegionFragment::SOURCE_THEME, $fragment->source);
        self::assertSame('theme bytes', \file_get_contents($fragment->path));
    }

    public function testOrderPrefixThenNameDecidesTheSequence(): void
    {
        $this->put('theme/head/ops/10-late.phtml');
        $this->put('theme/head/ops/02-early.phtml');
        $this->put('theme/head/ops/no-prefix.phtml');

        $ids = \array_map(
            static fn(GlobalRegionFragment $f): string => $f->name,
            (new GlobalRegionFragmentScanner([
                ['source' => GlobalRegionFragment::SOURCE_THEME, 'path' => $this->root . '/theme'],
            ]))->scan()['fragments'],
        );

        self::assertSame(['early', 'late', 'no-prefix'], $ids);
    }

    public function testReportsMalformedEntriesInsteadOfSwallowingThem(): void
    {
        $this->put('theme/head/ops/01-ok.phtml');
        $this->put('theme/head/Bad_Group/01-x.phtml');
        $this->put('theme/head/ops/Bad_Name.phtml');
        $this->put('theme/head/ops/notes.txt');
        $this->put('theme/head/stray.phtml');

        $result = (new GlobalRegionFragmentScanner([
            ['source' => GlobalRegionFragment::SOURCE_THEME, 'path' => $this->root . '/theme'],
        ]))->scan();

        self::assertCount(1, $result['fragments'], 'only the valid fragment is usable');

        $joined = \implode("\n", $result['problems']);
        self::assertStringContainsString('fragment_group_invalid:', $joined);
        self::assertStringContainsString('fragment_name_invalid:', $joined);
        self::assertStringContainsString('fragment_extension_invalid:', $joined);
        self::assertStringContainsString('fragment_group_expected:', $joined);
    }

    public function testUnknownRegionDirectoryIsIgnored(): void
    {
        $this->put('theme/head/ops/01-ok.phtml');
        $this->put('theme/sidebar/ops/02-nope.phtml');

        $result = (new GlobalRegionFragmentScanner([
            ['source' => GlobalRegionFragment::SOURCE_THEME, 'path' => $this->root . '/theme'],
        ]))->scan();

        self::assertCount(1, $result['fragments']);
        self::assertSame([], $result['problems'], 'only the three contract regions are scanned');
    }

    public function testMissingRootIsSkipped(): void
    {
        $result = (new GlobalRegionFragmentScanner([
            ['source' => GlobalRegionFragment::SOURCE_THEME, 'path' => $this->root . '/nope'],
        ]))->scan();

        self::assertSame(['fragments' => [], 'problems' => []], $result);
    }
}
