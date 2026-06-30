<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Helper\JsTranslationsExtractor;

final class JsTranslationsExtractorTest extends TestCase
{
    public function testResolveModulePathSupportsCompiledFrontendStaticUrl(): void
    {
        $path = JsTranslationsExtractor::resolveModulePath('/WeShop/Cart/view/statics/js/cart.js');

        self::assertIsString($path);
        self::assertStringEndsWith(
            str_replace('/', DIRECTORY_SEPARATOR, 'app/code/WeShop/Cart/view/statics/js/cart.js'),
            $path
        );
    }

    public function testExtractWordsFromResolvedCartModule(): void
    {
        $path = JsTranslationsExtractor::resolveModulePath('/WeShop/Cart/view/statics/js/cart.js');
        self::assertIsString($path);

        $words = JsTranslationsExtractor::extractWordsFromJsFile($path);

        self::assertArrayHasKey('已加入购物车', $words);
        self::assertArrayHasKey('添加购物车失败', $words);
    }

    public function testExtractWordsFromDeclaredCartModule(): void
    {
        $words = JsTranslationsExtractor::extractWordsFromModules(['cart'], 'frontend');

        self::assertArrayHasKey('已加入购物车', $words);
        self::assertArrayHasKey('前端 API 模块加载失败，请刷新后重试', $words);
    }
}
