<?php

declare(strict_types=1);

namespace Weline\Framework\Phrase;

/**
 * File-only translation for lock-contention and early-bootstrap messages.
 *
 * Normal application text must continue to use __(). This narrow path exists
 * because the regular Phrase Parser may consult the global dictionary through
 * the database, which is forbidden before a database-access lock is held.
 */
final class DatabaseFreeTranslator
{
    public static function translate(string $source, string $moduleName): string
    {
        $moduleDirectory = self::moduleDirectory($moduleName);
        if ($moduleDirectory === null) {
            return $source;
        }

        foreach (self::localeCandidates() as $locale) {
            $file = $moduleDirectory . DIRECTORY_SEPARATOR . 'i18n' . DIRECTORY_SEPARATOR . $locale . '.csv';
            $translated = self::findTranslation($file, $source);
            if ($translated !== null) {
                return $translated;
            }
        }

        return $source;
    }

    /** @return list<string> */
    private static function localeCandidates(): array
    {
        $candidates = [];
        foreach ([self::readConfiguredLocale()] as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $candidate = str_replace('-', '_', trim($candidate));
            if ($candidate === '' || !preg_match('/^[A-Za-z0-9_.@]+$/D', $candidate)) {
                continue;
            }
            $candidates[] = $candidate;
            if (str_starts_with(strtolower($candidate), 'en')) {
                $candidates[] = 'en_US';
            } elseif (str_starts_with(strtolower($candidate), 'zh')) {
                $candidates[] = 'zh_Hans_CN';
            }
        }
        $candidates[] = 'zh_Hans_CN';

        return array_values(array_unique($candidates));
    }

    private static function readConfiguredLocale(): ?string
    {
        $envFile = self::basePath() . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'env.php';
        $contents = @file_get_contents($envFile);
        if (!is_string($contents) || $contents === '') {
            return null;
        }

        // Do not include/execute env.php on this path. A conservative literal
        // lookup keeps the contention branch structurally file-only.
        if (preg_match('/[\'\"]system[\'\"]\s*=>\s*\[(.*?)\]/s', $contents, $systemMatch) !== 1
            || preg_match('/[\'\"](?:lang|locale)[\'\"]\s*=>\s*[\'\"]([^\'\"]+)[\'\"]/', $systemMatch[1], $localeMatch) !== 1
        ) {
            return null;
        }

        return $localeMatch[1];
    }

    private static function moduleDirectory(string $moduleName): ?string
    {
        $parts = explode('_', $moduleName);
        if (count($parts) !== 2) {
            return null;
        }
        foreach ($parts as $part) {
            if ($part === '' || !preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $part)) {
                return null;
            }
        }

        return self::basePath() . 'app' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR
            . $parts[0] . DIRECTORY_SEPARATOR . $parts[1];
    }

    private static function basePath(): string
    {
        if (\defined('BP')) {
            return (string)\constant('BP');
        }

        return dirname(__DIR__, 5) . DIRECTORY_SEPARATOR;
    }

    private static function findTranslation(string $file, string $source): ?string
    {
        $handle = @fopen($file, 'rb');
        if (!is_resource($handle)) {
            return null;
        }

        try {
            $prefix = fread($handle, 3);
            if ($prefix !== "\xEF\xBB\xBF") {
                rewind($handle);
            }
            while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $key = count($row) > 0 ? (string)$row[0] : '';
                if (count($row) < 2 || $key !== $source) {
                    continue;
                }
                $translation = (string)$row[1];
                return $translation !== '' ? $translation : $source;
            }
        } finally {
            fclose($handle);
        }

        return null;
    }
}
