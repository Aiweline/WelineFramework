<?php
declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\Framework\App\Env;
use Weline\I18n\Model\Locale\Dictionary as LocaleDictionary;

class AiTranslationExportService
{
    public function __construct(
        private readonly LocaleDictionary $localeDictionary,
        private readonly AiTranslationPublisher $publisher
    ) {
    }

    /**
     * Incrementally appends AI translations to each source module's target locale CSV.
     *
     * @return array<string, mixed>
     */
    public function exportAiTranslationsToModules(string $localeCode): array
    {
        $localeCode = $this->normalizeLocaleCode($localeCode);
        $rows = $this->localeDictionary->clear()->reset()
            ->where(LocaleDictionary::schema_fields_LOCALE_CODE, $localeCode)
            ->where(LocaleDictionary::schema_fields_IS_AI, 1)
            ->where(LocaleDictionary::schema_fields_TRANSLATE, '', '!=')
            ->select()
            ->fetchArray();

        $exported = 0;
        $skipped = 0;
        $modules = [];
        $errors = [];

        foreach ((array)$rows as $row) {
            $word = trim((string)($row[LocaleDictionary::schema_fields_WORD] ?? ''));
            $translation = trim((string)($row[LocaleDictionary::schema_fields_TRANSLATE] ?? ''));
            $moduleName = trim((string)($row[LocaleDictionary::schema_fields_SOURCE_MODULE] ?? ''));
            if ($word === '' || $translation === '' || $translation === $word || $moduleName === '') {
                $skipped++;
                continue;
            }

            $moduleInfo = Env::getInstance()->getModuleInfo($moduleName);
            $basePath = is_array($moduleInfo) ? rtrim((string)($moduleInfo['base_path'] ?? ''), "\\/") : '';
            if ($basePath === '' || !is_dir($basePath)) {
                $errors[] = (string)__('模块 %{1} 不存在，跳过词：%{2}', [$moduleName, $word]);
                $skipped++;
                continue;
            }

            $csvFile = $basePath . DS . 'i18n' . DS . $localeCode . '.csv';
            $existing = $this->readCsvWords($csvFile);
            $existingTranslation = trim((string)($existing[$word] ?? ''));
            if ($existingTranslation !== '' && $existingTranslation !== $word) {
                $skipped++;
                continue;
            }

            try {
                $this->appendCsvRow($csvFile, $word, $translation);
                $this->markExported((string)($row[LocaleDictionary::schema_fields_MD5] ?? ''));
                $modules[$moduleName] = ($modules[$moduleName] ?? 0) + 1;
                $exported++;
            } catch (\Throwable $throwable) {
                $errors[] = (string)__('导出 %{1} 到模块 %{2} 失败：%{3}', [$word, $moduleName, $throwable->getMessage()]);
            }
        }

        if ($exported > 0) {
            $this->publisher->publishLocale($localeCode);
        }

        return [
            'success' => $errors === [],
            'exported' => $exported,
            'skipped' => $skipped,
            'modules' => $modules,
            'errors' => $errors,
        ];
    }

    /**
     * Exports the DB language pack as a global CSV file.
     */
    public function exportGlobalLanguagePack(string $localeCode): string
    {
        $localeCode = $this->normalizeLocaleCode($localeCode);
        $this->publisher->publishLocale($localeCode);
        $words = $this->readGeneratedLanguageWords($localeCode);
        $rows = $this->localeDictionary->clear()->reset()
            ->where(LocaleDictionary::schema_fields_LOCALE_CODE, $localeCode)
            ->where(LocaleDictionary::schema_fields_TRANSLATE, '', '!=')
            ->select()
            ->fetchArray();

        foreach ((array)$rows as $row) {
            $word = (string)($row[LocaleDictionary::schema_fields_WORD] ?? '');
            $translation = (string)($row[LocaleDictionary::schema_fields_TRANSLATE] ?? '');
            if ($word !== '' && $translation !== '') {
                $words[$word] = $translation;
            }
        }

        $path = sys_get_temp_dir() . DS . 'i18n-language-pack-' . $localeCode . '-' . date('YmdHis') . '.csv';
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException((string)__('无法创建导出文件。'));
        }

        fwrite($handle, "\xEF\xBB\xBF");
        foreach ($words as $word => $translation) {
            if ($word !== '' && $translation !== '') {
                fputcsv($handle, [$word, $translation]);
            }
        }
        fclose($handle);

        return $path;
    }

    /**
     * @return array<string, string>
     */
    private function readGeneratedLanguageWords(string $localeCode): array
    {
        $localeFile = BP . DS . 'generated' . DS . 'language' . DS . $localeCode . '.php';
        if (!is_file($localeFile)) {
            return [];
        }

        $words = include $localeFile;
        if (!is_array($words)) {
            return [];
        }

        $result = [];
        $this->flattenGeneratedTranslations($words, $result);

        return $result;
    }

    /**
     * @param array<mixed> $words
     * @param array<string, string> $result
     */
    private function flattenGeneratedTranslations(array $words, array &$result): void
    {
        foreach ($words as $word => $translation) {
            if (is_array($translation)) {
                $this->flattenGeneratedTranslations($translation, $result);
                continue;
            }
            if (is_string($word) && is_string($translation) && $word !== '' && $translation !== '') {
                $result[$word] = $translation;
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function readCsvWords(string $csvFile): array
    {
        if (!is_file($csvFile)) {
            return [];
        }

        $handle = fopen($csvFile, 'rb');
        if ($handle === false) {
            return [];
        }

        $words = [];
        while (($row = fgetcsv($handle, 100000, ',', '"', '\\')) !== false) {
            $word = trim((string)($row[0] ?? ''));
            if ($word !== '') {
                $words[$word] = trim((string)($row[1] ?? ''));
            }
        }
        fclose($handle);

        return $words;
    }

    private function appendCsvRow(string $csvFile, string $word, string $translation): void
    {
        $dir = dirname($csvFile);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException((string)__('无法创建目录：%{1}', [$dir]));
        }

        $needsNewLine = is_file($csvFile) && filesize($csvFile) > 0;
        $handle = fopen($csvFile, 'ab');
        if ($handle === false) {
            throw new \RuntimeException((string)__('无法写入文件：%{1}', [$csvFile]));
        }

        if ($needsNewLine) {
            $tail = file_get_contents($csvFile, false, null, max(0, filesize($csvFile) - 1));
            if ($tail !== false && $tail !== "\n") {
                fwrite($handle, PHP_EOL);
            }
        }
        fputcsv($handle, [$word, $translation]);
        fclose($handle);
    }

    private function markExported(string $md5): void
    {
        if ($md5 === '') {
            return;
        }

        $this->localeDictionary->clear()->reset()
            ->where(LocaleDictionary::schema_fields_MD5, $md5)
            ->update([LocaleDictionary::schema_fields_EXPORTED_AT => date('Y-m-d H:i:s')])
            ->fetch();
    }

    private function normalizeLocaleCode(string $localeCode): string
    {
        return trim(str_replace('-', '_', $localeCode));
    }
}
