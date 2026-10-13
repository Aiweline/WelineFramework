<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Schema;

/**
 * Declarative primary-key promotion for legacy tables that only have UNIQUE.
 */
final class PrimaryKeyPromotionDefinition
{
    /**
     * @param list<string> $columns Declared PRIMARY KEY column order
     * @param list<string> $replaceUniqueIndexNames Physical UNIQUE indexes to drop before ADD PRIMARY KEY
     */
    public function __construct(
        public readonly array $columns,
        public readonly array $replaceUniqueIndexNames = [],
    ) {
        if ($this->columns === []) {
            throw new \InvalidArgumentException('primary key promotion requires at least one column');
        }
    }
}
