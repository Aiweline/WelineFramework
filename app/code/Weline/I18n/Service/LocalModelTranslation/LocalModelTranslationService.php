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
     * @return list<array{local_model:class-string,local_id_field:string,record_id:int,field:string,source_text:string}>
     */
    public function collectWorkItems(): array
    {
        $items = [];
        foreach ($this->catalog->descriptors() as $descriptor) {
            foreach ($this->loadParentRows($descriptor) as $parentRow) {
                $recordId = (int)($parentRow[$descriptor['parent_id_field']] ?? 0);
                if ($recordId <= 0) {
                    continue;
                }
                foreach ($descriptor['fields'] as $field) {
                    $sourceText = $this->resolveSourceText($descriptor, $recordId, $field, $parentRow);
                    if ($sourceText === '') {
                        continue;
                    }
                    $items[] = [
                        'local_model' => $descriptor['local_model'],
                        'local_id_field' => $descriptor['local_id_field'],
                        'record_id' => $recordId,
                        'field' => $field,
                        'source_text' => $sourceText,
                    ];
                }
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
        $sourceLocale = $this->translationConfig->getSourceLocale();
        $targetLocales = array_values(array_filter(
            $this->translationConfig->getInstalledActiveLocaleCodes(),
            static fn(string $locale): bool => $locale !== $sourceLocale,
        ));

        foreach ($items as $item) {
            $localModelClass = (string)($item['local_model'] ?? '');
            $recordId = (int)($item['record_id'] ?? 0);
            $field = (string)($item['field'] ?? '');
            $sourceText = trim((string)($item['source_text'] ?? ''));
            $localIdField = (string)($item['local_id_field'] ?? 'id');
            if ($localModelClass === '' || $recordId <= 0 || $field === '' || $sourceText === '') {
                continue;
            }

            try {
                $this->upsertLocalValue($localModelClass, $localIdField, $recordId, $sourceLocale, $field, $sourceText);
                $existingByCode = $this->loadExistingLocalValues($localModelClass, $localIdField, $recordId);
                $batchTranslated = 0;

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
                        $errors = array_merge($errors, array_map('strval', (array)$result['errors']));
                        continue;
                    }

                    $translation = trim((string)($result['translations'][$sourceText] ?? ''));
                    if ($translation === '') {
                        continue;
                    }

                    $this->upsertLocalValue($localModelClass, $localIdField, $recordId, $localeCode, $field, $translation);
                    $batchTranslated++;
                }

                $processed++;
                $translated += $batchTranslated;
            } catch (\Throwable $throwable) {
                $processed++;
                $errors[] = $localModelClass . '#' . $recordId . '.' . $field . ': ' . $throwable->getMessage();
            }
        }

        return [
            'processed' => $processed,
            'translated' => $translated,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /**
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
     */
    private function loadParentRows(array $descriptor): array
    {
        $parentModelClass = $descriptor['parent_model'];
        if ($parentModelClass && class_exists($parentModelClass)) {
            $parent = ObjectManager::getInstance($parentModelClass);
            $rows = $parent->reset()->select()->fetchArray();

            return is_array($rows) ? $rows : [];
        }

        /** @var LocalModel $localModel */
        $localModel = ObjectManager::getInstance($descriptor['local_model']);
        $rows = $localModel->reset()
            ->fields($descriptor['local_id_field'])
            ->select()
            ->fetchArray();
        if (!is_array($rows)) {
            return [];
        }

        $seen = [];
        $parentRows = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $recordId = (int)($row[$descriptor['local_id_field']] ?? 0);
            if ($recordId <= 0 || isset($seen[$recordId])) {
                continue;
            }
            $seen[$recordId] = true;
            $parentRows[] = [$descriptor['parent_id_field'] => $recordId];
        }

        return $parentRows;
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
        if ($localeCode === '' || $recordId <= 0) {
            return;
        }

        /** @var LocalModel $model */
        $model = ObjectManager::getInstance($localModelClass);
        $model->reset()->insert([
            [
                $localIdField => $recordId,
                $model::schema_fields_local_code => $localeCode,
                $field => $value,
            ],
        ], $localIdField . ',local_code', $field)->fetch();
    }
}
