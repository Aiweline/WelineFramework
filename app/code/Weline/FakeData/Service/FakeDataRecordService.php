<?php

declare(strict_types=1);

namespace Weline\FakeData\Service;

use Weline\FakeData\Model\FakeDataRecord;

class FakeDataRecordService
{
    public function __construct(
        private readonly FakeDataRecord $record,
    ) {
    }

    public function record(string $providerCode, string $entityType, int|string $entityId, string $stableKey, array $meta = []): void
    {
        $now = date('Y-m-d H:i:s');
        $existing = $this->record->clear()
            ->where(FakeDataRecord::schema_fields_PROVIDER_CODE, $providerCode)
            ->where(FakeDataRecord::schema_fields_STABLE_KEY, $stableKey)
            ->find()
            ->fetch();
        $createdAt = is_array($existing) && !empty($existing[FakeDataRecord::schema_fields_CREATED_AT])
            ? (string)$existing[FakeDataRecord::schema_fields_CREATED_AT]
            : $now;

        $this->record->clear()
            ->setData([
                FakeDataRecord::schema_fields_PROVIDER_CODE => $providerCode,
                FakeDataRecord::schema_fields_ENTITY_TYPE => $entityType,
                FakeDataRecord::schema_fields_ENTITY_ID => (string)$entityId,
                FakeDataRecord::schema_fields_STABLE_KEY => $stableKey,
                FakeDataRecord::schema_fields_META_JSON => json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                FakeDataRecord::schema_fields_CREATED_AT => $createdAt,
                FakeDataRecord::schema_fields_UPDATED_AT => $now,
            ])
            ->forceCheck(true, [
                FakeDataRecord::schema_fields_PROVIDER_CODE,
                FakeDataRecord::schema_fields_STABLE_KEY,
            ])
            ->save();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecords(string $providerCode, ?string $entityType = null): array
    {
        $query = $this->record->clear()
            ->where(FakeDataRecord::schema_fields_PROVIDER_CODE, $providerCode);
        if ($entityType !== null) {
            $query->where(FakeDataRecord::schema_fields_ENTITY_TYPE, $entityType);
        }
        return $query->select()->fetchArray() ?: [];
    }

    public function removeRecord(string $providerCode, string $stableKey): void
    {
        $this->record->clear()
            ->where(FakeDataRecord::schema_fields_PROVIDER_CODE, $providerCode)
            ->where(FakeDataRecord::schema_fields_STABLE_KEY, $stableKey)
            ->delete()
            ->fetch();
    }

    public function removeProviderRecords(string $providerCode): void
    {
        $this->record->clear()
            ->where(FakeDataRecord::schema_fields_PROVIDER_CODE, $providerCode)
            ->delete()
            ->fetch();
    }
}

