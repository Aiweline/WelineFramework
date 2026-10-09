<?php

declare(strict_types=1);

namespace Weline\Framework\Phrase;

use Weline\Framework\App\Env;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\File\Scan;

/**
 * Collect CLI-selectable Phrase locales: module CSV + generated/language, then event merge + dedupe.
 */
final class CliLanguageCatalog
{
    /**
     * @return list<array{code: string, label: string, source: string}>
     */
    public function languages(): array
    {
        $byCode = [];

        foreach ($this->discoverCsvAndGeneratedLocales() as $code) {
            $byCode[$code] = [
                'code' => $code,
                'label' => $code,
                'source' => 'csv',
            ];
        }

        $payload = [
            'languages' => \array_values($byCode),
        ];

        try {
            /** @var EventsManager $events */
            $events = ObjectManager::getInstance(EventsManager::class);
            $events->dispatch(CliLanguage::EVENT_OPTIONS, $payload);
        } catch (\Throwable) {
        }

        foreach ((array)($payload['languages'] ?? []) as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $code = CliLanguage::normalize((string)($row['code'] ?? ''));
            if ($code === '' || !\Weline\Framework\App\State::isLanguageCodeShape($code)) {
                continue;
            }
            $label = \trim((string)($row['label'] ?? ''));
            $source = \trim((string)($row['source'] ?? 'event'));
            if (!isset($byCode[$code])) {
                $byCode[$code] = [
                    'code' => $code,
                    'label' => $label !== '' ? $label : $code,
                    'source' => $source !== '' ? $source : 'event',
                ];
                continue;
            }
            if ($label !== '' && ($byCode[$code]['label'] === '' || $byCode[$code]['label'] === $code)) {
                $byCode[$code]['label'] = $label;
            }
            if ($source !== '' && $byCode[$code]['source'] === 'csv') {
                $byCode[$code]['source'] = $source;
            }
        }

        return $this->sortLanguages(\array_values($byCode));
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        $codes = [];
        foreach ($this->languages() as $row) {
            $codes[] = $row['code'];
        }

        return $codes;
    }

    /**
     * @return list<string>
     */
    private function discoverCsvAndGeneratedLocales(): array
    {
        $codes = [];
        $scanner = new Scan();

        foreach (Env::getInstance()->getActiveModules() as $module) {
            $basePath = (string)($module['base_path'] ?? '');
            $i18nDir = $basePath !== '' ? \rtrim($basePath, '/\\') . DS . 'i18n' : '';
            if ($i18nDir === '' || !\is_dir($i18nDir)) {
                continue;
            }
            $files = [];
            $scanner->globFile($i18nDir, $files, '.csv');
            foreach ($files as $file) {
                $locale = \pathinfo((string)$file, PATHINFO_FILENAME);
                $locale = CliLanguage::normalize($locale);
                if ($locale !== '' && !\str_contains($locale, '_total')) {
                    $codes[$locale] = true;
                }
            }
        }

        $generatedDir = \rtrim(Env::path_TRANSLATE_FILES_PATH, '/\\');
        if ($generatedDir !== '' && \is_dir($generatedDir)) {
            foreach (\glob($generatedDir . DS . '*.php') ?: [] as $file) {
                $locale = CliLanguage::normalize((string)\pathinfo((string)$file, PATHINFO_FILENAME));
                if ($locale !== '' && !\str_ends_with($locale, '_total')) {
                    $codes[$locale] = true;
                }
            }
        }

        $codes[Env::default_LANGUAGE_CODE] = true;
        $codes['en_US'] = true;

        return \array_keys($codes);
    }

    /**
     * @param list<array{code: string, label: string, source: string}> $languages
     * @return list<array{code: string, label: string, source: string}>
     */
    private function sortLanguages(array $languages): array
    {
        $priority = [
            Env::default_LANGUAGE_CODE => 0,
            'en_US' => 1,
        ];
        \usort(
            $languages,
            static function (array $left, array $right) use ($priority): int {
                $l = $priority[$left['code']] ?? 100;
                $r = $priority[$right['code']] ?? 100;
                if ($l !== $r) {
                    return $l <=> $r;
                }

                return \strcasecmp($left['code'], $right['code']);
            }
        );

        return $languages;
    }
}
