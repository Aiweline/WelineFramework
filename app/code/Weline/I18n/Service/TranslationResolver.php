<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\Framework\App\Env;
use Weline\I18n\Api\Translation\TranslationResolverInterface;

final class TranslationResolver implements TranslationResolverInterface
{
    /** @var array<string, array<string, string>> */
    private array $moduleWords = [];

    public function translate(string $source, string $localeCode, array $preferredModules = []): string
    {
        $source = \trim($source);
        if ($source === '') {
            return '';
        }

        foreach (\array_values(\array_unique($preferredModules)) as $moduleName) {
            $words = $this->moduleWords((string)$moduleName, $localeCode);
            if (isset($words[$source]) && $words[$source] !== '' && $words[$source] !== $source) {
                return $words[$source];
            }
        }

        $translated = (string)\__($source);
        return $translated !== '' ? $translated : $source;
    }

    /** @return array<string, string> */
    private function moduleWords(string $moduleName, string $localeCode): array
    {
        $cacheKey = $localeCode . '|' . $moduleName;
        if (isset($this->moduleWords[$cacheKey])) {
            return $this->moduleWords[$cacheKey];
        }

        $words = [];
        try {
            $moduleInfo = Env::getInstance()->getModuleInfo($moduleName);
            $csvFile = (string)($moduleInfo['base_path'] ?? '') . '/i18n/' . $localeCode . '.csv';
            if (!\is_file($csvFile)) {
                return $this->moduleWords[$cacheKey] = [];
            }

            $handle = @\fopen($csvFile, 'r');
            if ($handle === false) {
                return $this->moduleWords[$cacheKey] = [];
            }
            try {
                while (($data = \fgetcsv($handle, 100000, ',', '"', '\\')) !== false) {
                    if (!isset($data[0], $data[1])) {
                        continue;
                    }
                    $key = \trim((string)$data[0]);
                    $value = \trim((string)$data[1]);
                    if ($key !== '' && $value !== '' && $value !== $key) {
                        $words[$key] = $value;
                    }
                }
            } finally {
                \fclose($handle);
            }
        } catch (\Throwable) {
        }

        return $this->moduleWords[$cacheKey] = $words;
    }
}
