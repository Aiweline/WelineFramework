<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Schema;

use Weline\Framework\Database\Connection\Adapter\Pgsql\PgsqlIndexName;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Helper\Standar;

/** Cross-adapter identity and semantic rules for declared indexes. */
final class IndexDefinitionContract
{
    public const SQLITE_CONSTRAINT_INDEX_PREFIX = '__weline_sqlite_unique_constraint__';

    /** @param list<IndexDefinition> $indexes @return array<string, true> */
    public static function explicitSingleUniqueColumnMap(array $indexes): array
    {
        $columns = [];
        foreach ($indexes as $index) {
            if ($index->type === 'UNIQUE' && count($index->columns) === 1) {
                $columns[$index->columns[0]] = true;
            }
        }
        return $columns;
    }

    /**
     * Reject declarations that collapse to the same portable or physical
     * identity before any DDL can run.
     *
     * @param list<IndexDefinition> $indexes
     * @param null|callable(string): string $physicalIdentity
     */
    public static function assertDeclaredNames(array $indexes, ?callable $physicalIdentity = null): void
    {
        $logical = [];
        $physical = [];
        foreach ($indexes as $index) {
            $name = trim($index->name);
            if ($name === '') {
                throw new \InvalidArgumentException(__('Schema 索引名不能为空'));
            }
            if (str_starts_with(strtolower($name), self::SQLITE_CONSTRAINT_INDEX_PREFIX)) {
                throw new \InvalidArgumentException(__(
                    'Schema 索引名 %{1} 使用了框架保留前缀',
                    [$name],
                ));
            }
            $logicalKey = strtolower($name);
            if (isset($logical[$logicalKey])) {
                throw new \InvalidArgumentException(__(
                    'Schema 索引名在忽略大小写后冲突: %{1} / %{2}',
                    [$logical[$logicalKey], $name],
                ));
            }
            $logical[$logicalKey] = $name;

            $physicalKey = self::identity($name, $physicalIdentity);
            if (isset($physical[$physicalKey])) {
                throw new \InvalidArgumentException(__(
                    'Schema 索引名映射到相同物理身份: %{1} / %{2}',
                    [$physical[$physicalKey], $name],
                ));
            }
            $physical[$physicalKey] = $name;
        }
    }

    /**
     * @param list<IndexDefinition> ...$indexSets
     * @param null|callable(string): string $physicalIdentity
     * @return array<string, true>
     */
    public static function reservedIdentities(?callable $physicalIdentity, array ...$indexSets): array
    {
        $reserved = [];
        foreach ($indexSets as $indexes) {
            foreach ($indexes as $index) {
                $name = trim($index->name);
                if ($name === '') {
                    continue;
                }
                $reserved[strtolower($name)] = true;
                $reserved[self::identity($name, $physicalIdentity)] = true;
            }
        }
        return $reserved;
    }

    /**
     * @param array<string, true> $reservedIdentities
     * @param null|callable(string): string $physicalIdentity
     */
    public static function resolveImplicitName(
        string $tableName,
        string $columnName,
        array $reservedIdentities = [],
        ?callable $physicalIdentity = null,
    ): string {
        $columnToken = preg_replace('/[^A-Za-z0-9_]+/', '_', trim($columnName)) ?: 'column';
        $base = self::fitName('uk_' . $columnToken);
        if (!self::isReserved($base, $reservedIdentities, $physicalIdentity)) {
            return $base;
        }

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $suffix = substr(hash('sha256', strtolower($tableName) . "\0" . $columnName . "\0" . $attempt), 0, 10);
            $candidate = substr($base, 0, 53) . '_' . $suffix;
            if (!self::isReserved($candidate, $reservedIdentities, $physicalIdentity)) {
                return $candidate;
            }
        }

        throw new \LogicException(__(
            '无法为 %{1}.%{2} 分配隐式 UNIQUE 索引名',
            [$tableName, $columnName],
        ));
    }

    public static function physicalIdentity(
        ConnectorInterface $connector,
        string $tableName,
        string $logicalName,
    ): string {
        $databaseType = strtolower($connector->getConfigProvider()->getDbType());
        $formattedTable = $connector->formatTableName($tableName);
        $physical = match ($databaseType) {
            'pgsql', 'postgres', 'postgresql' => PgsqlIndexName::canonicalPhysical($formattedTable, $logicalName),
            'sqlite' => Standar::getIndexName($formattedTable, $logicalName),
            default => trim(str_replace(['`', '"'], '', $logicalName)),
        };
        return strtolower($physical);
    }

    /** @param list<IndexDefinition> $indexes */
    public static function assertAdapterLimits(ConnectorInterface $connector, array $indexes): void
    {
        if (strtolower($connector->getConfigProvider()->getDbType()) !== 'mysql') {
            return;
        }
        foreach ($indexes as $index) {
            if (mb_strlen($index->name, 'UTF-8') <= 64) {
                continue;
            }
            throw new \InvalidArgumentException(__(
                'MySQL Schema 索引名 %{1} 超过 64 个字符',
                [$index->name],
            ));
        }
    }

    public static function equals(
        IndexDefinition $declared,
        IndexDefinition $actual,
        ?string $databaseType = null,
    ): bool {
        if ($declared->columns !== $actual->columns) {
            return false;
        }

        $databaseType = strtolower((string)$databaseType);
        $declaredType = self::semanticType($declared->type, $databaseType);
        $actualType = self::semanticType($actual->type, $databaseType);
        if ($declaredType !== $actualType) {
            return false;
        }
        if ($databaseType === 'sqlite' || in_array($declaredType, ['FULLTEXT', 'SPATIAL'], true)) {
            return true;
        }

        return strtoupper($declared->method ?: 'BTREE') === strtoupper($actual->method ?: 'BTREE');
    }

    /** @param null|callable(string): string $physicalIdentity */
    private static function identity(string $name, ?callable $physicalIdentity): string
    {
        return strtolower($physicalIdentity !== null ? $physicalIdentity($name) : $name);
    }

    /**
     * @param array<string, true> $reservedIdentities
     * @param null|callable(string): string $physicalIdentity
     */
    private static function isReserved(
        string $name,
        array $reservedIdentities,
        ?callable $physicalIdentity,
    ): bool {
        return isset($reservedIdentities[strtolower($name)])
            || isset($reservedIdentities[self::identity($name, $physicalIdentity)]);
    }

    private static function semanticType(string $type, string $databaseType): string
    {
        $type = strtoupper(trim($type));
        if ($databaseType === 'sqlite' && $type !== 'UNIQUE') {
            return 'DEFAULT';
        }
        return match ($type) {
            'UNIQUE', 'FULLTEXT', 'SPATIAL' => $type,
            default => 'DEFAULT',
        };
    }

    private static function fitName(string $name): string
    {
        if (strlen($name) <= 64) {
            return $name;
        }
        return substr($name, 0, 53) . '_' . substr(hash('sha256', $name), 0, 10);
    }
}
