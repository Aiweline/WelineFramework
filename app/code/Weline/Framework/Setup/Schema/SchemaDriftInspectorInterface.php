<?php

declare(strict_types=1);

namespace Weline\Framework\Setup\Schema;

/**
 * Read-only schema drift detection contract, supplied by the database module's
 * release pipeline.
 */
interface SchemaDriftInspectorInterface
{
    /**
     * @param list<string>|null $moduleFilter
     * @return array{
     *   clean: bool,
     *   drifts: list<array{
     *     module: string,
     *     version: string,
     *     suggested_version: ?string,
     *     changed_tables: list<string>,
     *     added_tables: list<string>,
     *     removed_tables: list<string>,
     *     existing_checksum: ?string,
     *     expected_checksum: string
     *   }>,
     *   checked_modules: int
     * }
     */
    public function inspect(?array $moduleFilter = null): array;
}
