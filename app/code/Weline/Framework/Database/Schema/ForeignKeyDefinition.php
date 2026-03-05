<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Schema;

/**
 * 外键定义 DTO。
 */
final class ForeignKeyDefinition
{
    /** @param list<string> $columns */
    /** @param list<string> $referencesColumns */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly string $referencesTable,
        public readonly array $referencesColumns,
        public readonly bool $onDeleteCascade = false,
        public readonly bool $onUpdateCascade = false,
    ) {
    }
}
