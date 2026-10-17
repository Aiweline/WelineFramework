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
        ];
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
