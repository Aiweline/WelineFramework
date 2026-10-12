<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Phrase;

use PHPUnit\Framework\TestCase;

/**
 * generated/language/{locale}.php 不得由 DictionaryCompiler 写出（唯一权威：I18n AiTranslationPublisher）。
 */
final class DictionaryCompilerLocaleFileSoleWriterContractTest extends TestCase
{
    public function testCompilerDoesNotWritePathTranslateFilesLocalePhp(): void
    {
        $path = dirname(__DIR__, 3) . '/Phrase/DictionaryCompiler.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);

        self::assertStringContainsString('AiTranslationPublisher', $source);
        self::assertStringContainsString('不写 generated/language/{locale}.php', $source);

        // 不得再出现向 path_TRANSLATE_FILES_PATH 拼 locale.php 并写入的循环。
        self::assertDoesNotMatchRegularExpression(
            '/path_TRANSLATE_FILES_PATH\s*\.\s*\$locale\s*\.\s*[\'"]\.php[\'"]/',
            $source,
        );
        self::assertStringNotContainsString('new File()', $source);
    }
}
