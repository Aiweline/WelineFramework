<?php

declare(strict_types=1);

namespace Weline\Mail\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;

/**
 * 企业邮箱弹层必须使用后台可解析的 --weline-theme-* token；
 * 资源 URL 不能重复拼 ?v=。
 */
final class MailComposerThemeTokensTest extends TestCase
{
    public function testComposerCssUsesThemeTokensWithLegacyFallback(): void
    {
        $css = (string)file_get_contents(BP . 'app/code/Weline/Mail/view/statics/css/mail-composer.css');
        self::assertStringContainsString('background: var(--weline-theme-surface-raised, var(--color-bg-surface, #fff));', $css);
        self::assertStringContainsString('background: var(--weline-theme-overlay, rgba(2, 6, 23, .62));', $css);
        self::assertStringContainsString('color: var(--weline-theme-text, var(--color-text, #1d1d1f));', $css);
        self::assertStringContainsString('border: 1px solid var(--weline-theme-border, var(--color-border, #d2d2d7));', $css);
    }

    public function testComposerAssetUrlUsesValidCacheBustQuery(): void
    {
        $taglib = (string)file_get_contents(BP . 'app/code/Weline/Mail/Taglib/MailComposer.php');
        self::assertStringContainsString("str_contains(\$cssUrl, '?') ? '&' : '?'", $taglib);
        self::assertStringContainsString("str_contains(\$jsUrl, '?') ? '&' : '?'", $taglib);
        self::assertStringContainsString("'cb=20261022-1'", $taglib);
        self::assertStringNotContainsString("'?v=20261022-1'", $taglib);
    }
}
