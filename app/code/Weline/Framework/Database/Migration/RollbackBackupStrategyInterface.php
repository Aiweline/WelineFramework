<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Migration;

/**
 * Explicit data-preservation contract for coordinated migration rollback.
 *
 * Implementing this interface is deliberately opt-in. Older migrations are
 * still loadable, but cannot be automatically rolled back because the runtime
 * cannot prove that data written after their installation will be preserved.
 */
interface RollbackBackupStrategyInterface extends MigrationInterface
{
    /**
     * Supported strategies:
     * - none: the migration author explicitly confirms no data can be lost;
     * - column: back up the listed columns immediately before uninstall;
     * - table: back up the listed table structures and rows before uninstall.
     *
     * `reason` is mandatory for every strategy. Set
     * `requires_forward_backup=true` when rollback also depends on the backup
     * captured before the original upgrade (for example, a dropped table),
     * and list the required structure/table/column backup record types.
     *
     * @return array{
     *     strategy: 'none'|'column'|'table',
     *     tables?: list<string>,
     *     columns?: list<string>,
     *     reason: string,
     *     requires_forward_backup?: bool,
     *     forward_backup_types?: list<'structure'|'table'|'column'>
     * }
     */
    public function getRollbackBackupStrategy(): array;
}
