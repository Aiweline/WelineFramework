<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

/**
 * Unified UTF-8 CSV codec for module i18n/{locale}.csv files.
 *
 * Contract:
 * - Encoding is UTF-8.
 * - At most one leading file-level UTF-8 BOM may be written (Excel-friendly).
 * - BOM / U+FFFD must never become part of a translation key or value.
 * - Garbled rows (key or value contains BOM bytes, U+FEFF, or U+FFFD) are dropped
 *   one-by-one — never abort the whole batch/file rewrite because of a single bad row;
 *   missing words can be translated again on the next run.
 * - Writers normalize clean keys, dedupe (last value wins), then rewrite the file.
 */
final class I18nCsvCodec
{
    public const UTF8_BOM = "\xEF\xBB\xBF";

    /**
     * True when the raw cell is unsafe to persist (BOM pollution or replacement chars).
     */
    public static function isGarbledText(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        if (str_contains($value, self::UTF8_BOM) || str_contains($value, "\u{FEFF}") || str_contains($value, "\u{FFFD}")) {
            return true;
        }

        return false;
    }

    public static function stripBom(string $value): string
    {
        while ($value !== '' && str_starts_with($value, self::UTF8_BOM)) {
            $value = substr($value, strlen(self::UTF8_BOM));
        }

        $stripped = preg_replace('/^\x{FEFF}+/u', '', $value);

        return is_string($stripped) ? $stripped : $value;
    }

    /**
     * Returns a clean word, or '' when the input is garbled / empty (caller must skip).
     */
    public static function normalizeWord(string $word): string
    {
        if (self::isGarbledText($word)) {
            return '';
        }

        return trim($word);
    }

    /**
     * Returns a clean translation, or '' when the input is garbled (caller may skip the row).
     */
    public static function normalizeTranslation(string $translation): string
    {
        if (self::isGarbledText($translation)) {
            return '';
        }

        return trim($translation);
    }

    /**
     * @param array<string, string> $translations
     * @return array<string, string>
     */
    public static function normalizeMap(array $translations): array
    {
        $normalized = [];
        foreach ($translations as $word => $translation) {
            if (self::isGarbledText((string)$word) || self::isGarbledText((string)$translation)) {
                continue;
            }
            $cleanWord = trim((string)$word);
            if ($cleanWord === '') {
                continue;
            }
            $normalized[$cleanWord] = trim((string)$translation);
        }

        return $normalized;
    }

    /**
     * @return array<string, string>
     */
    public static function readWords(string $csvFile): array
    {
        if ($csvFile === '' || !is_file($csvFile)) {
            return [];
        }

        $handle = fopen($csvFile, 'rb');
        if ($handle === false) {
            return [];
        }

        // Skip a single file-level BOM only; do not treat it as part of the first key.
        $bom = fread($handle, 3);
        if ($bom !== self::UTF8_BOM) {
            rewind($handle);
        }

        $words = [];
        while (($row = fgetcsv($handle, 100000, ',', '"', '\\')) !== false) {
            if (!is_array($row)) {
                continue;
            }
            $rawWord = (string)($row[0] ?? '');
            $rawTranslation = (string)($row[1] ?? '');
            // Drop garbled rows entirely — do not strip-and-keep.
            if (self::isGarbledText($rawWord) || self::isGarbledText($rawTranslation)) {
                continue;
            }
            $word = trim($rawWord);
            if ($word === '') {
                continue;
            }
            $words[$word] = trim($rawTranslation);
        }
        fclose($handle);

        return $words;
    }

    /**
     * @param array<string, string> $translations
     */
    public static function writeWords(string $csvFile, array $translations, bool $withFileBom = true): void
    {
        $dir = dirname($csvFile);
        if ($dir !== '' && !is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException((string)__('无法创建目录：%{1}', [$dir]));
        }

        $normalized = self::normalizeMap($translations);
        $handle = fopen($csvFile, 'wb');
        if ($handle === false) {
            throw new \RuntimeException((string)__('无法写入文件：%{1}', [$csvFile]));
        }

        if ($normalized !== []) {
            if ($withFileBom) {
                fwrite($handle, self::UTF8_BOM);
            }
            foreach ($normalized as $word => $translation) {
                fputcsv($handle, [$word, $translation], ',', '"', '\\');
            }
        }
        fclose($handle);
    }

    /**
     * Rewrite a CSV through read→drop-garbled→write so polluted rows are removed, not salvaged.
     *
     * @return array{words:int, changed:bool, dropped:int}
     */
    public static function sanitizeFile(string $csvFile, bool $withFileBom = true): array
    {
        if ($csvFile === '' || !is_file($csvFile)) {
            return ['words' => 0, 'changed' => false, 'dropped' => 0];
        }

        $before = file_get_contents($csvFile);
        $rawCount = self::countRawDataRows($csvFile);
        $words = self::readWords($csvFile);
        self::writeWords($csvFile, $words, $withFileBom);
        $after = file_get_contents($csvFile);
        $kept = count($words);

        return [
            'words' => $kept,
            'changed' => $before !== $after,
            'dropped' => max(0, $rawCount - $kept),
        ];
    }

    /**
     * Count non-empty CSV rows before garbled filtering (for sanitize stats).
     */
    private static function countRawDataRows(string $csvFile): int
    {
        $handle = fopen($csvFile, 'rb');
        if ($handle === false) {
            return 0;
        }

        $bom = fread($handle, 3);
        if ($bom !== self::UTF8_BOM) {
            rewind($handle);
        }

        $count = 0;
        while (($row = fgetcsv($handle, 100000, ',', '"', '\\')) !== false) {
            if (!is_array($row)) {
                continue;
            }
            if (trim((string)($row[0] ?? '')) === '' && trim((string)($row[1] ?? '')) === '') {
                continue;
            }
            $count++;
        }
        fclose($handle);

        return $count;
    }
}
