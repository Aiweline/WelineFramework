<?php

declare(strict_types=1);

namespace Weline\Eav\Service\LocalTranslation;

use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Group;
use Weline\Eav\Model\EavAttribute\Option;
use Weline\Eav\Model\EavAttribute\Set;
use Weline\Eav\Model\EavEntity;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Http\Cookie;
use Weline\I18n\Api\Localization\LocalModel;
use Weline\I18n\Service\AiTranslationConfig;
use Weline\I18n\Service\I18nAiTranslationAdapter;

final class EavLocalTranslationService
{
    public function __construct(
        private readonly I18nAiTranslationAdapter $translationAdapter,
        private readonly AiTranslationConfig $translationConfig,
        private readonly EavEntity $eavEntity,
        private readonly Set $eavSet,
        private readonly Group $eavGroup,
        private readonly EavAttribute $eavAttribute,
        private readonly Option $eavOption,
    ) {
    }

    /**
     * @return array{translated:int,locales:list<string>,errors:list<string>}
     */
    public function aiTranslateNode(string $nodeType, int $id, bool $retranslateAll = false): array
    {
        $nodeType = strtolower(trim($nodeType));
        $id = max(0, $id);
        if ($id <= 0) {
            throw new \InvalidArgumentException(__('缺少记录 ID'));
        }

        $descriptor = EavLocalTranslationRegistry::resolve($nodeType);
        $sourceText = trim($this->resolveSourceText($nodeType, $id));
        if ($sourceText === '') {
            throw new \InvalidArgumentException(__('源文本为空，无法翻译'));
        }

        $this->syncSourceLocale($nodeType, $id, $sourceText);

        return $this->translateTextToLocales($descriptor, $id, $sourceText, $retranslateAll);
    }

    public function syncSourceLocale(string $nodeType, int $id, string $text): void
    {
        $descriptor = EavLocalTranslationRegistry::resolve($nodeType);
        $sourceLocale = $this->translationConfig->getSourceLocale();
        $this->upsertLocalValue($descriptor, $id, $sourceLocale, $text);
    }

    public function getCurrentLocale(): string
    {
        return trim(Cookie::getLang());
    }

    public function isSourceLocale(?string $localeCode = null): bool
    {
        $localeCode = trim($localeCode ?? $this->getCurrentLocale());
        if ($localeCode === '') {
            return true;
        }

        return $localeCode === $this->translationConfig->getSourceLocale();
    }

    public function saveLocalizedField(string $nodeType, int $id, string $text, ?string $localeCode = null): void
    {
        $localeCode = trim($localeCode ?? $this->getCurrentLocale());
        if ($localeCode === '' || $id <= 0) {
            return;
        }

        $descriptor = EavLocalTranslationRegistry::resolve($nodeType);
        $this->upsertLocalValue($descriptor, $id, $localeCode, trim($text));
    }

    /**
     * @return list<array{type:string,id:int}>
     */
    public function collectEntityWorkItems(int $entityId, bool $includeOptions = true): array
    {
        $entityId = max(0, $entityId);
        if ($entityId <= 0) {
            return [];
        }

        $items = [];
        $sets = clone $this->eavSet;
        foreach ($sets->where(Set::schema_fields_eav_entity_id, $entityId)->select()->fetchArray() as $row) {
            $items[] = ['type' => 'set', 'id' => (int)($row[Set::schema_fields_ID] ?? 0)];
        }

        $groups = clone $this->eavGroup;
        foreach ($groups->where(Group::schema_fields_eav_entity_id, $entityId)->select()->fetchArray() as $row) {
            $items[] = ['type' => 'group', 'id' => (int)($row[Group::schema_fields_ID] ?? 0)];
        }

        $attributes = clone $this->eavAttribute;
        foreach ($attributes->where(EavAttribute::schema_fields_eav_entity_id, $entityId)->select()->fetchArray() as $row) {
            $items[] = ['type' => 'attribute', 'id' => (int)($row[EavAttribute::schema_fields_ID] ?? 0)];
        }

        if ($includeOptions) {
            $options = clone $this->eavOption;
            foreach ($options->where(Option::schema_fields_eav_entity_id, $entityId)->select()->fetchArray() as $row) {
                $items[] = ['type' => 'option', 'id' => (int)($row[Option::schema_fields_ID] ?? 0)];
            }
        }

        return array_values(array_filter(
            $items,
            static fn(array $item): bool => $item['id'] > 0,
        ));
    }

    /**
     * @param list<array{type:string,id:int}> $items
     * @return array{processed:int,translated:int,errors:list<string>}
     */
    public function processBatch(array $items): array
    {
        $processed = 0;
        $translated = 0;
        $errors = [];

        foreach ($items as $item) {
            $type = (string)($item['type'] ?? '');
            $id = (int)($item['id'] ?? 0);
            if ($id <= 0 || $type === '') {
                continue;
            }

            try {
                $result = $this->aiTranslateNode($type, $id);
                $processed++;
                $translated += (int)($result['translated'] ?? 0);
                if (!empty($result['errors'])) {
                    $errors = array_merge($errors, array_map('strval', (array)$result['errors']));
                }
            } catch (\Throwable $throwable) {
                $processed++;
                $errors[] = $type . '#' . $id . ': ' . $throwable->getMessage();
            }
        }

        return [
            'processed' => $processed,
            'translated' => $translated,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    private function resolveSourceText(string $nodeType, int $id): string
    {
        return match ($nodeType) {
            'entity' => $this->loadEntityName($id),
            'set' => $this->loadSetName($id),
            'group' => $this->loadGroupName($id),
            'attribute' => $this->loadAttributeName($id),
            'option' => $this->loadOptionValue($id),
            default => '',
        };
    }

    private function loadEntityName(int $id): string
    {
        $model = clone $this->eavEntity;
        $model->load($id);

        return (string)$model->getName();
    }

    private function loadSetName(int $id): string
    {
        $model = clone $this->eavSet;
        $model->load($id);

        return (string)$model->getName();
    }

    private function loadGroupName(int $id): string
    {
        $model = clone $this->eavGroup;
        $model->load($id);

        return (string)$model->getName();
    }

    private function loadAttributeName(int $id): string
    {
        $model = clone $this->eavAttribute;
        $model->load($id);

        return (string)$model->getName();
    }

    private function loadOptionValue(int $id): string
    {
        $model = clone $this->eavOption;
        $model->load($id);

        return (string)$model->getData(Option::schema_fields_value);
    }

    /**
     * @param array{model:class-string,field:string,id_field:string} $descriptor
     * @return array{translated:int,locales:list<string>,errors:list<string>}
     */
    private function translateTextToLocales(array $descriptor, int $id, string $sourceText, bool $retranslateAll = false): array
    {
        $sourceLocale = $this->translationConfig->getSourceLocale();
        $targetLocales = array_values(array_filter(
            $this->translationConfig->getInstalledActiveLocaleCodes(),
            static fn(string $locale): bool => $locale !== $sourceLocale,
        ));
        $existingByCode = $this->loadExistingLocalValues($descriptor, $id);
        $field = (string)$descriptor['field'];

        $translated = 0;
        $skipped = 0;
        $locales = [];
        $errors = [];

        foreach ($targetLocales as $localeCode) {
            $existing = $existingByCode[$localeCode] ?? [];
            $stored = trim((string)($existing[$field] ?? ''));
            if (!$retranslateAll && $this->isRealTranslation($stored, $sourceText)) {
                $skipped++;
                continue;
            }

            $result = $this->translationAdapter->translateBatch(
                [$sourceText],
                $sourceLocale,
                $localeCode,
                $this->translationConfig->getStrategy($localeCode),
            );
            if (!$result['success']) {
                $errors = array_merge($errors, $result['errors']);
                continue;
            }

            $translation = trim((string)($result['translations'][$sourceText] ?? ''));
            if ($translation === '') {
                continue;
            }

            $this->upsertLocalValue($descriptor, $id, $localeCode, $translation);
            $translated++;
            $locales[] = $localeCode;
        }

        return [
            'translated' => $translated,
            'skipped' => $skipped,
            'locales' => $locales,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /**
     * @param array{model:class-string,field:string,id_field:string} $descriptor
     * @return array<string, array<string, mixed>>
     */
    private function loadExistingLocalValues(array $descriptor, int $id): array
    {
        if ($id <= 0) {
            return [];
        }

        /** @var LocalModel $model */
        $model = ObjectManager::getInstance($descriptor['model']);
        $idField = (string)$descriptor['id_field'];
        $rows = $model->reset()
            ->where($idField, $id)
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
     * @param array{model:class-string,field:string,id_field:string} $descriptor
     */
    private function upsertLocalValue(array $descriptor, int $id, string $localeCode, string $value): void
    {
        $localeCode = trim($localeCode);
        if ($localeCode === '' || $id <= 0) {
            return;
        }

        /** @var LocalModel $model */
        $model = ObjectManager::getInstance($descriptor['model']);
        $field = $descriptor['field'];
        $idField = $descriptor['id_field'];

        $model->reset()->insert([
            [
                $idField => $id,
                $model::schema_fields_local_code => $localeCode,
                $field => $value,
            ],
        ], $idField . ',local_code', $field)->fetch();
    }
}
