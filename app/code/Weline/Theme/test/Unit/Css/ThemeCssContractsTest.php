<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Css;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllThemeCssContractsPass().
 */
final class ThemeCssContractsTest extends TestCase
{
    public function testAllThemeCssContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'HomepageHeroFrameFill::testDefaultHomepageShellDoesNotClampWFrameTo40vh20rem' => function () {
            $this->assert_HomepageHeroFrameFill_testDefaultHomepageShellDoesNotClampWFrameTo40vh20rem();
        },
            'HomepageHeroFrameFill::testHanfuHomepageLayoutFillsWFrameInsideSlideMedia' => function () {
            $this->assert_HomepageHeroFrameFill_testHanfuHomepageLayoutFillsWFrameInsideSlideMedia();
        },
            'HomepageHeroFrameFill::testHeroSliderNormalizesBakedWFrameRatio' => function () {
            $this->assert_HomepageHeroFrameFill_testHeroSliderNormalizesBakedWFrameRatio();
        },
            'LayoutStabilityPrimitives::testFoundationDefinesFrameSkeletonLines' => function () {
            $this->assert_LayoutStabilityPrimitives_testFoundationDefinesFrameSkeletonLines();
        },
            'LayoutStabilityPrimitives::testSpacingVariablesRegisterSkeletonToken' => function () {
            $this->assert_LayoutStabilityPrimitives_testSpacingVariablesRegisterSkeletonToken();
        },
            'LayoutStabilityPrimitives::testProductCardConsumesThemePrimitives' => function () {
            $this->assert_LayoutStabilityPrimitives_testProductCardConsumesThemePrimitives();
        },
            'WidgetThemeTokenHardcode::testWidgetVisualSourcesHaveZeroTokenHardcodes' => function () {
            $this->assert_WidgetThemeTokenHardcode_testWidgetVisualSourcesHaveZeroTokenHardcodes();
        },
            'ThemeCascadeLayer::testThemeCssIsFullyWrappedInLowestPriorityLayer' => function () {
            $this->assert_ThemeCascadeLayer_testThemeCssIsFullyWrappedInLowestPriorityLayer();
        },
        ];
    }

    /**
     * 「另外引入 CSS 即继承」架构契约（HARD）：theme.css 必须整体收敛在具名层
     * `@layer weline-theme`，且该层在 foundation.css 的最早顺序声明中排在**第一位**
     * （CSS 层叠中后声明层优先级更高 ⇒ 第一位 = 最低优先级）。任一条不成立，
     * 默认骨架就会无条件压过自定义主题，`!important` 之外无解。
     */
    private function assert_ThemeCascadeLayer_testThemeCssIsFullyWrappedInLowestPriorityLayer(): void
    {
        $themeCss = BP . 'app/code/Weline/Theme/view/theme/frontend/assets/css/theme.css';
        $foundation = BP . 'app/code/Weline/Theme/view/ui/css/foundation.css';
        foreach ([$themeCss, $foundation] as $file) {
            self::assertFileExists($file);
        }

        $theme = (string)file_get_contents($themeCss);
        $openers = preg_match_all('/@layer\s+weline-theme\s*\{/', $theme);
        self::assertSame(1, $openers, 'theme.css 必须恰好有 1 个 @layer weline-theme { 块开启者，实际 ' . $openers);

        // 层包裹必须从头到尾覆盖：第一个块开启者之前不得有任何规则文本（注释除外）
        $pos = (int)strpos($theme, '@layer weline-theme {');
        $head = substr($theme, 0, $pos);
        $stripped = preg_replace('!/\*.*?\*/!s', '', $head);
        $stripped = preg_replace('!//[^\n]*!', '', (string)$stripped);
        self::assertSame('', trim((string)$stripped), '@layer weline-theme { 之前不得有规则（未分层规则会无条件压过所有层）');

        // 花括号配平（去掉注释后统计）
        $noComments = preg_replace('!/\*.*?\*/!s', '', $theme);
        self::assertSame(
            substr_count((string)$noComments, '{'),
            substr_count((string)$noComments, '}'),
            'theme.css 去掉注释后花括号必须配平（层块未闭合会让后续规则逃逸出层）'
        );

        // 层序声明：weline-theme 必须在最早声明的序列里，且排在第一位（最低优先级）
        $fs = (string)file_get_contents($foundation);
        $seqs = [];
        preg_match_all('/@layer\s+([a-z0-9_,\s-]+);/i', $fs, $m);
        foreach ($m[1] as $seq) {
            $seqs[] = array_map('trim', explode(',', $seq));
        }
        self::assertNotEmpty($seqs, 'foundation.css 必须存在 @layer 顺序声明');
        $firstSeq = $seqs[0];
        self::assertSame('weline-theme', $firstSeq[0] ?? null, 'foundation.css 最早层序中 weline-theme 必须排第一位（= 最低优先级），实际 ' . implode(',', $firstSeq));
        self::assertContains('page', $firstSeq, '层序必须仍包含框架层 page，否则骨架分层失效');
    }

private function assert_HomepageHeroFrameFill_testDefaultHomepageShellDoesNotClampWFrameTo40vh20rem(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/view/theme/frontend/assets/css/layout-homepage-default.css';
    self::assertFileExists($path);
    $css = (string) file_get_contents($path);
    self::assertStringNotContainsString('max-height: min(40vh, 20rem)', $css);
    self::assertStringContainsString('max-height: min(60vh, 37.5rem)', $css);
    self::assertMatchesRegularExpression('/\.slide-media\s+\.w-frame\s*\{[^}]*max-height:\s*none/s', $css);
}

private function assert_HomepageHeroFrameFill_testHanfuHomepageLayoutFillsWFrameInsideSlideMedia(): void
{
    // Theme/test/Unit/Css → app/ = 6 levels up → app/design/...
    $path = '/Users/weline/Project/Official/框架/app' . '/design/Weline/hanfu/frontend/assets/css/hanfu-homepage-layout.css';
    self::assertFileExists($path);
    $css = (string) file_get_contents($path);
    self::assertStringContainsString('.slide-media .w-frame', $css);
    self::assertMatchesRegularExpression('/\.slide-media\s+\.w-frame\s*\{[^}]*aspect-ratio:\s*auto/s', $css);
    self::assertMatchesRegularExpression('/\.slide-media\s+\.w-frame\s*\{[^}]*max-height:\s*none\s*!important/s', $css);
    self::assertMatchesRegularExpression('/\.slide-media\s+\.w-frame\s*\{[^}]*height:\s*100%/s', $css);
}

private function assert_HomepageHeroFrameFill_testHeroSliderNormalizesBakedWFrameRatio(): void
{
    $paths = ['/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/view/theme/frontend/widgets/banner/hero-slider/default.phtml', '/Users/weline/Project/Official/框架/app' . '/design/Weline/hanfu/frontend/widgets/banner/hero-slider/default.phtml'];
    foreach ($paths as $path) {
        self::assertFileExists($path, $path);
        $source = (string) file_get_contents($path);
        self::assertStringContainsString('$frameRatioAttr', $source, $path);
        self::assertStringContainsString('$frameRatioCss', $source, $path);
        self::assertStringContainsString('--weline-frame-ratio:', $source, $path);
        self::assertStringContainsString("str_contains(\$html, 'w-frame')", $source, $path);
        self::assertStringNotContainsString('data-ratio="16/9"', $source, $path);
    }
}

private function assert_LayoutStabilityPrimitives_testFoundationDefinesFrameSkeletonLines(): void
{
    $themeRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Theme';
    $src = $themeRoot . '/view/ui/css/foundation.css';
    $static = $themeRoot . '/view/statics/ui/weline-foundation.css';
    self::assertFileExists($src);
    self::assertFileExists($static);
    foreach ([$src, $static] as $path) {
        $css = (string) file_get_contents($path);
        self::assertStringContainsString('.w-frame', $css);
        self::assertStringContainsString('[data-ratio="3/4"]', $css);
        self::assertStringContainsString('[data-ratio="16/5"]', $css);
        self::assertStringContainsString('[data-ratio="16/9"]', $css);
        self::assertStringContainsString('[data-fit="contain"]', $css);
        self::assertStringContainsString('.w-skeleton[data-size="card"]', $css);
        self::assertStringContainsString('--weline-skeleton-card-min-h', $css);
        self::assertStringContainsString('.w-lines[data-lines="2"]', $css);
        self::assertStringNotContainsString('#ede9e1', $css);
    }
}

private function assert_LayoutStabilityPrimitives_testSpacingVariablesRegisterSkeletonToken(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/view/theme/frontend/variables/_spacing.css';
    self::assertFileExists($path);
    $css = (string) file_get_contents($path);
    self::assertStringContainsString('--weline-skeleton-card-min-h:', $css);
    self::assertStringContainsString('--weline-lines-lh:', $css);
}

private function assert_LayoutStabilityPrimitives_testProductCardConsumesThemePrimitives(): void
{
    $card = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Product/view/templates/frontend/partials/product-card.phtml';
    self::assertFileExists($card);
    $html = (string) file_get_contents($card);
    self::assertStringContainsString('w-frame', $html);
    self::assertStringContainsString('data-ratio="1"', $html);
    self::assertStringContainsString('w-lines', $html);
    self::assertStringContainsString('data-lines="2"', $html);
}

private function assert_WidgetThemeTokenHardcode_testWidgetVisualSourcesHaveZeroTokenHardcodes(): void
{
    $script = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/bin/widget-theme-token-audit.py';
    self::assertFileExists($script);
    $cmd = 'python3 ' . escapeshellarg($script) . ' --json';
    $json = shell_exec($cmd);
    self::assertIsString($json, 'widget-theme-token-audit.py must produce JSON');
    $report = json_decode($json, true);
    self::assertIsArray($report);
    self::assertTrue((bool) ($report['ok'] ?? false), 'widget theme token audit failed: bad_files=' . (string) ($report['bad_files'] ?? '?') . ' by_code=' . json_encode($report['by_code'] ?? [], JSON_UNESCAPED_UNICODE) . ' first=' . json_encode($report['files'][0] ?? null, JSON_UNESCAPED_UNICODE));
    self::assertSame(0, (int) ($report['bad_files'] ?? -1));
}
}
