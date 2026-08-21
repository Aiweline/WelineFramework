<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 */

namespace Weline\Theme\Font;

/**
 * Resolve default glyph text for a language / locale code.
 *
 * Charset files live in {@see self::CHARSET_DIR} as `{lang}.txt` (UTF-8).
 * Unknown languages fall back to Latin base + optional `{base}.txt`.
 */
class LanguageCharsetResolver
{
    public const CHARSET_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'charset';

    /** ASCII printable + common punctuation always kept in every subset. */
    public const LATIN_BASE = " !\"#\$%&'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_`abcdefghijklmnopqrstuvwxyz{|}~\n\r\t";

    /**
     * Normalize locale-ish codes (hyphen → underscore). Empty stays empty —
     * current request language comes from {@see \Weline\Framework\App\State}, not here.
     */
    public function normalize(string $langCode): string
    {
        return str_replace('-', '_', trim($langCode));
    }

    /**
     * Candidates from specific → base (zh_Hans_CN, zh_Hans, zh).
     *
     * @return list<string>
     */
    public function candidates(string $langCode): array
    {
        $lang = $this->normalize($langCode);
        if ($lang === '') {
            return [];
        }
        $parts = explode('_', $lang);
        $out = [];
        while ($parts !== []) {
            $out[] = implode('_', $parts);
            array_pop($parts);
        }

        return array_values(array_unique($out));
    }

    /**
     * Build character corpus for subsetting.
     */
    public function resolve(string $langCode, string $extraChars = ''): string
    {
        $chunks = [self::LATIN_BASE];

        foreach ($this->candidates($langCode) as $candidate) {
            $file = self::CHARSET_DIR . DIRECTORY_SEPARATOR . $candidate . '.txt';
            if (is_file($file)) {
                $text = (string)file_get_contents($file);
                if ($text !== '') {
                    $chunks[] = $text;
                    break;
                }
            }
        }

        if ($extraChars !== '') {
            $chunks[] = $extraChars;
        }

        return $this->uniqueChars(implode('', $chunks));
    }

    /**
     * Fingerprint of the charset used for a lang (+ extra), for cache keys.
     */
    public function fingerprint(string $langCode, string $extraChars = ''): string
    {
        return hash('sha256', $this->resolve($langCode, $extraChars));
    }

    private function uniqueChars(string $text): string
    {
        if ($text === '') {
            return self::LATIN_BASE;
        }

        $seen = [];
        $out = '';
        $len = mb_strlen($text, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($text, $i, 1, 'UTF-8');
            if ($ch === '' || isset($seen[$ch])) {
                continue;
            }
            $seen[$ch] = true;
            $out .= $ch;
        }

        return $out !== '' ? $out : self::LATIN_BASE;
    }
}
