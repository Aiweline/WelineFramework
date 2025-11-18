<?php
declare(strict_types=1);

namespace Weline\I18n\Helper;

use Weline\Framework\Phrase\Parser;

/**
 * 注册 JS 模块中的翻译词，供请求生命周期内使用
 */
class JsWordsRegistry
{
    /**
     * @var array<string, string>
     */
    private static array $words = [];

    /**
     * 注册翻译词
     *
     * @param array $words 索引数组或关联数组
     */
    public static function addWords(array $words): void
    {
        if (empty($words)) {
            return;
        }

        foreach ($words as $key => $word) {
            // 支持关联数组 [原文 => 原文]
            if (is_string($key) && !is_numeric($key)) {
                $word = $key;
            }

            if (!is_string($word) || $word === '') {
                continue;
            }

            if (!isset(self::$words[$word])) {
                // 通过 Parser 注册，确保翻译被加载
                try {
                    $phrase = $word;
                    Parser::parse($phrase);
                } catch (\Throwable $e) {
                    // 忽略异常，保持健壮
                }
                self::$words[$word] = $word;
            }
        }
    }

    /**
     * 获取已注册的翻译词
     */
    public static function getWords(): array
    {
        return array_values(self::$words);
    }

    /**
     * 获取翻译词及对应翻译
     */
    public static function getWordsWithTranslations(): array
    {
        $translations = Parser::getWords();
        $result = [];

        foreach (self::$words as $word) {
            $result[$word] = $translations[$word] ?? $word;
        }

        return $result;
    }

    /**
     * 清除已注册的翻译词（调试或测试使用）
     */
    public static function reset(): void
    {
        self::$words = [];
    }
}

