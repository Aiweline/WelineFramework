<?php

declare(strict_types=1);

namespace Weline\Theme\test\Unit\Region;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Region\GlobalRegionFragmentScanner;

/**
 * 全局区加载策略：**只有事件像素是 eager（head 直接内联），其余一律异步水合**。
 *
 * 这条是产品口径，不是实现细节：任何新片段若忘了加异步（或误加了 eager），
 * 页面就会多背一份 HTML。用真实项目树断言，避免以后悄悄漂移。
 */
class GlobalRegionEagerPolicyTest extends TestCase
{
    public function testOnlyPixelFragmentIsEager(): void
    {
        $root = \defined('BP') ? \rtrim((string)\BP, '/\\') : \getcwd();
        $fragments = (new GlobalRegionFragmentScanner(GlobalRegionFragmentScanner::defaultRoots($root)))->scan()['fragments'];

        $eager = [];
        $async = [];
        foreach ($fragments as $fragment) {
            if ($fragment->eager) {
                $eager[] = $fragment->id;
            } else {
                $async[] = $fragment->id;
            }
        }

        self::assertSame(
            ['base.head.analytics.01-pixel'],
            $eager,
            '只有事件像素允许 eager 内联；新增 eager 必须显式改这条测试'
        );
        self::assertGreaterThanOrEqual(8, \count($async), '其余全局组件必须走异步水合');
    }
}
