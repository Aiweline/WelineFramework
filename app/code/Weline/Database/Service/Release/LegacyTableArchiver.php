<?php

declare(strict_types=1);

namespace Weline\Database\Service\Release;

use InvalidArgumentException;
use RuntimeException;
use Weline\Framework\Database\ConnectionFactory;

/**
 * Renames legacy tables to <table>_legacy_<suffix> after a verified snapshot.
 *
 * This is a cutover primitive only: it never drops data and refuses to run
 * without a verified snapshot. New RSSE code never reads archived tables.
 */
final class LegacyTableArchiver
{
    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly DatabaseSnapshotService $snapshotService,
    ) {
    }

    /**
     * @param list<string> $tables
     * @return array<string, string> original => archived
     */
    public function archive(array $tables, string $snapshotKey, string $suffix = ''): array
    {
        $snapshotKey = trim($snapshotKey);
        if ($snapshotKey === '') {
            throw new InvalidArgumentException('archive requires a verified snapshot key');
        }
        if (!$this->snapshotService->verify($snapshotKey)) {
            throw new RuntimeException('snapshot is not verified, refusing to archive legacy tables: ' . $snapshotKey);
        }

        $suffix = trim($suffix);
        if ($suffix === '') {
            $suffix = date('Ymd');
        }
        if (preg_match('/\A[A-Za-z0-9_]+\z/', $suffix) !== 1) {
            throw new InvalidArgumentException('invalid archive suffix: ' . $suffix);
        }

        $connector = $this->connectionFactory->getConnector();
        $mapping = [];
        foreach (array_values(array_unique(array_map('strval', $tables))) as $table) {
            $table = trim($table);
            if ($table === '' || preg_match('/\A[A-Za-z0-9_.]+\z/', $table) !== 1) {
                throw new InvalidArgumentException('invalid legacy table name: ' . $table);
            }
            if (!$connector->tableExist($table)) {
                continue;
            }
            $target = $table . '_legacy_' . $suffix;
            if ($connector->tableExist($target)) {
                throw new RuntimeException('archive target already exists: ' . $target);
            }
            $sql = 'ALTER TABLE ' . $connector->formatTableName($table)
                . ' RENAME TO ' . $connector->quoteIdentifier($target);
            $connector->query($sql)->fetch();
            if (!$connector->tableExist($target)) {
                throw new RuntimeException('legacy table archive failed: ' . $table);
            }
            $mapping[$table] = $target;
        }

        return $mapping;
    }
}
