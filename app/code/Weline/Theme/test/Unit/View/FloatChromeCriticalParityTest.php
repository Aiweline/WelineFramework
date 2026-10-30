<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\SharedChromeService;

/**
 * 悬浮层边缘 chrome（关闭「×」/ 收起按钮）的**单一出处**守卫。
 *
 * 背景（2026-10-30 实证）：
 *  - 权威外观在 `Theme/view/statics/css/storefront-float-layer.css`（由资产发布器包进 `@layer components`）；
 *  - 首帧 critical CSS 曾同时被 **8 个手写作者** 复制（框架页脚、5 个 design 主题页脚、host 2 处 heal 副本），
 *    导致 1.25rem/20px 与 1.5rem/24px 漂移、浅底对比度 1.0~1.41:1（低于 WCAG 1.4.11 的 3:1），
 *    用户侧表现为「悬浮层关闭叉叉没显示」；
 *  - 现统一由 `SharedChromeService::emitFloatLayerCriticalCss()` 输出（未分层：分层会被页面未分层的
 *    `button{min-block-size:2.5rem}` 反压，chip 会变 24×40）。
 *
 * 本测试锁四件事：
 *  1) emitter 输出内含完整 chip 契约（24px 钉住 / 60% 描边 / 78% hover / 焦点环 token / recall 镜像 / 空壳隐藏）；
 *  2) 权威 sheet 的 chip 契约与 emitter 同值，且交互态带 ID 前缀；
 *  3) 所有页脚（框架默认 + 5 个 design 主题）只允许「调用 emitter」，不得再出现活的
 *     `<style data-storefront-float-layer-critical>` 内联正文（legacy 必须包在 `<template>` 内不生效）；
 *  4) host 两处 heal 副本同样改为调用 emitter，legacy 置 `<template>` 内不生效；design 主题不得强藏 dismiss。
 *
 * Marker: storefront-float-critical-single-source-v1
 */
final class FloatChromeCriticalParityTest extends TestCase
{
    private const DISMISS = 'w-storefront-float-edge__dismiss';

    private function themeRoot(): string
    {
        // app/code/Weline/Theme/test/Unit/View → app/code/Weline/Theme
        return \dirname(__DIR__, 3);
    }

    private function repoRoot(): string
    {
        // app/code/Weline/Theme/test/Unit/View → 仓库根
        return \dirname(__DIR__, 7);
    }

    private function emitterOutput(): string
    {
        return SharedChromeService::emitFloatLayerCriticalCss();
    }

    /** 页脚清单：框架默认 + 本机存在的 design 主题（缺失则跳过该文件）。 */
    private function footerPartials(): array
    {
        $framework = $this->themeRoot() . '/view/theme/frontend/partials/footer/default.phtml';
        $designs = [
            'playinginchina' => $this->repoRoot() . '/app/design/Weline/playinginchina/frontend/partials/footer/default.phtml',
            'hanfu' => $this->repoRoot() . '/app/design/Weline/hanfu/frontend/partials/footer/default.phtml',
            'grocery' => $this->repoRoot() . '/app/design/Weline/grocery/frontend/partials/footer/default.phtml',
            'daocharms' => $this->repoRoot() . '/app/design/Weline/daocharms/frontend/partials/footer/default.phtml',
            'amayum' => $this->repoRoot() . '/app/design/Amayum/official/frontend/partials/footer/default.phtml',
        ];
        $out = ['framework' => $framework];
        foreach ($designs as $name => $path) {
            if (\is_file($path)) {
                $out[$name] = $path;
            }
        }

        return $out;
    }

    private function sheetCss(): string
    {
        $path = $this->themeRoot() . '/view/statics/css/storefront-float-layer.css';
        self::assertFileExists($path, '权威 sheet 缺失：' . $path);
        // 先剥 CSS 注释：注释里的 `}` 会截断惰性匹配
        return (string)\preg_replace('#/\*.*?\*/#s', '', (string)\file_get_contents($path));
    }

    public function testEmitterCarriesFullChipContract(): void
    {
        $css = $this->emitterOutput();
        self::assertStringContainsString('<style data-storefront-float-layer-critical>', $css);
        self::assertSame(1, \substr_count($css, '</style>'), 'emitter 必须只输出一个 style 块');
        // 尺寸钉住（24px = 1.5rem）
        foreach (['width:1.5rem', 'height:1.5rem', 'min-inline-size:1.5rem', 'min-block-size:1.5rem', 'max-inline-size:1.5rem', 'max-block-size:1.5rem'] as $needle) {
            self::assertStringContainsString($needle, $css, 'emitter 缺尺寸钉住：' . $needle);
        }
        // chip 自身不得回退到 20px 旧值（结构规则里的 --weline-space-5:1.25rem 兜底不算）
        self::assertStringNotContainsString('width:1.25rem', $css, 'chip 宽度不得回退 20px');
        self::assertStringNotContainsString('height:1.25rem', $css, 'chip 高度不得回退 20px');
        self::assertStringNotContainsString('max-block-size:1.25rem', $css, 'chip max-block-size 不得回退 20px');
        // 控件边界对比度 ≥3:1（60% 文字色混色）
        self::assertStringContainsString('border:1px solid color-mix(in srgb,var(--weline-theme-text,#111) 60%,transparent)', $css);
        // 交互态镜像（未分层 critical 内必须自带，sheet 的 hover 在 layer 内赢不过它）
        self::assertStringContainsString(':is(:hover,:focus-visible){opacity:1', $css, 'emitter 缺 dismiss hover 镜像');
        self::assertStringContainsString('border-color:color-mix(in srgb,var(--weline-theme-text,#111) 78%,transparent)', $css);
        self::assertStringContainsString('var(--weline-theme-focus-ring-width,2px)', $css, '焦点环必须消费令牌而非写死 2px');
        self::assertStringContainsString('.w-storefront-float-edge__recall:is(:hover,:focus-visible)', $css, 'emitter 缺 recall hover 镜像');
        // 空壳隐藏上收（原写在各 design 主题首页布局里）
        self::assertStringContainsString('__body:empty){display:none}', $css, 'emitter 必须承载空壳槽隐藏规则');
        // 让位规则必须也在 emitter（未分层）内：sheet 里这些规则在 @layer components 内，会被未分层的
        // 基线 bottom 规则压掉——实测曾导致同意条文字被悬浮件遮挡 41%。
        self::assertStringContainsString('storefront-float-critical-consent-clearance-v1', $css, 'emitter 缺让位规则 marker');
        self::assertStringContainsString('--weline-consent-banner-clearance', $css, 'emitter 缺同意条让位（否则 390px 同意条被遮挡）');
        self::assertStringContainsString('weline-social-quick-bar', $css, 'emitter 缺社交条叠放让位');
        self::assertStringContainsString('var(--weline-consent-banner-clearance,5.25rem)', $css, 'emitter 缺移动端让位档（5.25rem）');
        // 单一出处标记在 SharedChromeService 源内（docblock），不在输出 HTML 里
        $serviceSrc = (string)\file_get_contents($this->themeRoot() . '/Service/SharedChromeService.php');
        self::assertStringContainsString('storefront-float-critical-single-source-v1', $serviceSrc);
        self::assertStringContainsString('public static function emitFloatLayerCriticalCss', $serviceSrc);
    }

    public function testSheetDismissChipContract(): void
    {
        $css = $this->sheetCss();
        self::assertMatchesRegularExpression(
            '/\.' . self::DISMISS . '\s*\{[^}]*width:\s*1\.5rem[^}]*\}/s',
            $css,
            'sheet 的 chip 必须 1.5rem/24px'
        );
        self::assertMatchesRegularExpression(
            '/\.' . self::DISMISS . '\s*\{[^}]*border:[^;{}]*color-mix\(\s*in srgb\s*,\s*var\(--weline-theme-text[^)]*\)\s*60%/s',
            $css,
            'sheet 的 chip 描边必须与 emitter 同值（60% 混色，≥3:1）'
        );
        self::assertMatchesRegularExpression(
            '/\.' . self::DISMISS . '\s*\{[^}]*background:\s*var\(--weline-theme-surface-raised/s',
            $css,
            'sheet 的 chip 底色必须不透明 surface-raised'
        );
    }

    public function testSheetInteractionStatesCarryIdPrefix(): void
    {
        $css = $this->sheetCss();
        self::assertMatchesRegularExpression(
            '/#w-storefront-float-layer\s+\.' . self::DISMISS . ':is\(:hover,\s*:focus-visible\)/',
            $css,
            'sheet 的 hover/focus-visible 规则必须带 ID 前缀'
        );
        self::assertMatchesRegularExpression(
            '/#w-storefront-float-layer\s+\.' . self::DISMISS . ':focus-visible/',
            $css,
            'sheet 的 focus-visible 焦点环规则必须带 ID 前缀'
        );
    }

    public function testEveryFooterUsesSingleSourceEmitter(): void
    {
        foreach ($this->footerPartials() as $name => $path) {
            $src = (string)\file_get_contents($path);
            self::assertStringContainsString(
                'SharedChromeService::emitFloatLayerCriticalCss',
                $src,
                $name . ' 页脚未调用单一出处 emitter：' . $path
            );
            self::assertSame(
                0,
                \substr_count($src, '<style data-storefront-float-layer-critical'),
                $name . ' 页脚仍有活的内联 critical 正文（必须改为调用 emitter）'
            );
            self::assertSame(
                0,
                \substr_count($src, '<template data-legacy-float-critical>'),
                $name . ' 页脚仍残留 legacy critical 容器（应已删除，避免契约测试文本扫描误判）'
            );
            self::assertSame(
                0,
                \substr_count($src, '#w-storefront-float-layer .w-storefront-float-edge__dismiss{'),
                $name . ' 页脚仍手写 dismiss 规则正文（必须由 emitter 单一出处输出）'
            );
        }
    }

    public function testHostHealCopiesUseSingleSourceOnly(): void
    {
        $path = $this->themeRoot() . '/Service/LayoutEntity/StorefrontFloatLayerHost.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);
        self::assertGreaterThanOrEqual(
            2,
            \substr_count($src, 'emitFloatLayerCriticalCss'),
            'host 两处 heal 副本必须调用单一出处 emitter'
        );
        self::assertSame(
            0,
            \substr_count($src, '<style data-storefront-float-layer-critical data-required-injection-host'),
            'host 不得再输出活的内联 critical style 块'
        );
        self::assertSame(
            0,
            \substr_count($src, '<template data-legacy-float-critical>'),
            'host 的 legacy critical 已删除（不得残留 inert 容器）'
        );
        self::assertSame(
            0,
            \substr_count($src, '1.25rem;height:1.25rem'),
            'host heal 副本不得回退 20px 旧值'
        );
    }

    public function testDesignThemeDoesNotForceHideDismiss(): void
    {
        $themeDir = $this->repoRoot() . '/app/design/Weline/playinginchina/frontend';
        if (!\is_dir($themeDir)) {
            self::markTestSkipped('PIC design 主题不在本机磁盘');
        }
        $cssPath = $themeDir . '/assets/css/playinginchina.css';
        if (\is_file($cssPath)) {
            $css = (string)\file_get_contents($cssPath);
            self::assertDoesNotMatchRegularExpression(
                '/body\.pic-product[^{}]*\.' . self::DISMISS . '[^{}]*\{[^}]*display\s*:\s*none\s*!important/',
                $css,
                '主题不得在 PDP 用 display:none!important 强藏关闭叉叉（控件显隐属框架契约）'
            );
        }
        $jsPath = $themeDir . '/assets/js/playinginchina.js';
        if (\is_file($jsPath)) {
            $js = (string)\file_get_contents($jsPath);
            self::assertDoesNotMatchRegularExpression(
                '/hideFloatEdgeDismissOnPdp\s*\(\s*\)\s*;/',
                $js,
                '主题 JS 不得再调用 hideFloatEdgeDismissOnPdp()（强藏关闭叉叉）'
            );
        }
    }
}
