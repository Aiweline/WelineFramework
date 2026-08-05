<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Helper\JsTranslationsExtractor;

final class JsTranslationsExtractorTest extends TestCase
{
    public function testResolveModulePathSupportsCurrentFrontendStaticUrl(): void
    {
        $path = JsTranslationsExtractor::resolveModulePath('/Weline/Frontend/view/statics/js/weline.js');

        self::assertIsString($path);
        self::assertStringEndsWith(
            str_replace('/', DIRECTORY_SEPARATOR, 'app/code/Weline/Frontend/view/statics/js/weline.js'),
            $path
        );
    }

    public function testExtractWordsFromResolvedFrontendModule(): void
    {
        $path = JsTranslationsExtractor::resolveModulePath('/Weline/Frontend/view/statics/js/weline.js');
        self::assertIsString($path);

        $words = JsTranslationsExtractor::extractWordsFromJsFile($path);

        self::assertArrayHasKey('模块 %{1} 加载失败：未找到 %{2}', $words);
        self::assertArrayHasKey('购物车相关页面', $words);
    }

    public function testExtractWordsFromDeclaredFrontendModule(): void
    {
        $words = JsTranslationsExtractor::extractWordsFromModules(['weline'], 'frontend');

        self::assertArrayHasKey('模块 %{1} 加载失败：未找到 %{2}', $words);
        self::assertArrayHasKey('自动预加载模块', $words);
    }
}
