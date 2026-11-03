<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Region;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Region\GlobalRegion;
use Weline\Theme\Region\GlobalRegionProviderInterface;
use Weline\Theme\Region\GlobalRegionSourceRewriter;
use Weline\Theme\Region\GlobalRegionSwitch;

final class GlobalRegionSourceRewriterTest extends TestCase
{
    private function layout(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="{{htmlLang}}">
<head>
    <meta charset="utf-8">
    <title>{{title}}</title>
    <link rel="stylesheet" href="/static/app.css">
</head>
<body class="ca theme-layout-homepage">
    <header class="ca-header">H</header>
    <main>content</main>
    <script>var s = '</head>';</script>
</body>
</html>
HTML;
    }

    public function testInjectsAllThreeRegions(): void
    {
        $source = $this->layout();
        $out = (new GlobalRegionSourceRewriter())->rewrite($source);

        self::assertSame(1, \substr_count($out, '@weline-global:head:begin'));
        self::assertSame(1, \substr_count($out, '@weline-global:body_open:begin'));
        self::assertSame(1, \substr_count($out, '@weline-global:body_end:begin'));
    }

    public function testKeepsExistingMarkupByteIdentical(): void
    {
        $source = $this->layout();
        $out = (new GlobalRegionSourceRewriter())->rewrite($source);

        // Every original line must survive untouched, in the same order.
        $originalLines = \array_values(\array_filter(\explode("\n", $source), static fn(string $l): bool => \trim($l) !== ''));
        $strippedOut = $out;
        foreach (GlobalRegionSourceRewriter::REGIONS as $region) {
            $strippedOut = \preg_replace(
                '~<!--@weline-global:' . $region . ':begin-->.*?<!--@weline-global:' . $region . ':end-->~s',
                '',
                $strippedOut,
            ) ?? $strippedOut;
        }
        self::assertSame($source, $strippedOut, 'rewrite must be purely additive');
        self::assertNotEmpty($originalLines);
    }

    public function testHeadInjectionSitsRightBeforeRealHeadClose(): void
    {
        $out = (new GlobalRegionSourceRewriter())->rewrite($this->layout());

        $pos = \strpos($out, '@weline-global:head:begin');
        $closePos = \strpos($out, '</head>');
        self::assertIsInt($pos);
        self::assertIsInt($closePos);
        self::assertLessThan($closePos, $pos, 'head block must precede </head>');
        self::assertGreaterThan(\strpos($out, '<title>'), $pos, 'head block must follow existing head content');
    }

    public function testBodyOpenInjectionSitsRightAfterBodyTag(): void
    {
        $out = (new GlobalRegionSourceRewriter())->rewrite($this->layout());

        $bodyPos = \strpos($out, '<body class="ca theme-layout-homepage">');
        self::assertIsInt($bodyPos);
        $afterBody = $bodyPos + \strlen('<body class="ca theme-layout-homepage">');
        self::assertSame($afterBody, \strpos($out, '<!--@weline-global:body_open:begin-->'));
    }

    public function testRewriteIsIdempotent(): void
    {
        $rewriter = new GlobalRegionSourceRewriter();
        $once = $rewriter->rewrite($this->layout());
        $twice = $rewriter->rewrite($once);

        self::assertSame($once, $twice, 'recompiling must not stack duplicate blocks');
    }

    public function testMissingHeadCloseFailsCompile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/theme_layout_missing_global_anchor:.*<\/head>/');
        (new GlobalRegionSourceRewriter())->rewrite("<!DOCTYPE html><html><head><title>x</title><body></body></html>");
    }

    public function testMissingBodyFailsCompile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/theme_layout_missing_global_anchor:.*<body/');
        (new GlobalRegionSourceRewriter())->rewrite("<!DOCTYPE html><html><head></head></html>");
    }

    public function testBodyCloseInsideScriptIsNotTheAnchor(): void
    {
        $source = <<<'HTML'
<!DOCTYPE html><html><head><title>x</title></head><body>
<script>document.write('</body>');</script>
<main>real</main>
</body></html>
HTML;
        $out = (new GlobalRegionSourceRewriter())->rewrite($source);

        // The injected body_end block must land before the LAST </body>, i.e. after <main>.
        $mainPos = \strpos($out, '<main>real</main>');
        $blockPos = \strpos($out, '@weline-global:body_end:begin');
        self::assertIsInt($mainPos);
        self::assertIsInt($blockPos);
        self::assertGreaterThan($mainPos, $blockPos);
    }

    public function testSkipMarkerLeavesFragmentLayoutsUntouched(): void
    {
        $fragment = "<?php\n/**\n * Size guide fragment · @weline-global:skip\n */\n?>\n<section>guide</section>\n";

        self::assertSame($fragment, (new GlobalRegionSourceRewriter())->rewrite($fragment));
    }

    public function testFragmentWithoutSkipMarkerStillFails(): void
    {
        $this->expectException(\RuntimeException::class);
        (new GlobalRegionSourceRewriter())->rewrite("<section>guide</section>\n");
    }

    public function testBodyOpenBlockLandsAfterTheWholeBodyTag(): void
    {
        // Regression: a body tag carrying a PHP class echo used to be cut at the echo's
        // closing tag delimiter, which pushed the body_open block inside the attribute
        // list (real baked artifact showed it between the echo and the tag's closing angle).
        $source = <<<'HTML'
<!DOCTYPE html><html><head><title>x</title></head>
<body class="theme-layout-homepage <?= $class ?>" data-x="a>b">
<main>content</main>
</body></html>
HTML;
        $out = (new GlobalRegionSourceRewriter())->rewrite($source);

        $tagEnd = \strpos($out, '<body class="theme-layout-homepage <?= $class ?>" data-x="a>b">');
        self::assertIsInt($tagEnd);
        $afterTag = $tagEnd + \strlen('<body class="theme-layout-homepage <?= $class ?>" data-x="a>b">');
        self::assertSame($afterTag, \strpos($out, '<!--@weline-global:body_open:begin-->'));
    }

    public function testRegionRendersOncePerRequest(): void
    {
        GlobalRegion::reset();
        // Pin an empty switch and a project root without fragments: the defaults would
        // otherwise register the real project's theme fragments (default ON) and change
        // the expectation.
        GlobalRegion::useSwitch(new GlobalRegionSwitch([]));
        GlobalRegion::useProjectRoot('/__weline_region_test_no_project__');
        $provider = new class implements GlobalRegionProviderInterface {
            public int $calls = 0;

            public function region(): string
            {
                return 'head';
            }

            public function render(): string
            {
                ++$this->calls;

                return '<meta name="probe" content="1">';
            }
        };
        GlobalRegion::register($provider);

        self::assertSame('<meta name="probe" content="1">', GlobalRegion::render('head'));
        self::assertSame('', GlobalRegion::render('head'), 'second call in the same request must be empty');
        self::assertSame('', GlobalRegion::render('body_end'), 'other regions are unaffected');
        self::assertSame(1, $provider->calls);

        GlobalRegion::reset();
    }

    public function testProviderFailureDoesNotBreakThePage(): void
    {
        GlobalRegion::reset();
        GlobalRegion::useSwitch(new GlobalRegionSwitch([]));
        GlobalRegion::useProjectRoot('/__weline_region_test_no_project__');
        GlobalRegion::register(new class implements GlobalRegionProviderInterface {
            public function region(): string
            {
                return 'head';
            }

            public function render(): string
            {
                throw new \RuntimeException('boom');
            }
        });

        self::assertSame('', GlobalRegion::render('head'));

        GlobalRegion::reset();
    }
}
