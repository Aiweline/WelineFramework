<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\View;

use Weline\Framework\Test\TestCore;
use Weline\Visitor\Service\EventDictionaryService;

/**
 * page_exit 仅真实卸载（非 BFCache）；visibility_hidden / pagehide_persisted 走 page_hide。
 */
class PixelPageExitSemanticsContractTest extends TestCore
{
    /** @return list<string> */
    private function sources(): array
    {
        $root = dirname(__DIR__, 3);
        return [
            (string) \file_get_contents($root . '/view/statics/js/pixel.js'),
            (string) \file_get_contents($root . '/view/taglib/js/pixel.phtml'),
        ];
    }

    public function testVisibilityHiddenTracksPageHideNotPageExit(): void
    {
        foreach ($this->sources() as $src) {
            self::assertStringContainsString("track('page_hide'", $src);
            self::assertStringContainsString("trackPageHide('visibility_hidden')", $src);
            self::assertStringNotContainsString("trackPageExit('visibility_hidden')", $src);
            self::assertStringContainsString("trackPageExit('pagehide'", $src);
            self::assertStringContainsString("trackPageHide('pagehide_persisted'", $src);
            self::assertStringContainsString('__pixelPageWasVisible', $src);
            self::assertStringContainsString("addEventListener('pageshow'", $src);
            self::assertStringNotContainsString('__pixelPageShown', $src);
            self::assertStringContainsString('__pixelPageHideCycleSent = false', $src);
            self::assertStringContainsString("PIXEL_SCRIPT_VERSION = '2026.09.19-sticky-bus1'", $src);
        }
    }

    public function testDictionaryDefinesPageHideAndPageExit(): void
    {
        $dict = new EventDictionaryService();
        $hide = $dict->resolve('page_hide');
        $exit = $dict->resolve('page_exit');
        self::assertNotNull($hide);
        self::assertNotNull($exit);
        self::assertSame('页面隐藏', $hide['label_zh'] ?? null);
        self::assertSame('页面退出', $exit['label_zh'] ?? null);
        self::assertTrue(!empty($hide['skip_gtm_push']));
        self::assertTrue(!empty($exit['skip_gtm_push']));
        self::assertSame('1.3.3', $dict->getVersion());
    }

    public function testBootstrapVersionBumped(): void
    {
        $bootstrap = (string) \file_get_contents(
            dirname(__DIR__, 3) . '/Service/PixelBootstrapHtmlService.php'
        );
        self::assertStringContainsString("PIXEL_SCRIPT_VERSION = '20260919-sticky-bus1'", $bootstrap);
    }
}
