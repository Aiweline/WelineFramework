<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Schema;

use Weline\Framework\Database\Connection\Api\ConnectorInterface;

/**
 * Builds a deployment-neutral identity for semantic schema checkpoints.
 *
 * Only qualifiers proven to belong to the active connection are removed.
 * Explicit foreign schemas/catalogs remain part of the identity. Identifier
 * quote style is ignored, while dots inside quoted identifiers are preserved.
 */
final class SchemaCheckpointIdentity
{
    /** @return list<string> */
    public static function runtimeQualifiers(ConnectorInterface $connector): array
    {
        $probe = '__weline_checkpoint_identity_probe__';
        try {
            // Resolve the same physical qualification the active connector
            // injects for an unqualified logical table. Configured database
            // names and conventional aliases are not sufficient proof: for
            // example PostgreSQL injects current_schema(), while SQLite temp
            // remains an explicit attached schema.
            $connector->getWrappedConnection();
            if (!is_callable([$connector, 'formatTableName'])) {
                return [];
            }
            $formatted = $connector->formatTableName($probe);
        } catch (\Throwable) {
            return [];
        }

        $parts = self::identifierParts($formatted);
        if (count($parts) < 2) {
            return [];
        }
        array_pop($parts);

        $qualifiers = [];
        foreach ($parts as $qualifier) {
            self::appendQualifier($qualifiers, $qualifier);
        }

        return array_values($qualifiers);
    }

    /** @param list<string> $runtimeQualifiers */
    public static function tableName(string $tableName, array $runtimeQualifiers = []): string
    {
        $parts = self::logicalParts($tableName, $runtimeQualifiers);
        if ($parts === []) {
            return '';
        }

        return implode('.', array_map(self::encodeIdentifierPart(...), $parts));
    }

    /** @param list<string> $runtimeQualifiers */
    public static function legacyTableName(string $tableName, array $runtimeQualifiers = []): string
    {
        $parts = self::logicalParts($tableName, $runtimeQualifiers);
        if ($parts === []) {
            return '';
        }
        $quote = str_contains($tableName, '`')
            ? '`'
            : (str_contains($tableName, '"') ? '"' : '');
        if ($quote === '') {
            return implode('.', $parts);
        }
        return implode('.', array_map(
            static fn(string $part): string => $quote . str_replace($quote, $quote . $quote, $part) . $quote,
            $parts,
        ));
    }

    public static function qualifiedTableName(string $tableName): string
    {
        $parts = self::identifierParts($tableName);
        return implode('.', array_map(self::encodeIdentifierPart(...), $parts));
    }

    /** @param list<string> $runtimeQualifiers */
    public static function schema(TableSchema $schema, array $runtimeQualifiers = []): TableSchema
    {
        return self::copySchema($schema, self::tableName(...), $runtimeQualifiers);
    }

    /** @param list<string> $runtimeQualifiers */
    public static function legacySchema(TableSchema $schema, array $runtimeQualifiers = []): TableSchema
    {
        return self::copySchema($schema, self::legacyTableName(...), $runtimeQualifiers);
    }

    /**
     * @param callable(string, list<string>): string $tableNormalizer
     * @param list<string> $runtimeQualifiers
     */
    private static function copySchema(
        TableSchema $schema,
        callable $tableNormalizer,
        array $runtimeQualifiers,
    ): TableSchema
    {
        $tableName = $tableNormalizer($schema->tableName, $runtimeQualifiers);
        $foreignKeys = [];
        foreach ($schema->foreignKeys as $foreignKey) {
            if (!$foreignKey instanceof ForeignKeyDefinition) {
                $foreignKeys[] = $foreignKey;
                continue;
            }
            $foreignKeys[] = new ForeignKeyDefinition(
                name: $foreignKey->name,
                columns: $foreignKey->columns,
                referencesTable: $tableNormalizer($foreignKey->referencesTable, $runtimeQualifiers),
                referencesColumns: $foreignKey->referencesColumns,
                onDeleteCascade: $foreignKey->onDeleteCascade,
                onUpdateCascade: $foreignKey->onUpdateCascade,
            );
        }

        if ($tableName === $schema->tableName && $foreignKeys === $schema->foreignKeys) {
            return $schema;
        }

        return new TableSchema(
            tableName: $tableName,
            comment: $schema->comment,
            columns: $schema->columns,
            indexes: $schema->indexes,
            foreignKeys: $foreignKeys,
            modelClass: $schema->modelClass,
        );
    }

    /**
     * Finds a table fingerprint across format-2 logical identities and legacy
     * format-1 raw table names.
     *
     * @param array<string, string> $tables
     * @param list<string> $runtimeQualifiers
     */
    public static function fingerprint(array $tables, string $tableName, array $runtimeQualifiers = []): ?string
    {
        $candidates = array_values(array_unique(array_filter([
            self::tableName($tableName, $runtimeQualifiers),
            self::legacyTableName($tableName, $runtimeQualifiers),
            $tableName,
            self::qualifiedTableName($tableName),
        ], static fn(string $candidate): bool => $candidate !== '')));
        foreach ($candidates as $candidate) {
            if (isset($tables[$candidate])) {
                return (string)$tables[$candidate];
            }
        }
        return null;
    }

    /** @param array<string, string> $qualifiers */
    private static function appendQualifier(array &$qualifiers, string $qualifier): void
    {
        $qualifier = trim($qualifier, " \t\n\r\0\x0B`\"");
        if ($qualifier !== '') {
            $qualifiers[$qualifier] = $qualifier;
        }
    }

    /** @return list<string> */
    private static function identifierParts(string $identifier): array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return [];
        }

        $parts = [];
        $part = '';
        $quote = null;
        $length = strlen($identifier);
        for ($index = 0; $index < $length; $index++) {
            $character = $identifier[$index];
            if ($quote !== null) {
                if ($character === $quote) {
                    if ($index + 1 < $length && $identifier[$index + 1] === $quote) {
                        $part .= $quote;
                        $index++;
                        continue;
                    }
                    $quote = null;
                    continue;
                }
                $part .= $character;
                continue;
            }
            if ($character === '`' || $character === '"') {
                $quote = $character;
                continue;
            }
            if ($character === '.') {
                self::appendIdentifierPart($parts, $part);
                $part = '';
                continue;
            }
            $part .= $character;
        }
        self::appendIdentifierPart($parts, $part);

        return $parts;
    }

    /**
     * @param list<string> $runtimeQualifiers
     * @return list<string>
     */
    private static function logicalParts(string $tableName, array $runtimeQualifiers): array
    {
        $parts = self::identifierParts($tableName);
        $runtime = array_fill_keys($runtimeQualifiers, true);
        while (count($parts) > 1 && isset($runtime[$parts[0]])) {
            array_shift($parts);
        }
        return $parts;
    }

    /** @param list<string> $parts */
    private static function appendIdentifierPart(array &$parts, string $part): void
    {
        $part = trim($part);
        if ($part !== '') {
            $parts[] = $part;
        }
    }

    private static function encodeIdentifierPart(string $part): string
    {
        return str_replace(['%', '.', '/'], ['%25', '%2E', '%2F'], $part);
    }
}
