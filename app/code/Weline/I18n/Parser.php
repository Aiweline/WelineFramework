<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归 Aiweline 所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\I18n;

use Weline\Framework\App\Env;
use Weline\Framework\Http\Cookie;

class Parser
{
    private static array $translateWordsCache = [];
    private static ?array $fallbackWordsCache = null;

    public static function parse(string $words, array $args): string
    {
        $translate_words = self::loadTranslateWords(Cookie::getLangLocal());
        if (empty($translate_words)) {
            $translate_words = self::loadFallbackWords();
        }
        $words = $translate_words[$words] ?? $words;
        if ($args) {
            foreach ($args as $key => $arg) {
                $words = str_replace('%{' . (is_numeric($key) ? $key + 1 : $key) . '}', $arg, $words);
            }
        }

        return $words;
    }

    public static function preloadWorkerDictionaries(): void
    {
        foreach (self::discoverPreloadLanguages() as $lang) {
            self::loadTranslateWords($lang);
        }
    }

    public static function clearWorkerCaches(): void
    {
        self::$translateWordsCache = [];
        self::$fallbackWordsCache = null;
    }

    private static function discoverPreloadLanguages(): array
    {
        $configured = self::normalizeLanguageList(Env::get('wls.i18n.preload_locales', ''));
        if ($configured === []) {
            $configured = self::normalizeLanguageList(Env::get('i18n.preload_locales', ''));
        }
        if ($configured === []) {
            $configured = self::normalizeLanguageList(Env::get('i18n.locales', ''));
        }
        if (\in_array('all', $configured, true)) {
            $languages = [];
            foreach (\glob(Env::path_TRANSLATE_FILES_PATH . '*.php') ?: [] as $file) {
                $languages[] = \pathinfo($file, PATHINFO_FILENAME);
            }
            $languages[] = Env::default_LANGUAGE_CODE;
            return \array_values(\array_unique(\array_filter($languages)));
        }

        $languages = \array_merge(
            $configured,
            self::normalizeLanguageList(Env::get('user.lang', '')),
            self::normalizeLanguageList(Env::get('locale', '')),
            self::normalizeLanguageList(Env::get('language', '')),
            [Env::default_LANGUAGE_CODE]
        );

        return \array_values(\array_unique(\array_filter($languages)));
    }

    private static function normalizeLanguageList(mixed $value): array
    {
        if (\is_string($value)) {
            $decoded = \json_decode($value, true);
            if (\is_array($decoded)) {
                $value = $decoded;
            } else {
                $value = \preg_split('/[,\s]+/', $value) ?: [];
            }
        }
        if (!\is_array($value)) {
            return [];
        }

        $languages = [];
        foreach ($value as $key => $row) {
            if (\is_array($row)) {
                if (!empty($row['enabled']) && \is_string($key)) {
                    $languages[] = $key;
                }
                if (!empty($row['code'])) {
                    $languages[] = (string)$row['code'];
                }
                if (!empty($row['locale'])) {
                    $languages[] = (string)$row['locale'];
                }
                continue;
            }
            if (\is_string($key) && $key !== '' && \filter_var($row, FILTER_VALIDATE_BOOLEAN)) {
                $languages[] = $key;
            }
            if (\is_scalar($row)) {
                $languages[] = (string)$row;
            }
        }

        return \array_values(\array_unique(\array_filter(\array_map('trim', $languages))));
    }

    private static function loadTranslateWords(string $lang): array
    {
        if (isset(self::$translateWordsCache[$lang])) {
            return self::$translateWordsCache[$lang];
        }

        $translate_words_file = Env::path_TRANSLATE_FILES_PATH . $lang . '.php';
        if (!\is_file($translate_words_file)) {
            return self::$translateWordsCache[$lang] = [];
        }

        $translate_words = include $translate_words_file;
        return self::$translateWordsCache[$lang] = \is_array($translate_words) ? $translate_words : [];
    }

    private static function loadFallbackWords(): array
    {
        if (self::$fallbackWordsCache !== null) {
            return self::$fallbackWordsCache;
        }

        $filename = Env::path_TRANSLATE_ALL_COLLECTIONS_WORDS_FILE;
        if (!\file_exists($filename)) {
            \touch($filename);
        }
        try {
            $default_all_words = (array)include $filename;
        } catch (\Weline\Framework\App\Exception $exception) {
            throw new \Weline\Framework\App\Exception($exception->getMessage());
        }

        return self::$fallbackWordsCache = $default_all_words;
    }
}
