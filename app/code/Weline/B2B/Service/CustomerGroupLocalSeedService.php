<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\B2B\Model\CustomerGroupRecord;
use Weline\B2B\Model\CustomerGroupRecord\LocalDescription;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Service\LocalModelTranslation\LocalModelTranslationQueueService;

/**
 * 将客户组主表 name/description 回填为源语言 Local，并入队 LocalModel 翻译。
 */
final class CustomerGroupLocalSeedService
{
    private ObjectManager $objectManager;

    public function __construct(?ObjectManager $objectManager = null)
    {
        $this->objectManager = $objectManager ?? ObjectManager::getInstance();
    }

    /**
     * @return array{seeded:int,skipped:int}
     */
    public function seedSourceLocalsAndEnqueue(): array
    {
        $seeded = 0;
        $skipped = 0;
        $locale = $this->resolveSourceLocale();
        /** @var CustomerGroupRecord $groupModel */
        $groupModel = $this->objectManager->getInstance(CustomerGroupRecord::class);
        $rows = $groupModel->clear()->select()->fetchArray();
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $rowId = (int)($row[CustomerGroupRecord::schema_fields_ID] ?? 0);
            if ($rowId <= 0) {
                ++$skipped;
                continue;
            }
            $name = trim((string)($row[CustomerGroupRecord::schema_fields_NAME] ?? ''));
            $description = trim((string)($row[CustomerGroupRecord::schema_fields_DESCRIPTION] ?? ''));
            if ($name === '' && $description === '') {
                ++$skipped;
                continue;
            }
            if ($this->upsertSourceLocal($rowId, $locale, $name, $description)) {
                ++$seeded;
            } else {
                ++$skipped;
            }
        }
        $this->enqueueTranslation();

        return ['seeded' => $seeded, 'skipped' => $skipped];
    }

    public function localizedName(int $groupRowId, string $fallback): string
    {
        $value = $this->localizedField($groupRowId, CustomerGroupRecord::schema_fields_NAME);

        return $value !== '' ? $value : $fallback;
    }

    public function localizedDescription(int $groupRowId, string $fallback): string
    {
        $value = $this->localizedField($groupRowId, CustomerGroupRecord::schema_fields_DESCRIPTION);

        return $value !== '' ? $value : $fallback;
    }

    private function localizedField(int $groupRowId, string $field): string
    {
        if ($groupRowId <= 0) {
            return '';
        }
        $locale = trim((string)Cookie::getLangLocal());
        if ($locale === '') {
            $locale = 'zh_Hans_CN';
        }
        try {
            /** @var LocalDescription $local */
            $local = $this->objectManager->getInstance(LocalDescription::class);
            $items = $local->reset()
                ->where(LocalDescription::schema_fields_ID, $groupRowId)
                ->where(LocalDescription::schema_fields_local_code, $locale)
                ->select()
                ->fetch()
                ->getItems();
            foreach ($items as $item) {
                if ($item instanceof LocalDescription) {
                    $text = trim((string)$item->getData($field));
                    if ($text !== '') {
                        return $text;
                    }
                }
            }
        } catch (\Throwable) {
            // Fall back to main-table value.
        }

        return '';
    }

    private function upsertSourceLocal(int $rowId, string $locale, string $name, string $description): bool
    {
        try {
            /** @var LocalDescription $local */
            $local = $this->objectManager->getInstance(LocalDescription::class);
            $row = [
                LocalDescription::schema_fields_ID => $rowId,
                LocalDescription::schema_fields_local_code => $locale,
                LocalDescription::schema_fields_NAME => $name,
                LocalDescription::schema_fields_DESCRIPTION => $description,
            ];
            $local->reset()->insert(
                [$row],
                LocalDescription::schema_fields_ID . ',local_code',
                LocalDescription::schema_fields_NAME . ',' . LocalDescription::schema_fields_DESCRIPTION,
            )->fetch();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function resolveSourceLocale(): string
    {
        $locale = trim((string)Cookie::getLangLocal());
        if ($locale !== '') {
            return $locale;
        }

        return 'zh_Hans_CN';
    }

    private function enqueueTranslation(): void
    {
        try {
            /** @var LocalModelTranslationQueueService $queue */
            $queue = $this->objectManager->getInstance(LocalModelTranslationQueueService::class);
            $queue->enqueue('Weline_B2B:CustomerGroupLocalSeedService', false);
        } catch (\Throwable) {
            // Queue optional when I18n / AI unavailable.
        }
    }
}
