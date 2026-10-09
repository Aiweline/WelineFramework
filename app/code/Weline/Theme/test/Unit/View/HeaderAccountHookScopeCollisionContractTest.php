<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * header/account bakes header-account-links into the same PHP include scope.
 * Host helpers must not use a short name like $label that contributors commonly assign.
 */
final class HeaderAccountHookScopeCollisionContractTest extends TestCase
{
    public function testAccountWidgetUsesHostLocalI18nHelperNotShortLabel(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/widgets/header/account/default.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('$accountI18n = static fn', $source);
        self::assertStringContainsString("\$accountI18n('登录')", $source);
        self::assertStringContainsString("\$accountI18n('退出登录')", $source);
        self::assertStringContainsString('header-account-links', $source);
        self::assertDoesNotMatchRegularExpression(
            '/\$label\s*=\s*static\s+fn/',
            $source,
            'Do not bind host i18n helper as $label; baked hooks share this scope'
        );
        self::assertDoesNotMatchRegularExpression(
            '/\$esc\(\$label\(/',
            $source,
            'Post-hook host chrome must not call $label(...)'
        );
    }

    public function testProductQuoteHeaderLinkAvoidsLabelVariable(): void
    {
        $path = dirname(__DIR__, 4) . '/Product/view/hooks/header-account-links.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('$quoteLabel = WidgetI18n::label', $source);
        self::assertStringContainsString('htmlspecialchars($quoteLabel', $source);
        self::assertDoesNotMatchRegularExpression(
            '/\$label\s*=\s*WidgetI18n::label/',
            $source,
            'Product header-account-links must not overwrite host $label'
        );
    }

    public function testBakedHookLabelOverwriteWouldCallChineseAsFunction(): void
    {
        $label = static fn (string $source): string => 'ok:' . $source;
        self::assertSame('ok:登录', $label('登录'));

        // Simulate Product hook assignment into the shared include scope.
        $label = '我的询价';
        $caught = null;
        try {
            /** @noinspection PhpUndefinedFunctionInspection */
            $label('退出登录');
        } catch (\Throwable $e) {
            $caught = $e;
        }

        self::assertInstanceOf(\Error::class, $caught);
        self::assertStringContainsString('我的询价', $caught->getMessage());
        self::assertStringContainsString('undefined function', strtolower($caught->getMessage()));
    }
}
