<?php

declare(strict_types=1);

namespace Weline\Meta\Service\Repository;

use Weline\Meta\Api\Data\MetaConfigIdentity;
use Weline\Meta\Api\Data\MetaConfigRecord;
use Weline\Meta\Api\Data\MetaConfigSearch;
use Weline\Meta\Api\Data\MetaConfigScopeSearch;
use Weline\Meta\Api\Data\MetaConfigWrite;
use Weline\Meta\Api\MetaConfigRepositoryInterface;
use Weline\Meta\Helper\MetaData;
use Weline\Meta\Model\MetaConfig;

final class MetaConfigRepository implements MetaConfigRepositoryInterface
{
    private const DEFAULT_LOCALE = 'zh_Hans_CN';

    public function __construct(
        private readonly MetaConfig $configs,
    ) {
    }

    public function search(MetaConfigSearch $search): array
    {
        $query = $this->configs->newQuery()
            ->where(MetaConfig::schema_fields_NAMESPACE, trim($search->namespace))
            ->where(MetaConfig::schema_fields_SCOPE, trim($search->scope));

        $this->applyOwnerIdentity(
            $query,
            $search->identifyId,
            $search->metaId,
            $search->metaIdentify,
        );

        if ($search->configKey !== null) {
            $query->where(MetaConfig::schema_fields_CONFIG_KEY, trim($search->configKey));
        } elseif ($search->configKeyPrefix !== null) {
            $query->where(MetaConfig::schema_fields_CONFIG_KEY, $search->configKeyPrefix . '%', 'LIKE');
        }

        if (!$search->allLocales) {
            $this->applyExactLocale($query, $search->locale);
        }

        $rows = $query
            ->order(MetaConfig::schema_fields_CONFIG_KEY, 'ASC')
            ->order(MetaConfig::schema_fields_LOCALE, 'ASC')
            ->order(MetaConfig::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();

        return array_values(array_map(fn(mixed $row): MetaConfigRecord => $this->hydrate($row), $rows));
    }

    public function resolve(MetaConfigIdentity $identity): ?MetaConfigRecord
    {
        return $this->resolveBatch([$identity])[0] ?? null;
    }

    public function resolveBatch(array $identities): array
    {
        if ($identities === []) {
            return [];
        }
        foreach ($identities as $identity) {
            if (!$identity instanceof MetaConfigIdentity) {
                throw new \InvalidArgumentException('resolveBatch accepts only MetaConfigIdentity values.');
            }
        }

        $query = $this->configs->newQuery();
        $this->applyIn($query, MetaConfig::schema_fields_NAMESPACE, array_map(
            static fn(MetaConfigIdentity $identity): string => trim($identity->namespace),
            $identities,
        ));
        $this->applyIn($query, MetaConfig::schema_fields_CONFIG_KEY, array_map(
            static fn(MetaConfigIdentity $identity): string => trim($identity->configKey),
            $identities,
        ));
        $this->applyIn($query, MetaConfig::schema_fields_SCOPE, array_map(
            static fn(MetaConfigIdentity $identity): string => trim($identity->scope),
            $identities,
        ));

        $this->applySharedOwnerConstraint($query, $identities, 'identifyId', MetaConfig::schema_fields_IDENTIFY_ID);
        $this->applySharedOwnerConstraint($query, $identities, 'metaId', MetaConfig::schema_fields_META_ID);
        $this->applySharedOwnerConstraint($query, $identities, 'metaIdentify', MetaConfig::schema_fields_META_IDENTIFY);

        $rows = $query
            ->order(MetaConfig::schema_fields_CONFIG_KEY, 'ASC')
            ->order(MetaConfig::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();

        $candidateIndexes = [];
        foreach ($identities as $index => $identity) {
            $candidateIndexes[$this->contextKey(
                $identity->namespace,
                $identity->configKey,
                $identity->scope,
            )][] = $index;
        }

        $resolved = array_fill(0, count($identities), null);
        $resolvedRanks = array_fill(0, count($identities), PHP_INT_MAX);
        foreach ($rows as $row) {
            $contextKey = $this->contextKey(
                (string)$this->rowValue($row, MetaConfig::schema_fields_NAMESPACE, ''),
                (string)$this->rowValue($row, MetaConfig::schema_fields_CONFIG_KEY, ''),
                (string)$this->rowValue($row, MetaConfig::schema_fields_SCOPE, ''),
            );
            foreach ($candidateIndexes[$contextKey] ?? [] as $index) {
                $identity = $identities[$index];
                if (!$this->ownerMatches($row, $identity)) {
                    continue;
                }

                $rank = $this->localeRank(
                    $this->nullableString($this->rowValue($row, MetaConfig::schema_fields_LOCALE)),
                    $identity->locale,
                );
                if ($rank === null || $rank >= $resolvedRanks[$index]) {
                    continue;
                }

                $resolved[$index] = $this->hydrate($row);
                $resolvedRanks[$index] = $rank;
            }
        }

        return $resolved;
    }

    public function listScopes(MetaConfigScopeSearch $search): array
    {
        $query = $this->configs->newQuery()
            ->where(MetaConfig::schema_fields_NAMESPACE, trim($search->namespace));

        $this->applyOwnerIdentity(
            $query,
            $search->identifyId,
            $search->metaId,
            $search->metaIdentify,
        );

        $rows = $query
            ->group(MetaConfig::schema_fields_SCOPE)
            ->order(MetaConfig::schema_fields_SCOPE, 'ASC')
            ->select(MetaConfig::schema_fields_SCOPE)
            ->fetchArray();

        $scopes = [];
        foreach ($rows as $row) {
            $scope = trim((string)$this->rowValue($row, MetaConfig::schema_fields_SCOPE, ''));
            if ($scope !== '') {
                $scopes[$scope] = true;
            }
        }

        return array_keys($scopes);
    }

    public function upsert(MetaConfigWrite $config): MetaConfigRecord
    {
        $identity = $config->identity;
        $data = [
            MetaConfig::schema_fields_NAMESPACE => trim($identity->namespace),
            MetaConfig::schema_fields_CONFIG_KEY => trim($identity->configKey),
            MetaConfig::schema_fields_CONFIG_VALUE => $config->value,
            MetaConfig::schema_fields_SCOPE => trim($identity->scope),
            MetaConfig::schema_fields_LOCALE => $identity->locale,
        ];
        if ($identity->identifyId !== null && trim($identity->identifyId) !== '') {
            $data[MetaConfig::schema_fields_IDENTIFY_ID] = trim($identity->identifyId);
        }
        if ($identity->metaId !== null) {
            $data[MetaConfig::schema_fields_META_ID] = $identity->metaId;
        }
        if ($identity->metaIdentify !== null && trim($identity->metaIdentify) !== '') {
            $data[MetaConfig::schema_fields_META_IDENTIFY] = trim($identity->metaIdentify);
        }

        $this->runAtomically(function () use ($identity, $data): void {
            $current = $this->findExact($identity);
            if ($current !== null) {
                $this->configs->newQuery()
                    ->where(MetaConfig::schema_fields_ID, $current->id)
                    ->update($data, MetaConfig::schema_fields_ID)
                    ->fetch();
                return;
            }

            $this->configs->newQuery()->insert($data)->fetch();
        });

        MetaData::clearCache();

        $record = $this->findExact($identity);
        if ($record === null) {
            throw new \RuntimeException('Meta config upsert completed without a readable exact-locale record.');
        }

        return $record;
    }

    public function delete(MetaConfigIdentity $identity): bool
    {
        $deleted = $this->runAtomically(function () use ($identity): bool {
            $current = $this->findExact($identity);
            if ($current === null) {
                return false;
            }

            $this->configs->newQuery()
                ->where(MetaConfig::schema_fields_ID, $current->id)
                ->delete()
                ->fetch();
            return true;
        });

        if ($deleted) {
            MetaData::clearCache();
        }
        return $deleted;
    }

    private function findExact(MetaConfigIdentity $identity): ?MetaConfigRecord
    {
        $records = $this->search(new MetaConfigSearch(
            namespace: $identity->namespace,
            scope: $identity->scope,
            configKey: $identity->configKey,
            locale: $identity->locale,
            identifyId: $identity->identifyId,
            metaId: $identity->metaId,
            metaIdentify: $identity->metaIdentify,
        ));

        return $records[0] ?? null;
    }

    private function applyOwnerIdentity(
        mixed $query,
        ?string $identifyId,
        ?int $metaId,
        ?string $metaIdentify,
    ): void {
        if ($identifyId !== null && trim($identifyId) !== '') {
            $query->where(MetaConfig::schema_fields_IDENTIFY_ID, trim($identifyId));
        }
        if ($metaId !== null) {
            $query->where(MetaConfig::schema_fields_META_ID, $metaId);
        }
        if ($metaIdentify !== null && trim($metaIdentify) !== '') {
            $query->where(MetaConfig::schema_fields_META_IDENTIFY, trim($metaIdentify));
        }
    }

    /** @param list<MetaConfigIdentity> $identities */
    private function applySharedOwnerConstraint(
        mixed $query,
        array $identities,
        string $property,
        string $field,
    ): void {
        $values = [];
        foreach ($identities as $identity) {
            $value = $identity->{$property};
            if ($value === null || (is_string($value) && trim($value) === '')) {
                return;
            }
            $values[] = is_string($value) ? trim($value) : $value;
        }
        $this->applyIn($query, $field, $values);
    }

    /** @param list<int|string> $values */
    private function applyIn(mixed $query, string $field, array $values): void
    {
        $values = array_values(array_unique($values, SORT_REGULAR));
        if (count($values) === 1) {
            $query->where($field, $values[0]);
            return;
        }
        $query->where($field, $values, 'IN');
    }

    private function applyExactLocale(mixed $query, ?string $locale): void
    {
        if ($locale === null) {
            $query->where(MetaConfig::schema_fields_LOCALE, null, 'IS NULL');
            return;
        }
        $query->where(MetaConfig::schema_fields_LOCALE, $locale);
    }

    private function ownerMatches(mixed $row, MetaConfigIdentity $identity): bool
    {
        if ($identity->identifyId !== null
            && trim($identity->identifyId) !== ''
            && (string)$this->rowValue($row, MetaConfig::schema_fields_IDENTIFY_ID, '') !== trim($identity->identifyId)
        ) {
            return false;
        }
        if ($identity->metaId !== null
            && (int)$this->rowValue($row, MetaConfig::schema_fields_META_ID, 0) !== $identity->metaId
        ) {
            return false;
        }
        if ($identity->metaIdentify !== null
            && trim($identity->metaIdentify) !== ''
            && (string)$this->rowValue($row, MetaConfig::schema_fields_META_IDENTIFY, '') !== trim($identity->metaIdentify)
        ) {
            return false;
        }

        return true;
    }

    private function localeRank(?string $rowLocale, ?string $requestedLocale): ?int
    {
        $locales = [];
        if ($requestedLocale !== null) {
            $locales[] = $requestedLocale;
        }
        $locales[] = self::DEFAULT_LOCALE;
        $locales[] = null;

        $seen = [];
        $rank = 0;
        foreach ($locales as $locale) {
            $key = $locale === null ? 'null' : 'string:' . $locale;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if ($rowLocale === $locale) {
                return $rank;
            }
            $rank++;
        }

        return null;
    }

    private function contextKey(string $namespace, string $configKey, string $scope): string
    {
        return trim($namespace) . "\0" . trim($configKey) . "\0" . trim($scope);
    }

    private function hydrate(mixed $row): MetaConfigRecord
    {
        $metaId = $this->rowValue($row, MetaConfig::schema_fields_META_ID);
        return new MetaConfigRecord(
            id: (int)$this->rowValue($row, MetaConfig::schema_fields_ID, 0),
            namespace: (string)$this->rowValue($row, MetaConfig::schema_fields_NAMESPACE, ''),
            configKey: (string)$this->rowValue($row, MetaConfig::schema_fields_CONFIG_KEY, ''),
            value: (string)$this->rowValue($row, MetaConfig::schema_fields_CONFIG_VALUE, ''),
            scope: (string)$this->rowValue($row, MetaConfig::schema_fields_SCOPE, ''),
            locale: $this->nullableString($this->rowValue($row, MetaConfig::schema_fields_LOCALE)),
            identifyId: $this->nullableString($this->rowValue($row, MetaConfig::schema_fields_IDENTIFY_ID)),
            metaId: $metaId === null || $metaId === '' ? null : (int)$metaId,
            metaIdentify: $this->nullableString($this->rowValue($row, MetaConfig::schema_fields_META_IDENTIFY)),
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

    private function runAtomically(callable $operation): mixed
    {
        $query = $this->configs->newQuery();
        if (!method_exists($query, 'getConnectionInterface')) {
            throw new \RuntimeException('Meta config repository requires a transaction-aware database connector.');
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
