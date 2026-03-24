<?php

declare(strict_types=1);

namespace WeShop\Inventory\Service;

use WeShop\Inventory\Model\Source;

class SourceAdminPageDataService
{
    public function __construct(
        private readonly SourceManagementService $sourceManagementService
    ) {
    }

    public function getListData(int $page = 1, int $pageSize = 20): array
    {
        $result = $this->sourceManagementService->getSourceList($page, $pageSize);

        return [
            'sources' => array_map(
                fn (array $row): array => $this->normalizeSourceRow($row),
                $result['items'] ?? []
            ),
            'pagination' => $result['pagination'] ?? [],
            'emptySource' => $this->sourceManagementService->getEmptySourceData(),
        ];
    }

    public function getEditData(int $sourceId): array
    {
        $source = $this->sourceManagementService->getSourceById($sourceId);
        if (!$source) {
            throw new \InvalidArgumentException((string) __('Inventory source does not exist.'));
        }

        return $this->normalizeSourceModel($source);
    }

    private function normalizeSourceModel(Source $source): array
    {
        return [
            'source_id' => (int) $source->getId(),
            'code' => (string) $source->getData(Source::schema_fields_CODE),
            'name' => (string) $source->getData(Source::schema_fields_NAME),
            'description' => (string) $source->getData(Source::schema_fields_DESCRIPTION),
            'country' => (string) $source->getData(Source::schema_fields_COUNTRY),
            'region' => (string) $source->getData(Source::schema_fields_REGION),
            'city' => (string) $source->getData(Source::schema_fields_CITY),
            'address' => (string) $source->getData(Source::schema_fields_ADDRESS),
            'postcode' => (string) $source->getData(Source::schema_fields_POSTCODE),
            'phone' => (string) $source->getData(Source::schema_fields_PHONE),
            'email' => (string) $source->getData(Source::schema_fields_EMAIL),
            'contact_name' => (string) $source->getData(Source::schema_fields_CONTACT_NAME),
            'is_enabled' => (int) $source->getData(Source::schema_fields_IS_ENABLED),
            'priority' => (int) $source->getData(Source::schema_fields_PRIORITY),
            'use_default_carrier' => (int) $source->getData(Source::schema_fields_USE_DEFAULT_CARRIER),
        ];
    }

    private function normalizeSourceRow(array $row): array
    {
        return [
            'source_id' => (int) ($row[Source::schema_fields_ID] ?? $row['source_id'] ?? 0),
            'code' => (string) ($row[Source::schema_fields_CODE] ?? $row['code'] ?? ''),
            'name' => (string) ($row[Source::schema_fields_NAME] ?? $row['name'] ?? ''),
            'description' => (string) ($row[Source::schema_fields_DESCRIPTION] ?? $row['description'] ?? ''),
            'is_enabled' => (int) ($row[Source::schema_fields_IS_ENABLED] ?? $row['is_enabled'] ?? 0),
            'priority' => (int) ($row[Source::schema_fields_PRIORITY] ?? $row['priority'] ?? 0),
            'country' => (string) ($row[Source::schema_fields_COUNTRY] ?? $row['country'] ?? ''),
            'city' => (string) ($row[Source::schema_fields_CITY] ?? $row['city'] ?? ''),
        ];
    }
}

