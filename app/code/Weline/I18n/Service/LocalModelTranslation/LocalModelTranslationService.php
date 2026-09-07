<?php

declare(strict_types=1);

namespace Weline\I18n\Service\LocalModelTranslation;

use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Api\Localization\LocalModel;
use Weline\I18n\Service\AiTranslationConfig;
use Weline\I18n\Service\I18nAiTranslationAdapter;

final class LocalModelTranslationService
{
    public function __construct(
        private readonly LocalModelTranslationCatalog $catalog,
        private readonly I18nAiTranslationAdapter $translationAdapter,
        private readonly AiTranslationConfig $translationConfig,
    ) {
    }

    /**
     * Collect pending LocalModel field work items.
     * When $limit > 0, stop after collecting $offset + $limit candidates so queue
     * workers do not materialize the entire catalog on every batch.
     *
     * @return list<array{local_model:class-string,local_id_field:string,record_id:int,field:string,source_text:string}>
     */
    public function collectWorkItems(int $offset = 0, int $limit = 0): array
    {
        $offset = max(0, $offset);
        $limit = max(0, $limit);
        $seen = 0;
        $items = [];
        $targetLocales = $this->resolveTargetLocales();

        foreach ($this->catalog->descriptors() as $descriptor) {
            try {
                foreach ($this->iterateParentRows($descriptor) as $parentRow) {
                    $recordId = (int)($parentRow[$descriptor['parent_id_field']] ?? 0);
                    if ($recordId <= 0) {
                        continue;
                    }
                    foreach ($descriptor['fields'] as $field) {
                        $sourceText = $this->resolveSourceText($descriptor, $recordId, $field, $parentRow);
                        if ($sourceText === '') {
                            continue;
                        }
                        if ($targetLocales !== [] && $this->fieldTargetsAlreadyFilled(
                            $descriptor['local_model'],
                            $descriptor['local_id_field'],
                            $recordId,
                            $field,
                            $sourceText,
                            $targetLocales,
                        )) {
                            continue;
                        }
                        if ($seen < $offset) {
                            $seen++;
                            continue;
                        }
                        $items[] = [
                            'local_model' => $descriptor['local_model'],
                            'local_id_field' => $descriptor['local_id_field'],
                            'record_id' => $recordId,
                            'field' => $field,
                            'source_text' => $sourceText,
                        ];
                        if ($limit > 0 && count($items) >= $limit) {
                            return $items;
                        }
                    }
                }
            } catch (\Throwable) {
                // One broken/huge parent table must not abort the whole LocalModel scan.
                continue;
            }
        }

        return $items;
    }

    /**
     * @param list<array{local_model:class-string,local_id_field:string,record_id:int,field:string,source_text:string}> $items
     * @return array{processed:int,translated:int,errors:list<string>}
     */
    public function processBatch(array $items): array
    {
        $processed = 0;
        $translated = 0;
        $errors = [];
        $consumed = 0;
        $abortedBusy = false;
        $sourceLocale = $this->translationConfig->getSourceLocale();
        // Respect AI translation enabled locales (same gate as dictionary cron).
        // Using all installed actives burned Ollama on 16 languages while config
        // only enabled en_US, so batches looked stuck with no storefront en_US writes.
        $targetLocales = $this->resolveTargetLocales();
        if ($targetLocales === []) {
            return [
                'processed' => 0,
                'translated' => 0,
                'consumed' => 0,
                'aborted_busy' => false,
                'errors' => [(string)__('未启用任何 AI 翻译目标语言，已跳过 LocalModel 批次。')],
            ];
        }

        foreach ($items as $item) {
            $localModelClass = (string)($item['local_model'] ?? '');
            $recordId = (int)($item['record_id'] ?? 0);
            $field = (string)($item['field'] ?? '');
            $sourceText = trim((string)($item['source_text'] ?? ''));
            $localIdField = (string)($item['local_id_field'] ?? 'id');
            if ($localModelClass === '' || $recordId <= 0 || $field === '' || $sourceText === '') {
                $consumed++;
                continue;
            }

            try {
                $existingByCode = $this->loadExistingLocalValues($localModelClass, $localIdField, $recordId);
                $sourceStored = trim((string)(($existingByCode[$sourceLocale] ?? [])[$field] ?? ''));
                if ($sourceStored !== $sourceText) {
                    $this->upsertLocalValue($localModelClass, $localIdField, $recordId, $sourceLocale, $field, $sourceText);
                    $existingByCode = $this->loadExistingLocalValues($localModelClass, $localIdField, $recordId);
                }
                $batchTranslated = 0;
                $hitAbort = false;

                foreach ($targetLocales as $localeCode) {
                    $existing = $existingByCode[$localeCode] ?? [];
                    $stored = trim((string)($existing[$field] ?? ''));
                    if ($this->isRealTranslation($stored, $sourceText)) {
                        continue;
                    }

                    $result = $this->translationAdapter->translateBatch(
                        [$sourceText],
                        $sourceLocale,
                        $localeCode,
                        $this->translationConfig->getStrategy($localeCode),
                    );
                    if (!$result['success']) {
                        $itemErrors = array_map('strval', (array)$result['errors']);
                        $errors = array_merge($errors, $itemErrors);
                        // Busy or hard AI error: stop this round; next cron continues.
                        $hitAbort = true;
                        $abortedBusy = $this->errorsIndicateBusy($itemErrors);
                        break;
                    }

                    $translation = trim((string)($result['translations'][$sourceText] ?? ''));
                    if ($translation === '') {
                        continue;
                    }

                    $this->upsertLocalValue($localModelClass, $localIdField, $recordId, $localeCode, $field, $translation);
                    $batchTranslated++;
                }

                if ($hitAbort) {
                    // Do not advance past this item — next cron round retries when free.
                    if (!$abortedBusy) {
                        $abortedBusy = true;
                    }
                    break;
                }

                $processed++;
                $translated += $batchTranslated;
                $consumed++;
            } catch (\Throwable $throwable) {
                $message = $throwable->getMessage();
                $errors[] = $localModelClass . '#' . $recordId . '.' . $field . ': ' . $message;
                // Any hard failure: stop this round; next cron continues.
                $abortedBusy = true;
                break;
            }
        }

        return [
            'processed' => $processed,
            'translated' => $translated,
            'consumed' => $consumed,
            'aborted_busy' => $abortedBusy,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /**
     * @param list<string> $errors
     */
    private function errorsIndicateBusy(array $errors): bool
    {
        foreach ($errors as $error) {
            if (str_contains((string)$error, 'AI_TRANSLATION_BUSY')) {
                return true;
            }
        }

        return false;
    }

    /**
     * AI-enabled target locales for LocalModel batch work (excludes source).
     *
     * @return list<string>
     */
    private function resolveTargetLocales(): array
    {
        return array_values(array_filter(
            $this->translationConfig->getEnabledLocaleCodes(),
            fn(string $locale): bool => $locale !== $this->translationConfig->getSourceLocale(),
        ));
    }

    /**
     * @param class-string<LocalModel> $localModelClass
     * @param list<string> $targetLocales
     */
    private function fieldTargetsAlreadyFilled(
        string $localModelClass,
        string $localIdField,
        int $recordId,
        string $field,
        string $sourceText,
        array $targetLocales,
    ): bool {
        $existingByCode = $this->loadExistingLocalValues($localModelClass, $localIdField, $recordId);
        foreach ($targetLocales as $localeCode) {
            $stored = trim((string)(($existingByCode[$localeCode] ?? [])[$field] ?? ''));
            if (!$this->isRealTranslation($stored, $sourceText)) {
                return false;
            }
        }

        return $targetLocales !== [];
    }

    /**
     * Source text for a Local field:
     * 1) parent/main table same-named column when present (Local may be empty);
     * 2) else source-locale Local row.
     * Target locales are filled by AI from this source — no source-locale Local row required.
     *
     * @param array{local_model:class-string,parent_model:?class-string,local_id_field:string,parent_id_field:string,fields:list<string>} $descriptor
     * @param array<string, mixed> $parentRow
     */
    private function resolveSourceText(array $descriptor, int $recordId, string $field, array $parentRow): string
    {
        $parentModelClass = $descriptor['parent_model'];
        if ($parentModelClass && $this->parentHasField($parentModelClass, $field)) {
            $fromParent = trim((string)($parentRow[$field] ?? ''));
            if ($fromParent !== '') {
                return $fromParent;
            }
        }

        return $this->loadLocalFieldValue(
            $descriptor['local_model'],
            $descriptor['local_id_field'],
            $recordId,
            $field,
            $this->translationConfig->getSourceLocale(),
        );
    }

    /**
     * @param array{local_model:class-string,parent_model:?class-string,local_id_field:string,parent_id_field:string,fields:list<string>} $descriptor
     * @return list<array<string, mixed>>
     * @deprecated Prefer iterateParentRows for large catalogs; kept for callers/tests.
     */
    private function loadParentRows(array $descriptor): array
    {
        return iterator_to_array($this->iterateParentRows($descriptor), false);
    }

    /**
     * Stream parent/main rows in pages so large tables (e.g. shipping regions) stay under unbounded SELECT limits.
     *
     * @param array{local_model:class-string,parent_model:?class-string,local_id_field:string,parent_id_field:string,fields:list<string>} $descriptor
     * @return \Generator<int, array<string, mixed>>
     */
    private function iterateParentRows(array $descriptor): \Generator
    {
        $pageSize = 500;
        $parentModelClass = $descriptor['parent_model'];
        if ($parentModelClass && class_exists($parentModelClass)) {
            $parent = ObjectManager::getInstance($parentModelClass);
            $offset = 0;
            while (true) {
                $rows = $parent->reset()
                    ->limit($pageSize, $offset)
                    ->select()
                    ->fetchArray();
                if (!is_array($rows) || $rows === []) {
                    break;
                }
                foreach ($rows as $row) {
                    if (is_array($row)) {
                        yield $row;
                    }
                }
                if (count($rows) < $pageSize) {
                    break;
                }
                $offset += $pageSize;
            }

            return;
        }

        /** @var LocalModel $localModel */
        $localModel = ObjectManager::getInstance($descriptor['local_model']);
        $offset = 0;
        $seen = [];
        while (true) {
            $rows = $localModel->reset()
                ->fields($descriptor['local_id_field'])
                ->limit($pageSize, $offset)
                ->select()
                ->fetchArray();
            if (!is_array($rows) || $rows === []) {
                break;
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $recordId = (int)($row[$descriptor['local_id_field']] ?? 0);
                if ($recordId <= 0 || isset($seen[$recordId])) {
                    continue;
                }
                $seen[$recordId] = true;
                yield [$descriptor['parent_id_field'] => $recordId];
            }
            if (count($rows) < $pageSize) {
                break;
            }
            $offset += $pageSize;
        }
    }

    /**
     * @param class-string $parentModelClass
     */
    private function parentHasField(string $parentModelClass, string $field): bool
    {
        if (!class_exists($parentModelClass)) {
            return false;
        }

        $parent = ObjectManager::getInstance($parentModelClass);

        return in_array($field, $parent->getModelFields(), true);
    }

    /**
     * @param class-string<LocalModel> $localModelClass
     */
    private function loadLocalFieldValue(
        string $localModelClass,
        string $localIdField,
        int $recordId,
        string $field,
        string $localeCode,
    ): string {
        /** @var LocalModel $model */
        $model = ObjectManager::getInstance($localModelClass);
        $row = $model->reset()
            ->where($localIdField, $recordId)
            ->where($model::schema_fields_local_code, $localeCode)
            ->find()
            ->fetchArray();

        return trim((string)($row[$field] ?? ''));
    }

    /**
     * @param class-string<LocalModel> $localModelClass
     * @return array<string, array<string, mixed>>
     */
    private function loadExistingLocalValues(string $localModelClass, string $localIdField, int $recordId): array
    {
        /** @var LocalModel $model */
        $model = ObjectManager::getInstance($localModelClass);
        $rows = $model->reset()
            ->where($localIdField, $recordId)
            ->select()
            ->fetchArray();
        if (!is_array($rows)) {
            return [];
        }

        $byCode = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = trim((string)($row[$model::schema_fields_local_code] ?? ''));
            if ($code !== '') {
                $byCode[$code] = $row;
            }
        }

        return $byCode;
    }

    private function isRealTranslation(string $stored, string $sourceText): bool
    {
        $stored = trim($stored);
        if ($stored === '') {
            return false;
        }

        $sourceText = trim($sourceText);

        return $sourceText === '' || $stored !== $sourceText;
    }

    /**
     * @param class-string<LocalModel> $localModelClass
     */
    private function upsertLocalValue(
        string $localModelClass,
        string $localIdField,
        int $recordId,
        string $localeCode,
        string $field,
        string $value,
    ): void {
        $localeCode = trim($localeCode);
        if ($localeCode === '' || $recordId <= 0 || $field === '') {
            return;
        }

        /** @var LocalModel $model */
        $model = ObjectManager::getInstance($localModelClass);
        $localeField = $model::schema_fields_local_code;
        $existing = $model->reset()
            ->where($localIdField, $recordId)
            ->where($localeField, $localeCode)
            ->find()
            ->fetch();

        $exists = false;
        if (is_object($existing)) {
            $storedLocale = trim((string)$existing->getData($localeField));
            $storedId = (int)$existing->getData($localIdField);
            $exists = $storedLocale === $localeCode && $storedId === $recordId;
            if (!$exists && method_exists($existing, 'getId') && (int)$existing->getId() > 0) {
                $exists = true;
            }
        }

        if ($exists) {
            $existing->setData($field, $value)->save();

            return;
        }

        $model->clearData()->reset()
            ->setData($localIdField, $recordId)
            ->setData($localeField, $localeCode)
            ->setData($field, $value)
            ->save();
    }
}
