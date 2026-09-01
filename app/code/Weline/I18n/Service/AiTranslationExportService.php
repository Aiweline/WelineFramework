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
    /**
     * Incrementally appends AI translations to each source module's target locale CSV.
     *
     * @return array<string, mixed>
     */
    public function exportAiTranslationsToModules(string $localeCode): array
    {
        $localeCode = $this->normalizeLocaleCode($localeCode);
        $exported = 0;
        $skipped = 0;
        $modules = [];
        $errors = [];
        $csvCache = [];
        $dirtyCsvFiles = [];
        $page = 1;
        $pageSize = 500;

        while (true) {
            $offset = ($page - 1) * $pageSize;
            $rows = $this->localeDictionary->clear()->reset()
                ->where(LocaleDictionary::schema_fields_LOCALE_CODE, $localeCode)
                ->where(LocaleDictionary::schema_fields_IS_AI, 1)
                ->where(LocaleDictionary::schema_fields_TRANSLATE, '', '!=')
                ->order(LocaleDictionary::schema_fields_MD5, 'ASC')
                ->limit($pageSize, $offset)
                ->select()
                ->fetchArray();

            if (empty($rows)) {
                break;
            }

            foreach ((array)$rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
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
                if (!isset($csvCache[$csvFile])) {
                    $csvCache[$csvFile] = I18nCsvCodec::readWords($csvFile);
                }
                $word = I18nCsvCodec::normalizeWord($word);
                $translation = I18nCsvCodec::normalizeTranslation($translation);
                // 乱码词/译文只跳过本条，本批其余词照常写回。
                if ($word === '' || $translation === '' || $translation === $word) {
                    $skipped++;
                    continue;
                }
                $existingTranslation = trim((string)($csvCache[$csvFile][$word] ?? ''));
                if ($existingTranslation !== '' && $existingTranslation !== $word) {
                    $skipped++;
                    continue;
                }

                try {
                    $csvCache[$csvFile][$word] = $translation;
                    $dirtyCsvFiles[$csvFile] = true;
                    $this->markExported((string)($row[LocaleDictionary::schema_fields_MD5] ?? ''));
                    $modules[$moduleName] = ($modules[$moduleName] ?? 0) + 1;
                    $exported++;
                } catch (\Throwable $throwable) {
                    $errors[] = (string)__('导出 %{1} 到模块 %{2} 失败：%{3}', [$word, $moduleName, $throwable->getMessage()]);
                }
            }

            if (count($rows) < $pageSize) {
                break;
            }
            $page++;
        }

        foreach (array_keys($dirtyCsvFiles) as $csvFile) {
            try {
                I18nCsvCodec::writeWords($csvFile, $csvCache[$csvFile] ?? []);
            } catch (\Throwable $throwable) {
                $errors[] = (string)__('写入 CSV 失败：%{1} — %{2}', [$csvFile, $throwable->getMessage()]);
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
     * Modules that currently have AI translations for the locale (for export picker).
     *
     * @return list<array{module:string,count:int}>
     */
    public function listAiSourceModules(string $localeCode): array
    {
        $localeCode = $this->normalizeLocaleCode($localeCode);
        if ($localeCode === '') {
            return [];
        }

        $counts = [];
        $page = 1;
        $pageSize = 500;
        while (true) {
            $offset = ($page - 1) * $pageSize;
            $rows = $this->localeDictionary->clear()->reset()
                ->fields(LocaleDictionary::schema_fields_SOURCE_MODULE)
                ->where(LocaleDictionary::schema_fields_LOCALE_CODE, $localeCode)
                ->where(LocaleDictionary::schema_fields_IS_AI, 1)
                ->where(LocaleDictionary::schema_fields_TRANSLATE, '', '!=')
                ->order(LocaleDictionary::schema_fields_MD5, 'ASC')
                ->limit($pageSize, $offset)
                ->select()
                ->fetchArray();

            if (empty($rows)) {
                break;
            }

            foreach ((array)$rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $moduleName = trim((string)($row[LocaleDictionary::schema_fields_SOURCE_MODULE] ?? ''));
                if ($moduleName === '') {
                    continue;
                }
                $counts[$moduleName] = ($counts[$moduleName] ?? 0) + 1;
            }

            if (count($rows) < $pageSize) {
                break;
            }
            $page++;
        }

        arsort($counts);
        $out = [];
        foreach ($counts as $moduleName => $count) {
            $out[] = [
                'module' => (string)$moduleName,
                'count' => (int)$count,
            ];
        }

        return $out;
    }


    /**
     * 将指定模块在词典库中的译文写回该模块 i18n/{locale}.csv（开发环境）。
     *
     * @return array<string, mixed>
     */
    public function exportModuleTranslations(string $moduleName, string $localeCode, bool $aiOnly = false): array
    {
        $localeCode = $this->normalizeLocaleCode($localeCode);
        $moduleName = trim($moduleName);
        if ($moduleName === '') {
            return [
                'success' => false,
                'exported' => 0,
                'skipped' => 0,
                'errors' => [(string)__('模块名不能为空')],
            ];
        }

        $moduleInfo = Env::getInstance()->getModuleInfo($moduleName);
        $basePath = is_array($moduleInfo) ? rtrim((string)($moduleInfo['base_path'] ?? ''), "\\/") : '';
        if ($basePath === '' || !is_dir($basePath)) {
            return [
                'success' => false,
                'exported' => 0,
                'skipped' => 0,
                'errors' => [(string)__('模块 %{1} 不存在', [$moduleName])],
            ];
        }

        $query = $this->localeDictionary->clear()->reset()
            ->where(LocaleDictionary::schema_fields_LOCALE_CODE, $localeCode)
            ->where(LocaleDictionary::schema_fields_TRANSLATE, '', '!=')
            ->where(LocaleDictionary::schema_fields_SOURCE_MODULE, $moduleName);

        if ($aiOnly) {
            // 与「待写回」一致：只取尚未标记导出的 AI 行（增量写回）。
            $query->where(LocaleDictionary::schema_fields_IS_AI, 1)
                ->where(LocaleDictionary::schema_fields_EXPORTED_AT, null);
        }

        $rows = $query->select()->fetchArray();
        $rowsByWord = [];
        foreach ((array)$rows as $row) {
            $word = trim((string)($row[LocaleDictionary::schema_fields_WORD] ?? ''));
            if ($word !== '') {
                $rowsByWord[$word] = $row;
            }
        }

        // 全量路径才按源 CSV 回填词典；AI 增量写回只处理 pending 行。
        if (!$aiOnly) {
            $sourceCsv = $basePath . DS . 'i18n' . DS . $this->resolveSourceLocale() . '.csv';
            foreach (array_keys(I18nCsvCodec::readWords(is_file($sourceCsv) ? $sourceCsv : '')) as $word) {
                if (isset($rowsByWord[$word])) {
                    continue;
                }
                $dbRow = $this->localeDictionary->reset()
                    ->where(LocaleDictionary::schema_fields_WORD, $word)
                    ->where(LocaleDictionary::schema_fields_LOCALE_CODE, $localeCode)
                    ->where(LocaleDictionary::schema_fields_TRANSLATE, '', '!=')
                    ->find()
                    ->fetch();
                if ((int)$dbRow->getId() > 0) {
                    $rowsByWord[$word] = $dbRow->getData();
                }
            }
        }

        $exported = 0;
        $skipped = 0;
        $errors = [];
        $csvFile = $basePath . DS . 'i18n' . DS . $localeCode . '.csv';
        $existing = I18nCsvCodec::readWords($csvFile);
        $dirty = false;

        foreach ($rowsByWord as $row) {
            $word = I18nCsvCodec::normalizeWord((string)($row[LocaleDictionary::schema_fields_WORD] ?? ''));
            $translation = I18nCsvCodec::normalizeTranslation((string)($row[LocaleDictionary::schema_fields_TRANSLATE] ?? ''));
            $md5 = (string)($row[LocaleDictionary::schema_fields_MD5] ?? '');
            if ($word === '' || $translation === '' || $translation === $word) {
                $skipped++;
                continue;
            }

            $existingTranslation = trim((string)($existing[$word] ?? ''));
            if ($existingTranslation !== '' && $existingTranslation !== $word) {
                // CSV 已有译文：不再重复写入，但仍标记已导出以清零「待写回」。
                if ($aiOnly && $md5 !== '') {
                    $this->markExported($md5);
                }
                $skipped++;
                continue;
            }

            try {
                $existing[$word] = $translation;
                $dirty = true;
                $this->markExported($md5);
                $exported++;
            } catch (\Throwable $throwable) {
                $errors[] = (string)__('导出 %{1} 到模块 %{2} 失败：%{3}', [$word, $moduleName, $throwable->getMessage()]);
            }
        }

        if ($dirty) {
            try {
                I18nCsvCodec::writeWords($csvFile, $existing);
            } catch (\Throwable $throwable) {
                $errors[] = (string)__('写入 CSV 失败：%{1} — %{2}', [$csvFile, $throwable->getMessage()]);
            }
        }

        if ($exported > 0) {
            $this->publisher->publishLocale($localeCode);
        }

        return [
            'success' => $errors === [],
            'exported' => $exported,
            'skipped' => $skipped,
            'module' => $moduleName,
            'csv_file' => $csvFile,
            'errors' => $errors,
            'message' => (string)__(
                '模块 %{1} 已写回 %{2} 条到 CSV，跳过 %{3} 条。',
                [$moduleName, (string)$exported, (string)$skipped],
            ),
        ];
    }

    /**
     * Fill target-locale CSV gaps from dictionary (any source), keyed by source-locale CSV.
     * Used by module writeback pipeline so DB-already-translated words still land in CSV.
     *
     * @return array{
     *     success: bool,
     *     exported: int,
     *     skipped: int,
     *     gap_before: int,
     *     gap_after: int,
     *     module: string,
     *     csv_file: string,
     *     errors: list<string>,
     *     message: string
     * }
     */
    public function exportModuleCsvGaps(string $moduleName, string $localeCode): array
    {
        $localeCode = $this->normalizeLocaleCode($localeCode);
        $moduleName = trim($moduleName);
        $empty = [
            'success' => false,
            'exported' => 0,
            'skipped' => 0,
            'gap_before' => 0,
            'gap_after' => 0,
            'module' => $moduleName,
            'csv_file' => '',
            'errors' => [],
            'message' => '',
        ];
        if ($moduleName === '') {
            $empty['errors'][] = (string)__('模块名不能为空');
            $empty['message'] = $empty['errors'][0];

            return $empty;
        }

        $moduleInfo = Env::getInstance()->getModuleInfo($moduleName);
        $basePath = is_array($moduleInfo) ? rtrim((string)($moduleInfo['base_path'] ?? ''), "\\/") : '';
        if ($basePath === '' || !is_dir($basePath)) {
            $empty['errors'][] = (string)__('模块 %{1} 不存在', [$moduleName]);
            $empty['message'] = $empty['errors'][0];

            return $empty;
        }

        $sourceLocale = $this->resolveSourceLocale();
        $sourceCsv = $basePath . DS . 'i18n' . DS . $sourceLocale . '.csv';
        $csvFile = $basePath . DS . 'i18n' . DS . $localeCode . '.csv';
        $sourceWords = I18nCsvCodec::readWords(is_file($sourceCsv) ? $sourceCsv : '');
        $existing = I18nCsvCodec::readWords(is_file($csvFile) ? $csvFile : '');

        $gapWords = [];
        foreach (array_keys($sourceWords) as $word) {
            $word = I18nCsvCodec::normalizeWord((string)$word);
            if ($word === '') {
                continue;
            }
            $csvTranslation = trim((string)($existing[$word] ?? ''));
            if ($csvTranslation !== '' && $csvTranslation !== $word) {
                continue;
            }
            $gapWords[] = $word;
        }

        $gapBefore = count($gapWords);
        $exported = 0;
        $skipped = 0;
        $errors = [];
        $dirty = false;

        foreach ($gapWords as $word) {
            $dbRow = $this->localeDictionary->clear()->reset()
                ->where(LocaleDictionary::schema_fields_WORD, $word)
                ->where(LocaleDictionary::schema_fields_LOCALE_CODE, $localeCode)
                ->where(LocaleDictionary::schema_fields_TRANSLATE, '', '!=')
                ->find()
                ->fetch();
            if ((int)$dbRow->getId() <= 0) {
                $skipped++;
                continue;
            }
            $translation = I18nCsvCodec::normalizeTranslation((string)$dbRow->getData(LocaleDictionary::schema_fields_TRANSLATE));
            $md5 = (string)$dbRow->getData(LocaleDictionary::schema_fields_MD5);
            if ($translation === '' || $translation === $word) {
                $skipped++;
                continue;
            }
            try {
                $existing[$word] = $translation;
                $dirty = true;
                if ($md5 !== '') {
                    $this->markExported($md5);
                }
                $exported++;
            } catch (\Throwable $throwable) {
                $errors[] = (string)__('导出 %{1} 到模块 %{2} 失败：%{3}', [
                    $word,
                    $moduleName,
                    $throwable->getMessage(),
                ]);
            }
        }

        if ($dirty) {
            try {
                I18nCsvCodec::writeWords($csvFile, $existing);
            } catch (\Throwable $throwable) {
                $errors[] = (string)__('写入 CSV 失败：%{1} — %{2}', [$csvFile, $throwable->getMessage()]);
            }
        }

        $gapAfter = 0;
        foreach (array_keys($sourceWords) as $word) {
            $word = I18nCsvCodec::normalizeWord((string)$word);
            if ($word === '') {
                continue;
            }
            $csvTranslation = trim((string)($existing[$word] ?? ''));
            if ($csvTranslation === '' || $csvTranslation === $word) {
                $gapAfter++;
            }
        }

        if ($exported > 0) {
            $this->publisher->publishLocale($localeCode);
        }

        return [
            'success' => $errors === [],
            'exported' => $exported,
            'skipped' => $skipped,
            'gap_before' => $gapBefore,
            'gap_after' => $gapAfter,
            'module' => $moduleName,
            'csv_file' => $csvFile,
            'errors' => $errors,
            'message' => (string)__(
                '模块 %{1}/%{2}：差额写回 %{3} 条，剩余差额 %{4}。',
                [$moduleName, $localeCode, (string)$exported, (string)$gapAfter],
            ),
        ];
    }

    private function resolveSourceLocale(): string
    {
        return trim(str_replace('-', '_', (string)Env::default_LANGUAGE_CODE));
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
        I18nCsvCodec::writeWords($path, $words);

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
