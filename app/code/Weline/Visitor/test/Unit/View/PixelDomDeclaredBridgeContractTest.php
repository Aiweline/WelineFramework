<?php

declare(strict_types=1);

namespace Weline\Visitor\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Frontend-declared element events (weline-pixel:: / data-pixel-event / custom_match)
 * must resolve a third-party event name and bridge — never CTA-hijack or unmapped-skip.
 */
final class PixelDomDeclaredBridgeContractTest extends TestCase
{
    public function testDictionaryResolveAutoBridgesDomAndCustomEvents(): void
    {
        $root = \dirname(__DIR__, 3);
        $pixel = (string) \file_get_contents($root . '/view/statics/js/pixel.js');
        $phtml = (string) \file_get_contents($root . '/view/taglib/js/pixel.phtml');
        $bootstrap = (string) \file_get_contents($root . '/Service/PixelBootstrapHtmlService.php');

        foreach ([$pixel, $phtml] as $src) {
            self::assertStringContainsString("mapping_source: customMatch", $src);
            self::assertStringContainsString("'track_name'", $src);
            self::assertStringContainsString('把监视来源扁平进 payload', $src);
            self::assertStringContainsString('__findPixelEventNameFromElement(element)', $src);
            self::assertStringContainsString("payload.source === 'behavior_monitor'", $src);
            self::assertStringContainsString('already_tracked', $src);
            self::assertStringContainsString('自定义条件命中：与元素声明并行', $src);
            self::assertStringNotContainsString('先按自定义事件 match_conditions 命中（创建后 runtime 热更即可立刻识别）', $src);
            // CTA heuristic must not swallow declared business names
            self::assertStringContainsString("if (!normalized && __isGa4CtaElement(element))", $src);
        }

        self::assertStringContainsString("PIXEL_SCRIPT_VERSION = '2026.09.23-r2d-param2'", $pixel);
        self::assertStringContainsString("PIXEL_SCRIPT_VERSION = '20260923-r2d-param2'", $bootstrap);
    }
}
