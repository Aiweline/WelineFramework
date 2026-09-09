<?php

declare(strict_types=1);

namespace Weline\I18n\Service\LocalModelTranslation;

use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Api\Localization\LocalModel;
use Weline\I18n\Service\AiTranslationConfig;
use Weline\I18n\Service\I18nAiTranslationAdapter;

final class LocalModelTranslationService
{
    /**
     * Max source strings per Ollama translateBatch call.
     * LocalModel used to send one string per request (~1.5–2s each); batching
     * amortizes model overhead across a queue batch (default 20 items).
     */
    public const AI_TEXT_CHUNK_SIZE = 20;

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
     * @return array{processed:int,translated:int,consumed:int,aborted_busy:bool,aborted:bool,skipped_persist:int,errors:list<string>}
     */
    public function processBatch(array $items): array
    {
        $processed = 0;
        $translated = 0;
        $errors = [];
        $consumed = 0;
        $abortedBusy = false;
        $aborted = false;
        $skippedPersist = 0;
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
                'aborted' => false,
                'skipped_persist' => 0,
                'errors' => [(string)__('未启用任何 AI 翻译目标语言，已跳过 LocalModel 批次。')],
            ];
        }

        /** @var list<array{local_model:class-string,local_id_field:string,record_id:int,field:string,source_text:string,pending_locales:list<string>}> $prepared */
        $prepared = [];

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
                    try {
                        $this->upsertLocalValue($localModelClass, $localIdField, $recordId, $sourceLocale, $field, $sourceText);
                        $existingByCode = $this->loadExistingLocalValues($localModelClass, $localIdField, $recordId);
                    } catch (\Throwable $sourcePersist) {
                        // Parent/main table already provides source_text; a broken source-locale
                        // Local row must not block target AI translation (prod EAV 25P02 loop).
                        $this->recoverLocalModelConnection($localModelClass);
                        $errors[] = $localModelClass . '#' . $recordId . '.' . $field
                            . ' (source): ' . $sourcePersist->getMessage();
                        $skippedPersist++;
                    }
                }

                $pendingLocales = [];
                foreach ($targetLocales as $localeCode) {
                    $existing = $existingByCode[$localeCode] ?? [];
                    $stored = trim((string)($existing[$field] ?? ''));
                    if ($this->isRealTranslation($stored, $sourceText)) {
                        continue;
                    }
                    $pendingLocales[] = $localeCode;
                }

                if ($pendingLocales === []) {
                    $processed++;
                    $consumed++;
                    continue;
                }

                $prepared[] = [
                    'local_model' => $localModelClass,
                    'local_id_field' => $localIdField,
                    'record_id' => $recordId,
                    'field' => $field,
                    'source_text' => $sourceText,
                    'pending_locales' => $pendingLocales,
                ];
            } catch (\Throwable $throwable) {
                // Load/scan poison: skip item and keep batch moving (never aborted_busy).
                $this->recoverLocalModelConnection($localModelClass);
                $errors[] = $localModelClass . '#' . $recordId . '.' . $field . ': ' . $throwable->getMessage();
                $skippedPersist++;
                $consumed++;
                continue;
            }
        }

        if ($abortedBusy || $prepared === []) {
            return [
                'processed' => $processed,
                'translated' => $translated,
                'consumed' => $consumed,
                'aborted_busy' => $abortedBusy,
                'aborted' => $aborted,
                'skipped_persist' => $skippedPersist,
                'errors' => array_values(array_unique($errors)),
            ];
        }

        // Group by target locale and call translateBatch with many source strings
        // (dictionary AI already does this; LocalModel previously sent one string each).
        $remainingByIndex = [];
        foreach ($prepared as $index => $row) {
            $remainingByIndex[$index] = array_fill_keys($row['pending_locales'], true);
        }

        foreach ($targetLocales as $localeCode) {
            /** @var array<string, list<int>> $indexesByText */
            $indexesByText = [];
            foreach ($prepared as $index => $row) {
                if (!isset($remainingByIndex[$index][$localeCode])) {
                    continue;
                }
                $indexesByText[$row['source_text']][] = $index;
            }
            if ($indexesByText === []) {
                continue;
            }

            $strategy = $this->translationConfig->getStrategy($localeCode);
            foreach (array_chunk(array_keys($indexesByText), self::AI_TEXT_CHUNK_SIZE) as $chunk) {
                try {
                    $result = $this->translationAdapter->translateBatch(
                        $chunk,
                        $sourceLocale,
                        $localeCode,
                        $strategy,
                        'local-model',
                    );
                } catch (\Throwable $throwable) {
                    $message = $throwable->getMessage();
                    $errors[] = $message;
                    if ($this->errorsIndicateBusy([$message])) {
                        $abortedBusy = true;
                    } else {
                        $aborted = true;
                    }
                    break 2;
                }

                if (!$result['success']) {
                    $itemErrors = array_map('strval', (array)$result['errors']);
                    $errors = array_merge($errors, $itemErrors);
                    $abortedBusy = $this->errorsIndicateBusy($itemErrors);
                    if (!$abortedBusy) {
                        $aborted = true;
                    }
                    break 2;
                }

                foreach ($chunk as $sourceText) {
                    $translation = trim((string)($result['translations'][$sourceText] ?? ''));
                    if ($translation === '') {
                        continue;
                    }
                    foreach ($indexesByText[$sourceText] ?? [] as $index) {
                        if (!isset($remainingByIndex[$index][$localeCode])) {
                            continue;
                        }
                        $row = $prepared[$index];
                        try {
                            $this->upsertLocalValue(
                                $row['local_model'],
                                $row['local_id_field'],
                                $row['record_id'],
                                $localeCode,
                                $row['field'],
                                $translation,
                            );
                            unset($remainingByIndex[$index][$localeCode]);
                            $translated++;
                        } catch (\Throwable $throwable) {
                            $this->recoverLocalModelConnection($row['local_model']);
                            $errors[] = $row['local_model'] . '#' . $row['record_id'] . '.' . $row['field']
                                . ': ' . $throwable->getMessage();
                            $skippedPersist++;
                            // Drop this locale so the item can finish/consume; keep batch moving.
                            unset($remainingByIndex[$index][$localeCode]);
                        }
                    }
                }
            }
        }

        foreach ($prepared as $index => $row) {
            if (($remainingByIndex[$index] ?? []) === []) {
                $processed++;
                $consumed++;
            } else {
                // Partial / aborted: do not advance past first incomplete item.
                break;
            }
        }

        return [
            'processed' => $processed,
            'translated' => $translated,
            'consumed' => $consumed,
            'aborted_busy' => $abortedBusy,
            'aborted' => $aborted,
            'skipped_persist' => $skippedPersist,
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
        if ($sourceText === '') {
            return true;
        }

        if ($stored !== $sourceText) {
            return true;
        }

        // Identical non-CJK source/target (e.g. English entity name) is already acceptable;
        // identical CJK means the target locale was never translated.
        return !preg_match('/[\x{4e00}-\x{9fff}]/u', $sourceText);
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

        try {
            $this->writeLocalValueOnce($localModelClass, $localIdField, $recordId, $localeCode, $field, $value);
        } catch (\Throwable $first) {
            if (!$this->isRecoverablePersistError($first)) {
                throw $first;
            }
            $this->recoverLocalModelConnection($localModelClass);
            $this->retryUpsertLocalValue($localModelClass, $localIdField, $recordId, $localeCode, $field, $value);
        }
    }

    /**
     * @param class-string<LocalModel> $localModelClass
     */
    private function writeLocalValueOnce(
        string $localModelClass,
        string $localIdField,
        int $recordId,
        string $localeCode,
        string $field,
        string $value,
    ): void {
        $this->ensureLocalModelConnectionHealthy($localModelClass);

        /** @var LocalModel $model */
        $model = ObjectManager::getInstance($localModelClass);
        $localeField = $model::schema_fields_local_code;
        $row = $model->clearData()->reset()
            ->where($localIdField, $recordId)
            ->where($localeField, $localeCode)
            ->find()
            ->fetchArray();

        $exists = is_array($row)
            && (int)($row[$localIdField] ?? 0) === $recordId
            && trim((string)($row[$localeField] ?? '')) === $localeCode;

        if ($exists) {
            $model->clearData()->reset()
                ->setData($localIdField, $recordId)
                ->setData($localeField, $localeCode)
                ->setData($field, $value)
                ->forceCheck(true)
                ->save();

            return;
        }

        $model->clearData()->reset()
            ->setData($localIdField, $recordId)
            ->setData($localeField, $localeCode)
            ->setData($field, $value)
            ->forceCheck(true)
            ->save();
    }

    /**
     * After unique conflict / aborted txn: re-find and prefer UPDATE.
     *
     * @param class-string<LocalModel> $localModelClass
     */
    private function retryUpsertLocalValue(
        string $localModelClass,
        string $localIdField,
        int $recordId,
        string $localeCode,
        string $field,
        string $value,
    ): void {
        $this->ensureLocalModelConnectionHealthy($localModelClass);
        $this->writeLocalValueOnce($localModelClass, $localIdField, $recordId, $localeCode, $field, $value);
    }

    /**
     * @param class-string<LocalModel>|string $localModelClass
     */
    private function ensureLocalModelConnectionHealthy(string $localModelClass): void
    {
        if ($localModelClass === '' || !class_exists($localModelClass)) {
            return;
        }

        try {
            /** @var LocalModel $model */
            $model = ObjectManager::getInstance($localModelClass);
            $model->clearData()->reset()->limit(1)->select()->fetchArray();
        } catch (\Throwable $throwable) {
            if ($this->isRecoverablePersistError($throwable)) {
                $this->recoverLocalModelConnection($localModelClass);
            }
        }
    }

    /**
     * @param class-string<LocalModel>|string $localModelClass
     */
    private function recoverLocalModelConnection(string $localModelClass): void
    {
        if ($localModelClass === '' || !class_exists($localModelClass)) {
            return;
        }

        try {
            /** @var LocalModel $model */
            $model = ObjectManager::getInstance($localModelClass);
            $query = $model->getQuery(false);
            try {
                $query->rollBack();
            } catch (\Throwable) {
            }
            // AbstractModel may skip coordinator rollback when transactionState is null,
            // leaving PostgreSQL in 25P02; force a physical ROLLBACK on the PDO link.
            try {
                if (method_exists($query, 'getLink')) {
                    $link = $query->getLink();
                    if ($link instanceof \PDO) {
                        try {
                            if ($link->inTransaction()) {
                                $link->rollBack();
                            }
                        } catch (\Throwable) {
                        }
                        try {
                            $link->exec('ROLLBACK');
                        } catch (\Throwable) {
                        }
                    }
                }
            } catch (\Throwable) {
            }
        } catch (\Throwable) {
            // Connection may already be clean or outside a transaction.
        }
    }

    private function isRecoverablePersistError(\Throwable $throwable): bool
    {
        $message = $throwable->getMessage();
        $code = (string)$throwable->getCode();

        return $code === '25P02'
            || str_contains($code, '25P02')
            || str_contains($message, '25P02')
            || str_contains($message, 'current transaction is aborted')
            || str_contains($message, 'In failed sql transaction')
            || str_contains($message, 'Unique violation')
            || str_contains($message, 'duplicate key')
            || str_contains($message, '23505');
    }
}
