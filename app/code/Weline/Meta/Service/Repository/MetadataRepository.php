<?php

declare(strict_types=1);

namespace Weline\Meta\Service\Repository;

use Weline\Meta\Api\Data\MetadataIdentity;
use Weline\Meta\Api\Data\MetadataRecord;
use Weline\Meta\Api\Data\MetadataSearch;
use Weline\Meta\Api\Data\MetadataWrite;
use Weline\Meta\Api\MetadataRepositoryInterface;
use Weline\Meta\Helper\MetaData;
use Weline\Meta\Model\Meta;

final class MetadataRepository implements MetadataRepositoryInterface
{
    public function __construct(
        private readonly Meta $metadata,
    ) {
    }

    public function search(MetadataSearch $search): array
    {
        $query = $this->metadata->newQuery()
            ->where(Meta::schema_fields_NAMESPACE, trim($search->namespace));

        if ($search->type !== null) {
            $query->where(Meta::schema_fields_META_TYPE, trim($search->type));
        }
        if ($search->identify !== null) {
            $query->where(Meta::schema_fields_META_IDENTIFY, trim($search->identify));
        } elseif ($search->identifyPrefix !== null) {
            $query->where(Meta::schema_fields_META_IDENTIFY, $search->identifyPrefix . '%', 'LIKE');
        }
        if ($search->area !== null) {
            $query->where(Meta::schema_fields_AREA, trim($search->area));
        }
        if ($search->category !== null) {
            $query->where(Meta::schema_fields_CATEGORY, trim($search->category));
        }
        if ($search->filePath !== null) {
            $query->where(Meta::schema_fields_FILE_PATH, $search->filePath);
        }

        $rows = $query
            ->order(Meta::schema_fields_META_IDENTIFY, 'ASC')
            ->select()
            ->fetchArray();

        $records = [];
        foreach ($rows as $row) {
            $records[] = $this->hydrate($row);
        }

        return $records;
    }

    public function resolve(MetadataIdentity $identity): ?MetadataRecord
    {
        $records = $this->search(new MetadataSearch(
            namespace: $identity->namespace,
            type: $identity->type,
            identify: $identity->identify,
        ));

        return $records[0] ?? null;
    }

    public function upsert(MetadataWrite $metadata): MetadataRecord
    {
        return $this->upsertBatch([$metadata])[0];
    }

    public function upsertBatch(array $metadata): array
    {
        if ($metadata === []) {
            return [];
        }

        $writesByKey = [];
        $groups = [];
        $inputKeys = [];
        foreach ($metadata as $write) {
            if (!$write instanceof MetadataWrite) {
                throw new \InvalidArgumentException('upsertBatch accepts only MetadataWrite values.');
            }
            $key = $this->identityKey($write->identity);
            $group = $this->groupKey($write->identity->namespace, $write->identity->type);
            $writesByKey[$key] = $write;
            $groups[$group] = [$write->identity->namespace, $write->identity->type];
            $inputKeys[] = $key;
        }

        $existing = [];
        foreach ($groups as [$namespace, $type]) {
            foreach ($this->search(new MetadataSearch(namespace: $namespace, type: $type)) as $record) {
                $existing[$this->recordKey($record)] = $record;
            }
        }

        $this->runAtomically(function () use ($writesByKey, $existing): void {
            foreach ($writesByKey as $key => $write) {
                $data = $this->writeData($write);
                if (isset($existing[$key])) {
                    $this->metadata->newQuery()
                        ->where(Meta::schema_fields_ID, $existing[$key]->id)
                        ->update($data, Meta::schema_fields_ID)
                        ->fetch();
                    continue;
                }

                $this->metadata->newQuery()->insert($data)->fetch();
            }
        });

        MetaData::clearCache();

        $stored = [];
        foreach ($groups as [$namespace, $type]) {
            foreach ($this->search(new MetadataSearch(namespace: $namespace, type: $type)) as $record) {
                $stored[$this->recordKey($record)] = $record;
            }
        }

        $result = [];
        foreach ($inputKeys as $key) {
            if (!isset($stored[$key])) {
                throw new \RuntimeException('Metadata batch upsert completed without a readable record.');
            }
            $result[] = $stored[$key];
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function writeData(MetadataWrite $metadata): array
    {
        $identity = $metadata->identity;
        return [
            Meta::schema_fields_NAMESPACE => trim($identity->namespace),
            Meta::schema_fields_META_TYPE => trim($identity->type),
            Meta::schema_fields_META_IDENTIFY => trim($identity->identify),
            Meta::schema_fields_FILE_PATH => $metadata->filePath,
            Meta::schema_fields_FILE_FULL_PATH => $metadata->fileFullPath,
            Meta::schema_fields_AREA => $metadata->area,
            Meta::schema_fields_CATEGORY => $metadata->category,
            Meta::schema_fields_META_DATA => json_encode(
                $metadata->metaData,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
            Meta::schema_fields_SETTING => json_encode(
                $metadata->setting,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
        ];
    }

    private function identityKey(MetadataIdentity $identity): string
    {
        return $this->groupKey($identity->namespace, $identity->type) . "\0" . trim($identity->identify);
    }

    private function recordKey(MetadataRecord $record): string
    {
        return $this->groupKey($record->namespace, $record->type) . "\0" . trim($record->identify);
    }

    private function groupKey(string $namespace, string $type): string
    {
        return trim($namespace) . "\0" . trim($type);
    }

    public function delete(MetadataIdentity $identity): bool
    {
        $deleted = $this->runAtomically(function () use ($identity): bool {
            $current = $this->resolve($identity);
            if ($current === null) {
                return false;
            }

            $this->metadata->newQuery()
                ->where(Meta::schema_fields_ID, $current->id)
                ->delete()
                ->fetch();
            return true;
        });

        if ($deleted) {
            MetaData::clearCache();
        }
        return $deleted;
    }

    private function hydrate(mixed $row): MetadataRecord
    {
        return new MetadataRecord(
            id: (int)$this->rowValue($row, Meta::schema_fields_ID, 0),
            namespace: (string)$this->rowValue($row, Meta::schema_fields_NAMESPACE, ''),
            type: (string)$this->rowValue($row, Meta::schema_fields_META_TYPE, ''),
            identify: (string)$this->rowValue($row, Meta::schema_fields_META_IDENTIFY, ''),
            filePath: $this->nullableString($this->rowValue($row, Meta::schema_fields_FILE_PATH)),
            fileFullPath: $this->nullableString($this->rowValue($row, Meta::schema_fields_FILE_FULL_PATH)),
            area: $this->nullableString($this->rowValue($row, Meta::schema_fields_AREA)),
            category: $this->nullableString($this->rowValue($row, Meta::schema_fields_CATEGORY)),
            metaData: $this->decodeObject($this->rowValue($row, Meta::schema_fields_META_DATA)),
            setting: $this->decodeObject($this->rowValue($row, Meta::schema_fields_SETTING)),
        );
    }

    private function rowValue(mixed $row, string $field, mixed $default = null): mixed
    {
        if (is_array($row)) {
            return array_key_exists($field, $row) ? $row[$field] : $default;
        }
        if (is_object($row) && method_exists($row, 'getData')) {
            $value = $row->getData($field);
            return $value !== null ? $value : $default;
        }

        return $default;
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string)$value;
    }

    /** @return array<string, mixed> */
    private function decodeObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function runAtomically(callable $operation): mixed
    {
        $query = $this->metadata->newQuery();
        if (!method_exists($query, 'getConnectionInterface')) {
            throw new \RuntimeException('Metadata repository requires a transaction-aware database connector.');
        }

        $connection = $query->getConnectionInterface();
        $ownsTransaction = !$connection->inTransaction();
        if ($ownsTransaction) {
            $connection->beginTransaction();
        }

        try {
            $result = $operation();
            if ($ownsTransaction) {
                $connection->commit();
            }
            return $result;
        } catch (\Throwable $throwable) {
            if ($ownsTransaction && $connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $throwable;
        }
    }
}
