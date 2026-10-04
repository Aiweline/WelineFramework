<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 竖排诗侧栏（.weline-detail-prose--verse-vertical）只适用于桌面 CJK 内容。
 * - 非 CJK：语言守卫回落 horizontal-tb
 * - 手机（≤640px）：一律横排轻量导语，避免竖排诗笺占满首屏
 */
final class PdpVerseVerticalLanguageContractTest extends TestCase
{
    private const CSS = '/view/statics/css/widgets/product-native-detail.css';

    private function css(): string
    {
        $path = dirname(__DIR__, 3) . self::CSS;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testDesktopCjkKeepsVerticalWriting(): void
    {
        $source = $this->css();

        self::assertStringContainsString('writing-mode: vertical-rl;', $source);
        self::assertStringContainsString(
            '.weline-detail-prose--verse-vertical:not([lang^="zh" i], [lang^="ja" i], [lang^="ko" i])',
            $source,
        );
        self::assertStringContainsString(':not(:lang(zh), :lang(ja), :lang(ko))', $source);
    }

    public function testNonCjkFallsBackToHorizontalAndWidensPoemAside(): void
    {
        $source = $this->css();

        self::assertMatchesRegularExpression(
            '/\.weline-detail-prose--verse-vertical'
            . ':not\(\[lang\^="zh" i\],\s*\[lang\^="ja" i\],\s*\[lang\^="ko" i\]\)'
            . ':not\(:lang\(zh\),\s*:lang\(ja\),\s*:lang\(ko\)\)'
            . '[^{]*\{[^}]*writing-mode:\s*horizontal-tb/s',
            $source,
        );
        self::assertMatchesRegularExpression(
            '/\.weline-detail-prose--verse-vertical'
            . ':not\(\[lang\^="zh" i\],\s*\[lang\^="ja" i\],\s*\[lang\^="ko" i\]\)'
            . '[^{]*\{[^}]*max-height:\s*none/s',
            $source,
        );
        // 等宽两栏。压缩器吞空白的缺陷已于 2026-10-02 修复，1fr 1fr 仅保留为已验证版式。
        self::assertStringContainsString(
            '.weline-detail-feature--poem-aside:has(.weline-detail-prose--verse-vertical:not([lang^="zh" i], [lang^="ja" i], [lang^="ko" i])',
            $source,
        );
        self::assertMatchesRegularExpression(
            '/weline-detail-feature--poem-aside:has\([\s\S]*?grid-template-columns:\s*1fr\s+1fr/',
            $source,
        );
    }

    public function testMobileDropsPoeticPackagingToHorizontalLightweightLead(): void
    {
        $source = $this->css();

        // 锚定「手机：诗意包装降级」注释块，避免匹配到其它 max-width:640 媒体查询
        self::assertMatchesRegularExpression(
            '/手机：诗意包装降级[\s\S]*?@media\s*\(max-width:\s*640px\)\s*\{[\s\S]*?'
            . '\.weline-detail-prose--verse-vertical\s*\{[\s\S]*?writing-mode:\s*horizontal-tb/',
            $source,
        );
        self::assertMatchesRegularExpression(
            '/手机：诗意包装降级[\s\S]*?@media\s*\(max-width:\s*640px\)\s*\{[\s\S]*?'
            . '\.weline-detail-feature--poem-aside\s+\.weline-detail-feature__copy\s*\{[\s\S]*?'
            . 'border:\s*none/',
            $source,
        );
        self::assertMatchesRegularExpression(
            '/手机：诗意包装降级[\s\S]*?@media\s*\(max-width:\s*640px\)\s*\{[\s\S]*?'
            . '\.weline-detail-feature--poem-aside\s+\.weline-detail-feature__copy\s*\{[\s\S]*?'
            . 'background:\s*var\(--color-bg-secondary/',
            $source,
        );
    }
}
