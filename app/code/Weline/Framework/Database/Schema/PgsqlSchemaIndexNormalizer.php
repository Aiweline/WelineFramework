<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Schema;

use Weline\Framework\Database\Connection\Adapter\Pgsql\PgsqlIndexName;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;

/**
 * Align PostgreSQL physical index names with declared logical names before SchemaDiff.
 */
final class PgsqlSchemaIndexNormalizer
{
    public function normalize(
        ConnectorInterface $connector,
        TableSchema $declared,
        TableSchema $actual,
    ): TableSchema {
        $formattedTable = $connector->formatTableName($declared->tableName);
        $normalized = [];
        $consumed = [];
        $claimed = [];

        foreach ($declared->indexes as $declaredIndex) {
            $rawPhysical = PgsqlIndexName::rawPhysical($declaredIndex->name);
            $canonicalPhysical = PgsqlIndexName::canonicalPhysical($formattedTable, $declaredIndex->name);
            $legacyCanonicalPhysical = PgsqlIndexName::legacyCanonicalPhysical(
                $formattedTable,
                $declaredIndex->name,
            );
            $candidates = PgsqlIndexName::candidates($formattedTable, $declaredIndex->name);
            $matches = [];
            $rawMatches = [];
            $canonicalMatches = [];
            $legacyCanonicalMatches = [];
            foreach ($actual->indexes as $position => $actualIndex) {
                if (!in_array($actualIndex->name, $candidates, true)) {
                    continue;
                }
                if (isset($claimed[$position])) {
                    throw new \RuntimeException(__(
                        'PostgreSQL 表 %{1} 的物理索引 %{2} 同时匹配多个逻辑索引声明',
                        [$formattedTable, $actualIndex->name],
                    ));
                }
                if (!$this->definitionEquals($declaredIndex, $actualIndex)) {
                    throw new \RuntimeException(__(
                        'PostgreSQL 表 %{1} 的索引 %{2} 与逻辑声明 %{3} 定义不一致',
                        [$formattedTable, $actualIndex->name, $declaredIndex->name],
                    ));
                }
                $matches[$position] = $actualIndex;
                if ($actualIndex->name === $rawPhysical) {
                    $rawMatches[$position] = $actualIndex;
                }
                if ($actualIndex->name === $canonicalPhysical) {
                    $canonicalMatches[$position] = $actualIndex;
                }
                if (mb_check_encoding($legacyCanonicalPhysical, 'UTF-8')
                    && $actualIndex->name === $legacyCanonicalPhysical) {
                    $legacyCanonicalMatches[$position] = $actualIndex;
                }
            }
            if ($matches === []) {
                continue;
            }
            foreach (array_keys($matches) as $position) {
                $claimed[$position] = $declaredIndex->name;
            }
            $selectedMatches = $canonicalMatches !== []
                ? $canonicalMatches
                : ($legacyCanonicalMatches !== [] ? $legacyCanonicalMatches : $rawMatches);
            foreach (array_keys($selectedMatches) as $position) {
                $consumed[$position] = $declaredIndex->name;
            }
            $normalized[] = $declaredIndex;
        }

        foreach ($actual->indexes as $position => $actualIndex) {
            if (!isset($consumed[$position])) {
                $normalized[] = $actualIndex;
            }
        }

        return new TableSchema(
            tableName: $actual->tableName,
            comment: $actual->comment,
            columns: $actual->columns,
            indexes: $normalized,
            foreignKeys: $actual->foreignKeys,
            modelClass: $actual->modelClass,
        );
    }

    private function definitionEquals(IndexDefinition $declared, IndexDefinition $actual): bool
    {
        $declaredType = strtoupper($declared->type);
        $actualType = strtoupper($actual->type);
        if ($declaredType === 'FULLTEXT' || $actualType === 'FULLTEXT') {
            if ($declaredType !== $actualType) {
                return false;
            }
        }

        return $declared->columns === $actual->columns
            && ($declared->type === 'UNIQUE') === ($actual->type === 'UNIQUE')
            && $this->expectedMethod($declared) === strtoupper($actual->method);
    }

    private function expectedMethod(IndexDefinition $index): string
    {
        return match (strtoupper($index->type)) {
            'FULLTEXT' => 'GIN',
            'SPATIAL' => 'GIST',
            default => strtoupper($index->method),
        };
    }
}
