<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\Framework\App\Env;

/**
 * Persists the locale-agnostic ClientWordSet (Chinese sources) used to build per-locale .js bags.
 */
final class ClientWordSetStore
{
    public static function pathForArea(string $area): string
    {
        $area = DictionaryAreaPolicy::normalize($area);
        if ($area === DictionaryAreaPolicy::AREA_SHARED) {
            $area = DictionaryAreaPolicy::AREA_FRONTEND;
        }

        return rtrim(Env::path_TRANSLATE_FILES_PATH, '/\\')
            . DIRECTORY_SEPARATOR
            . 'client-wordset'
            . DIRECTORY_SEPARATOR
            . $area
            . '.php';
    }

    /**
     * @param list<string>|array<string, string> $words
     */
    public static function save(string $area, array $words): bool
    {
        $normalized = [];
        foreach ($words as $key => $value) {
            $word = is_string($key) && !is_numeric($key) ? $key : (string)$value;
            $word = trim($word);
            if ($word === '') {
                continue;
            }
            $normalized[$word] = $word;
        }
        foreach (ClientI18nSeedPhrases::all() as $seed) {
            $seed = trim((string)$seed);
            if ($seed !== '') {
                $normalized[$seed] = $seed;
            }
        }
        ksort($normalized, SORT_STRING);
        $list = array_values($normalized);

        $path = self::pathForArea($area);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        $contents = '<?php return ' . var_export($list, true) . ';';
        $tmp = tempnam($dir, '.wordset-');
        if ($tmp === false) {
            return false;
        }
        try {
            if (file_put_contents($tmp, $contents, LOCK_EX) !== strlen($contents) || !rename($tmp, $path)) {
                return false;
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    public static function load(string $area): array
    {
        $path = self::pathForArea($area);
        if (!is_file($path)) {
            return ClientI18nSeedPhrases::all();
        }
        try {
            $words = include $path;
        } catch (\Throwable) {
            return ClientI18nSeedPhrases::all();
        }
        if (!is_array($words)) {
            return ClientI18nSeedPhrases::all();
        }
        $out = [];
        foreach ($words as $word) {
            $word = trim((string)$word);
            if ($word !== '') {
                $out[$word] = $word;
            }
        }
        foreach (ClientI18nSeedPhrases::all() as $seed) {
            $seed = trim((string)$seed);
            if ($seed !== '') {
                $out[$seed] = $seed;
            }
        }
        ksort($out, SORT_STRING);

        return array_values($out);
    }
}
