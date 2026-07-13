<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Migration;

/**
 * Optional migration metadata and backup capability.
 *
 * It is intentionally separate from MigrationInterface so migrations written
 * before these capabilities existed remain loadable. Runtimes must continue
 * to feature-detect these methods for legacy implementations.
 */
interface MigrationMetadataInterface extends MigrationInterface
{
    public function getType(): string;

    /** @return list<string> */
    public function getAffectedTables(): array;

    public function requiresBackup(): bool;

    /** @return array{strategy: string, tables: list<string>, columns: list<string>} */
    public function getBackupStrategy(): array;
}
