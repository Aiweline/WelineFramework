<?php

declare(strict_types=1);

namespace Weline\Meta\Service\Repository;

use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
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
    private const MAX_TRANSACTION_ATTEMPTS = 8;

    public function __construct(
        private readonly MetaConfig $configs,
        private readonly WriteIntentTransactionCoordinatorInterface $transactions,
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

        $rows = array_values(array_filter(
            is_array($rows) ? $rows : [],
            fn(mixed $row): bool => $this->searchRowMatches($row, $search),
        ));
        usort($rows, function (mixed $left, mixed $right): int {
            $key = strcmp(
                (string)$this->rowValue($left, MetaConfig::schema_fields_CONFIG_KEY, ''),
                (string)$this->rowValue($right, MetaConfig::schema_fields_CONFIG_KEY, ''),
            );
            if ($key !== 0) {
                return $key;
            }
            $locale = strcmp(
                (string)$this->rowValue($left, MetaConfig::schema_fields_LOCALE, ''),
                (string)$this->rowValue($right, MetaConfig::schema_fields_LOCALE, ''),
            );
            if ($locale !== 0) {
                return $locale;
            }
            return (int)$this->rowValue($left, MetaConfig::schema_fields_ID, 0)
                <=> (int)$this->rowValue($right, MetaConfig::schema_fields_ID, 0);
        });

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
            ->order(MetaConfig::schema_fields_SCOPE, 'ASC')
            ->order(MetaConfig::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();

        $scopes = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!$this->scopeRowMatches($row, $search)) {
                continue;
            }
            $scope = trim((string)$this->rowValue($row, MetaConfig::schema_fields_SCOPE, ''));
            if ($scope !== '') {
                $scopes[$scope] = true;
            }
        }

        $scopes = array_keys($scopes);
        sort($scopes, SORT_STRING);
        return $scopes;
    }

    private function searchRowMatches(mixed $row, MetaConfigSearch $search): bool
    {
        if ($this->nullableString($this->rowValue($row, MetaConfig::schema_fields_NAMESPACE)) !== trim($search->namespace)
            || $this->nullableString($this->rowValue($row, MetaConfig::schema_fields_SCOPE)) !== trim($search->scope)
            || !$this->requestedOwnerMatches(
                $row,
                $search->identifyId,
                $search->metaId,
                $search->metaIdentify,
            )) {
            return false;
        }

        $configKey = $this->nullableString($this->rowValue($row, MetaConfig::schema_fields_CONFIG_KEY));
        if ($search->configKey !== null && $configKey !== trim($search->configKey)) {
            return false;
        }
        if ($search->configKeyPrefix !== null && ($configKey === null || !str_starts_with($configKey, $search->configKeyPrefix))) {
            return false;
        }
        if (!$search->allLocales
            && $this->nullableString($this->rowValue($row, MetaConfig::schema_fields_LOCALE)) !== $search->locale) {
            return false;
        }

        return true;
    }

    private function scopeRowMatches(mixed $row, MetaConfigScopeSearch $search): bool
    {
        if ($this->nullableString($this->rowValue($row, MetaConfig::schema_fields_NAMESPACE)) !== trim($search->namespace)) {
            return false;
        }

        return $this->requestedOwnerMatches(
            $row,
            $search->identifyId,
            $search->metaId,
            $search->metaIdentify,
        );
    }

    public function upsert(MetaConfigWrite $config): MetaConfigRecord
    {
        $requestedIdentity = $this->canonicalIdentity($config->identity);
        $effectiveIdentity = $this->runAtomically(function () use ($requestedIdentity, $config): MetaConfigIdentity {
            [$identity, $configId] = $this->resolveEffectiveIdentity($requestedIdentity);
            $data = $this->writeData($identity, $config->value);
            if ($configId !== null) {
                $this->updateExact($configId, $identity, $data);
                return $identity;
            }

            // Use the framework's cross-database atomic no-op upsert instead of
            // a duplicate-key exception.  MySQL REPEATABLE READ can retain the
            // pre-conflict snapshot, and some adapter paths poison the outer
            // transaction before a savepoint handler can recover it.
            $this->insertIdentityNoOp($data);

            // A locking/current read sees the winning row on MySQL and also
            // verifies all seven raw fields before any value update.  A
            // theoretical SHA-256 collision therefore fails without mutating the
            // other identity.
            $exact = $this->findExact($identity, true);
            if ($exact === null) {
                throw new \RuntimeException(__('MetaConfig 原子建件后无法读取完全相同的身份。'));
            }
            [$insertedIdentity, $insertedId] = $this->resolveEffectiveIdentity($requestedIdentity, true);
            if ($insertedId === null || $insertedId !== $exact->id) {
                throw new \RuntimeException(__('MetaConfig 原子建件后的可选 owner 身份不再唯一。'));
            }
            $this->updateExact(
                $insertedId,
                $insertedIdentity,
                $this->writeData($insertedIdentity, $config->value),
            );

            return $insertedIdentity;
        });

        MetaData::clearCache();

        $record = $this->findExact($effectiveIdentity);
        if ($record === null) {
            throw new \RuntimeException('Meta config upsert completed without a readable exact-locale record.');
        }

        return $record;
    }

    public function delete(MetaConfigIdentity $identity): bool
    {
        $requestedIdentity = $this->canonicalIdentity($identity);
        $deleted = $this->runAtomically(function () use ($requestedIdentity): bool {
            [$effectiveIdentity, $configId] = $this->resolveEffectiveIdentity($requestedIdentity, true);
            if ($configId === null) {
                return false;
            }

            $query = $this->configs->newQuery()
                ->where(MetaConfig::schema_fields_ID, $configId);
            $this->applyExactIdentity($query, $effectiveIdentity);
            $query->delete()->fetch();
            return true;
        });

        if ($deleted) {
            MetaData::clearCache();
        }
        return $deleted;
    }

    /**
     * Preserve the historical optional-owner contract while converging storage
     * on one complete seven-field identity.
     *
     * @return array{0: MetaConfigIdentity, 1: ?int}
     */
    private function resolveEffectiveIdentity(MetaConfigIdentity $requested, bool $lockingRead = false): array
    {
        if (!$this->hasUnspecifiedOwner($requested)) {
            $exact = $this->findExact($requested, $lockingRead);
            if ($exact !== null) {
                return [$this->identityFromRecord($exact), $exact->id];
            }
        }

        $query = $this->configs->newQuery()
            ->where(MetaConfig::schema_fields_NAMESPACE, $requested->namespace)
            ->where(MetaConfig::schema_fields_CONFIG_KEY, $requested->configKey)
            ->where(MetaConfig::schema_fields_SCOPE, $requested->scope);
        $this->applyExactLocale($query, $requested->locale);
        $this->applyOwnerIdentity(
            $query,
            $requested->identifyId,
            $requested->metaId,
            $requested->metaIdentify,
        );
        if ($lockingRead && $this->supportsForUpdate()) {
            $query->additional('FOR UPDATE');
        }
        $rows = $query
            ->order(MetaConfig::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();

        $matches = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if ($this->candidateMatchesRequestedIdentity($row, $requested)) {
                $matches[] = $row;
            }
        }
        if (count($matches) > 1) {
            throw new \RuntimeException(__('MetaConfig 可选 owner 身份存在歧义，config_id=%{1}', [
                implode(',', array_map(
                    fn(mixed $row): int => (int)$this->rowValue($row, MetaConfig::schema_fields_ID, 0),
                    $matches,
                )),
            ]));
        }
        if (isset($matches[0])) {
            $identity = $this->identityFromRow($matches[0]);
            return [
                $identity,
                (int)$this->rowValue($matches[0], MetaConfig::schema_fields_ID, 0),
            ];
        }

        // No compatible historical row exists. Only now do omitted owner fields
        // become SQL NULL in the newly inserted complete storage identity.
        return [$requested, null];
    }

    private function hasUnspecifiedOwner(MetaConfigIdentity $identity): bool
    {
        return $identity->identifyId === null
            || $identity->metaId === null
            || $identity->metaIdentify === null;
    }

    private function findExact(MetaConfigIdentity $identity, bool $lockingRead = false): ?MetaConfigRecord
    {
        $fingerprintQuery = $this->configs->newQuery()
            ->where(MetaConfig::schema_fields_IDENTITY_FINGERPRINT, $identity->fingerprint())
            ->order(MetaConfig::schema_fields_ID, 'ASC');
        if ($lockingRead && $this->supportsForUpdate()) {
            $fingerprintQuery->additional('FOR UPDATE');
        }
        $fingerprintRows = $fingerprintQuery
            ->select()
            ->fetchArray();
        foreach (is_array($fingerprintRows) ? $fingerprintRows : [] as $row) {
            if (!$this->identityMatchesExactly($row, $identity)) {
                throw new \RuntimeException(__('MetaConfig 身份指纹碰撞，config_id=%{1}', [
                    (int)$this->rowValue($row, MetaConfig::schema_fields_ID, 0),
                ]));
            }
            return $this->hydrate($row);
        }

        // Phase 1 migration fallback: only rows not yet backfilled may be found by
        // the seven raw identity fields. Database collation can over-match, so PHP
        // performs the authoritative byte-for-byte comparison below.
        $legacyQuery = $this->configs->newQuery()
            ->where(MetaConfig::schema_fields_IDENTITY_FINGERPRINT, null, 'IS NULL');
        $this->applyExactIdentity($legacyQuery, $identity);
        if ($lockingRead && $this->supportsForUpdate()) {
            $legacyQuery->additional('FOR UPDATE');
        }
        $legacyRows = $legacyQuery
            ->order(MetaConfig::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();

        $matches = [];
        foreach (is_array($legacyRows) ? $legacyRows : [] as $row) {
            if ($this->identityMatchesExactly($row, $identity)) {
                $matches[] = $row;
            }
        }
        if (count($matches) > 1) {
            throw new \RuntimeException(__('MetaConfig 迁移窗口发现重复身份，config_id=%{1}', [
                implode(',', array_map(
                    fn(mixed $row): int => (int)$this->rowValue($row, MetaConfig::schema_fields_ID, 0),
                    $matches,
                )),
            ]));
        }

        return isset($matches[0]) ? $this->hydrate($matches[0]) : null;
    }

    /** @return array<string, mixed> */
    private function writeData(MetaConfigIdentity $identity, string $value): array
    {
        return [
            MetaConfig::schema_fields_NAMESPACE => $identity->namespace,
            MetaConfig::schema_fields_CONFIG_KEY => $identity->configKey,
            MetaConfig::schema_fields_CONFIG_VALUE => $value,
            MetaConfig::schema_fields_SCOPE => $identity->scope,
            MetaConfig::schema_fields_LOCALE => $identity->locale,
            MetaConfig::schema_fields_IDENTIFY_ID => $identity->identifyId,
            MetaConfig::schema_fields_META_ID => $identity->metaId,
            MetaConfig::schema_fields_META_IDENTIFY => $identity->metaIdentify,
            MetaConfig::schema_fields_IDENTITY_FINGERPRINT => $identity->fingerprint(),
        ];
    }

    /** @param array<string, mixed> $data */
    private function updateExact(
        int $configId,
        MetaConfigIdentity $identity,
        array $data,
    ): void {
        $query = $this->configs->newQuery()
            ->where(MetaConfig::schema_fields_ID, $configId);
        $this->applyExactIdentity($query, $identity);
        $query->update($data, MetaConfig::schema_fields_ID)->fetch();
    }

    /** @param array<string, mixed> $data */
    private function insertIdentityNoOp(array $data): void
    {
        $connector = $this->configs->getConnection()->getConnector();
        $table = $connector->quoteTable($this->configs->getTable());
        $columns = [];
        $placeholders = [];
        $params = [];
        foreach ($data as $field => $value) {
            $columns[] = $connector->quoteIdentifier($field);
            $placeholder = ':meta_config_insert_' . count($placeholders);
            $placeholders[] = $placeholder;
            $params[$placeholder] = $value;
        }
        $fingerprint = $connector->quoteIdentifier(MetaConfig::schema_fields_IDENTITY_FINGERPRINT);
        $sql = 'INSERT INTO ' . $table
            . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ') ';
        $sql .= match ($this->databaseType()) {
            // MySQL invokes this clause for every UNIQUE conflict, not only the
            // fingerprint key.  Assign the stored value to itself so a legacy
            // or unexpected UNIQUE can never rewrite another row's identity.
            // The exact locking read below then distinguishes the intended
            // fingerprint conflict from any other uniqueness violation.
            'mysql', 'mariadb' => 'ON DUPLICATE KEY UPDATE ' . $fingerprint . '=' . $fingerprint,
            'pgsql', 'postgres', 'postgresql' => 'ON CONFLICT (' . $fingerprint . ') DO UPDATE SET '
                . $fingerprint . '=EXCLUDED.' . $fingerprint,
            'sqlite' => 'ON CONFLICT (' . $fingerprint . ') DO UPDATE SET '
                . $fingerprint . '=excluded.' . $fingerprint,
            default => throw new \RuntimeException(__('MetaConfig 原子建件不支持当前数据库类型。')),
        };

        $statement = $connector->getWrappedConnection()->prepare($sql);
        $statement->execute($params);
    }

    private function canonicalIdentity(MetaConfigIdentity $identity): MetaConfigIdentity
    {
        return new MetaConfigIdentity(
            namespace: trim($identity->namespace),
            configKey: trim($identity->configKey),
            scope: trim($identity->scope),
            locale: $identity->locale,
            identifyId: $this->nullableTrimmedOwner($identity->identifyId),
            metaId: $identity->metaId,
            metaIdentify: $this->nullableTrimmedOwner($identity->metaIdentify),
        );
    }

    private function identityFromRecord(MetaConfigRecord $record): MetaConfigIdentity
    {
        return new MetaConfigIdentity(
            namespace: $record->namespace,
            configKey: $record->configKey,
            scope: $record->scope,
            locale: $record->locale,
            identifyId: $record->identifyId,
            metaId: $record->metaId,
            metaIdentify: $record->metaIdentify,
        );
    }

    private function identityFromRow(mixed $row): MetaConfigIdentity
    {
        return new MetaConfigIdentity(
            namespace: (string)$this->rowValue($row, MetaConfig::schema_fields_NAMESPACE, ''),
            configKey: (string)$this->rowValue($row, MetaConfig::schema_fields_CONFIG_KEY, ''),
            scope: (string)$this->rowValue($row, MetaConfig::schema_fields_SCOPE, ''),
            locale: $this->nullableString($this->rowValue($row, MetaConfig::schema_fields_LOCALE)),
            identifyId: $this->nullableString($this->rowValue($row, MetaConfig::schema_fields_IDENTIFY_ID)),
            metaId: $this->nullableInt($this->rowValue($row, MetaConfig::schema_fields_META_ID)),
            metaIdentify: $this->nullableString($this->rowValue($row, MetaConfig::schema_fields_META_IDENTIFY)),
        );
    }

    private function candidateMatchesRequestedIdentity(
        mixed $row,
        MetaConfigIdentity $requested,
    ): bool {
        if ($this->nullableString($this->rowValue($row, MetaConfig::schema_fields_NAMESPACE)) !== $requested->namespace
            || $this->nullableString($this->rowValue($row, MetaConfig::schema_fields_CONFIG_KEY)) !== $requested->configKey
            || $this->nullableString($this->rowValue($row, MetaConfig::schema_fields_SCOPE)) !== $requested->scope
            || $this->nullableString($this->rowValue($row, MetaConfig::schema_fields_LOCALE)) !== $requested->locale
        ) {
            return false;
        }
        if ($requested->identifyId !== null
            && $this->nullableString($this->rowValue($row, MetaConfig::schema_fields_IDENTIFY_ID)) !== $requested->identifyId
        ) {
            return false;
        }
        if ($requested->metaId !== null
            && $this->nullableInt($this->rowValue($row, MetaConfig::schema_fields_META_ID)) !== $requested->metaId
        ) {
            return false;
        }
        if ($requested->metaIdentify !== null
            && $this->nullableString($this->rowValue($row, MetaConfig::schema_fields_META_IDENTIFY)) !== $requested->metaIdentify
        ) {
            return false;
        }

        return true;
    }

    private function nullableTrimmedOwner(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private function applyExactIdentity(mixed $query, MetaConfigIdentity $identity): void
    {
        $this->applyExactValue($query, MetaConfig::schema_fields_NAMESPACE, $identity->namespace);
        $this->applyExactValue($query, MetaConfig::schema_fields_CONFIG_KEY, $identity->configKey);
        $this->applyExactValue($query, MetaConfig::schema_fields_SCOPE, $identity->scope);
        $this->applyExactValue($query, MetaConfig::schema_fields_LOCALE, $identity->locale);
        $this->applyExactValue($query, MetaConfig::schema_fields_IDENTIFY_ID, $identity->identifyId);
        $this->applyExactValue($query, MetaConfig::schema_fields_META_ID, $identity->metaId);
        $this->applyExactValue($query, MetaConfig::schema_fields_META_IDENTIFY, $identity->metaIdentify);
    }

    private function applyExactValue(mixed $query, string $field, string|int|null $value): void
    {
        if ($value === null) {
            $query->where($field, null, 'IS NULL');
            return;
        }
        $query->where($field, $value);
    }

    private function identityMatchesExactly(mixed $row, MetaConfigIdentity $identity): bool
    {
        return $this->nullableString($this->rowValue($row, MetaConfig::schema_fields_NAMESPACE)) === $identity->namespace
            && $this->nullableString($this->rowValue($row, MetaConfig::schema_fields_CONFIG_KEY)) === $identity->configKey
            && $this->nullableString($this->rowValue($row, MetaConfig::schema_fields_SCOPE)) === $identity->scope
            && $this->nullableString($this->rowValue($row, MetaConfig::schema_fields_LOCALE)) === $identity->locale
            && $this->nullableString($this->rowValue($row, MetaConfig::schema_fields_IDENTIFY_ID)) === $identity->identifyId
            && $this->nullableInt($this->rowValue($row, MetaConfig::schema_fields_META_ID)) === $identity->metaId
            && $this->nullableString($this->rowValue($row, MetaConfig::schema_fields_META_IDENTIFY)) === $identity->metaIdentify;
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
        return $this->requestedOwnerMatches(
            $row,
            $identity->identifyId,
            $identity->metaId,
            $identity->metaIdentify,
        );
    }

    private function requestedOwnerMatches(
        mixed $row,
        ?string $identifyId,
        ?int $metaId,
        ?string $metaIdentify,
    ): bool {
        if ($identifyId !== null
            && trim($identifyId) !== ''
            && $this->nullableString($this->rowValue($row, MetaConfig::schema_fields_IDENTIFY_ID)) !== trim($identifyId)
        ) {
            return false;
        }
        if ($metaId !== null
            && $this->nullableInt($this->rowValue($row, MetaConfig::schema_fields_META_ID)) !== $metaId
        ) {
            return false;
        }
        if ($metaIdentify !== null
            && trim($metaIdentify) !== ''
            && $this->nullableString($this->rowValue($row, MetaConfig::schema_fields_META_IDENTIFY)) !== trim($metaIdentify)
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

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int)$value;
    }

    private function runAtomically(callable $operation): mixed
    {
        $connection = $this->configs->getConnection();
        if ($this->transactions->isActive($connection)) {
            // Enter the coordinator's nested write scope even when the caller
            // already owns the transaction.  If the operation fails and the
            // caller catches that exception, the coordinator still marks the
            // outer transaction rollback-only; partial insert/upsert effects
            // can therefore never be committed.  A SQLite read transaction is
            // rejected by runWrite because its intent cannot be upgraded.
            return $this->transactions->runWrite($connection, $operation);
        }

        for ($attempt = 1; $attempt <= self::MAX_TRANSACTION_ATTEMPTS; $attempt++) {
            try {
                return $this->transactions->runWrite($connection, $operation);
            } catch (\Throwable $exception) {
                if ($attempt >= self::MAX_TRANSACTION_ATTEMPTS
                    || !$this->isRetryableTransactionConflict($exception)) {
                    throw $exception;
                }
            }
        }

        throw new \LogicException('MetaConfig transaction retry loop exhausted unexpectedly.');
    }

    private function isRetryableTransactionConflict(\Throwable $exception): bool
    {
        $diagnostics = [];
        do {
            $diagnostics[] = strtolower($exception->getMessage());
            $diagnostics[] = strtolower((string)$exception->getCode());
            if ($exception instanceof \PDOException && is_array($exception->errorInfo ?? null)) {
                $diagnostics[] = strtolower(implode(' ', array_map('strval', $exception->errorInfo)));
            }
            $exception = $exception->getPrevious();
        } while ($exception instanceof \Throwable);

        $diagnostic = implode(' ', $diagnostics);
        return str_contains($diagnostic, '40001')
            || str_contains($diagnostic, '40p01')
            || str_contains($diagnostic, '1213')
            || str_contains($diagnostic, 'deadlock found')
            || str_contains($diagnostic, 'deadlock detected');
    }

    private function supportsForUpdate(): bool
    {
        return in_array($this->databaseType(), ['mysql', 'mariadb', 'pgsql', 'postgres', 'postgresql'], true);
    }

    private function isSqlite(): bool
    {
        return $this->databaseType() === 'sqlite';
    }

    private function databaseType(): string
    {
        return strtolower((string)$this->configs->getConnection()
            ->getConnector()->getConfigProvider()->getDbType());
    }
}
