<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Service\Document;

use Weline\DeveloperWorkspace\Model\Document;
use Weline\DeveloperWorkspace\Model\Document\Catalog;
use Weline\DeveloperWorkspace\Model\Document\Catalog\Translation as CatalogTranslation;
use Weline\DeveloperWorkspace\Model\Document\Translation;
use Weline\DeveloperWorkspace\Model\Document\TranslationJob;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

/**
 * Idempotent unique-key healer for DeveloperWorkspace document tables.
 * Must run before SchemaDiff UNIQUE index DDL.
 */
final class DocumentUniqueKeyHealer
{
    /**
     * @return list<array{table: string, groups: int, deleted: int, backup: string}>
     */
    public function healAll(?Printing $printing = null): array
    {
        $printing ??= ObjectManager::getInstance(Printing::class);
        $stats = [];

        // Order: catalog → document (remap children) → translations → jobs
        $stats[] = $this->healCatalogDuplicates($printing);
        $stats[] = $this->healDocumentDuplicates($printing);
        $stats[] = $this->healSimpleUnique(
            $printing,
            Translation::class,
            Translation::schema_table,
            [Translation::schema_fields_SOURCE_DOCUMENT_ID, Translation::schema_fields_LOCALE],
            null,
        );
        $stats[] = $this->healSimpleUnique(
            $printing,
            CatalogTranslation::class,
            CatalogTranslation::schema_table,
            [CatalogTranslation::schema_fields_CATALOG_ID, CatalogTranslation::schema_fields_LOCALE],
            null,
        );
        $stats[] = $this->healSimpleUnique(
            $printing,
            TranslationJob::class,
            TranslationJob::schema_table,
            [
                TranslationJob::schema_fields_TARGET_TYPE,
                TranslationJob::schema_fields_TARGET_ID,
                TranslationJob::schema_fields_LOCALE,
                TranslationJob::schema_fields_SOURCE_HASH,
            ],
            null,
        );

        return $stats;
    }

    /**
     * @return array{table: string, groups: int, deleted: int, backup: string}
     */
    private function healCatalogDuplicates(Printing $printing): array
    {
        return $this->healSimpleUnique(
            $printing,
            Catalog::class,
            Catalog::schema_table,
            [Catalog::schema_fields_NAME, Catalog::schema_fields_PID],
            function (ConnectorInterface $connector, string $table, array $winnerByLoser) use ($printing): void {
                if ($winnerByLoser === []) {
                    return;
                }
                /** @var Document $document */
                $document = ObjectManager::getInstance(Document::class);
                if (!$connector->tableExist($document->getTable())) {
                    return;
                }
                $docTable = $connector->quoteTable($this->physicalTable($document));
                $catCol = $connector->quoteIdentifier(Document::schema_fields_CATEGORY_ID);
                $idCol = $connector->quoteIdentifier(Document::schema_fields_ID);
                foreach ($winnerByLoser as $loserId => $winnerId) {
                    $sql = sprintf(
                        'UPDATE %s SET %s = %d WHERE %s = %d',
                        $docTable,
                        $catCol,
                        (int)$winnerId,
                        $catCol,
                        (int)$loserId,
                    );
                    $connector->getLink()->exec($sql);
                }
                $printing->note(__('DocumentUniqueKeyHealer: remapped document.category_id for %{1} catalog loser(s)', [count($winnerByLoser)]));
            },
        );
    }

    /**
     * @return array{table: string, groups: int, deleted: int, backup: string}
     */
    private function healDocumentDuplicates(Printing $printing): array
    {
        return $this->healSimpleUnique(
            $printing,
            Document::class,
            Document::schema_table,
            [Document::schema_fields_MODULE_NAME, Document::schema_fields_FILE_PATH],
            function (ConnectorInterface $connector, string $table, array $winnerByLoser) use ($printing): void {
                if ($winnerByLoser === []) {
                    return;
                }
                $this->remapDocumentChildren($connector, $winnerByLoser, $printing);
            },
            true,
        );
    }

    /**
     * @param array<int, int> $winnerByLoser
     */
    private function remapDocumentChildren(ConnectorInterface $connector, array $winnerByLoser, Printing $printing): void
    {
        /** @var Translation $translation */
        $translation = ObjectManager::getInstance(Translation::class);
        if ($connector->tableExist($translation->getTable())) {
            $tTable = $connector->quoteTable($this->physicalTable($translation));
            $srcCol = $connector->quoteIdentifier(Translation::schema_fields_SOURCE_DOCUMENT_ID);
            foreach ($winnerByLoser as $loserId => $winnerId) {
                $connector->getLink()->exec(sprintf(
                    'UPDATE %s SET %s = %d WHERE %s = %d',
                    $tTable,
                    $srcCol,
                    (int)$winnerId,
                    $srcCol,
                    (int)$loserId,
                ));
            }
        }

        /** @var TranslationJob $job */
        $job = ObjectManager::getInstance(TranslationJob::class);
        if ($connector->tableExist($job->getTable())) {
            $jTable = $connector->quoteTable($this->physicalTable($job));
            $typeCol = $connector->quoteIdentifier(TranslationJob::schema_fields_TARGET_TYPE);
            $idCol = $connector->quoteIdentifier(TranslationJob::schema_fields_TARGET_ID);
            foreach ($winnerByLoser as $loserId => $winnerId) {
                $connector->getLink()->exec(sprintf(
                    "UPDATE %s SET %s = %d WHERE %s = %s AND %s = %d",
                    $jTable,
                    $idCol,
                    (int)$winnerId,
                    $typeCol,
                    $connector->getLink()->quote(TranslationJob::TARGET_DOCUMENT),
                    $idCol,
                    (int)$loserId,
                ));
            }
        }

        $printing->note(__('DocumentUniqueKeyHealer: remapped translation/job refs for %{1} document loser(s)', [count($winnerByLoser)]));
    }

    /**
     * @param class-string<Model> $modelClass
     * @param list<string> $keyColumns
     * @param (callable(ConnectorInterface, string, array<int,int>): void)|null $beforeDelete
     * @return array{table: string, groups: int, deleted: int, backup: string}
     */
    private function healSimpleUnique(
        Printing $printing,
        string $modelClass,
        string $logicalTable,
        array $keyColumns,
        ?callable $beforeDelete,
        bool $preferUpdatedAt = false,
    ): array {
        /** @var Model $model */
        $model = ObjectManager::getInstance($modelClass);
        $connector = $model->getConnection()->getConnector();
        $physical = $this->physicalTable($model);
        if (!$connector->tableExist($model->getTable()) && !$connector->tableExist($physical)) {
            $printing->note(__('DocumentUniqueKeyHealer: skip %{1} (table missing)', [$logicalTable]));
            return ['table' => $logicalTable, 'groups' => 0, 'deleted' => 0, 'backup' => ''];
        }

        $rows = $model->clear()->select()->fetchArray();
        if (!is_array($rows) || $rows === []) {
            $printing->note(__('DocumentUniqueKeyHealer: %{1} groups=%{2} deleted=%{3}', [$logicalTable, 0, 0]));
            return ['table' => $logicalTable, 'groups' => 0, 'deleted' => 0, 'backup' => ''];
        }

        /** @var array<string, list<array<string, mixed>>> $groups */
        $groups = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $parts = [];
            foreach ($keyColumns as $col) {
                $parts[] = (string)($row[$col] ?? '');
            }
            $key = implode("\0", $parts);
            $groups[$key][] = $row;
        }

        $winnerByLoser = [];
        $loserIds = [];
        $dupGroups = 0;
        foreach ($groups as $members) {
            if (count($members) < 2) {
                continue;
            }
            $dupGroups++;
            usort($members, function (array $a, array $b) use ($preferUpdatedAt): int {
                if ($preferUpdatedAt) {
                    $aNull = ($a[Document::schema_fields_UPDATED_AT] ?? null) === null || $a[Document::schema_fields_UPDATED_AT] === '';
                    $bNull = ($b[Document::schema_fields_UPDATED_AT] ?? null) === null || $b[Document::schema_fields_UPDATED_AT] === '';
                    if ($aNull !== $bNull) {
                        return $aNull ? 1 : -1; // non-null first
                    }
                    $cmp = strcmp((string)($b[Document::schema_fields_UPDATED_AT] ?? ''), (string)($a[Document::schema_fields_UPDATED_AT] ?? ''));
                    if ($cmp !== 0) {
                        return $cmp;
                    }
                }
                return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
            });
            $winnerId = (int)($members[0]['id'] ?? 0);
            for ($i = 1, $n = count($members); $i < $n; $i++) {
                $loserId = (int)($members[$i]['id'] ?? 0);
                if ($loserId > 0 && $winnerId > 0) {
                    $winnerByLoser[$loserId] = $winnerId;
                    $loserIds[] = $loserId;
                }
            }
        }

        if ($loserIds === []) {
            $printing->note(__('DocumentUniqueKeyHealer: %{1} groups=%{2} deleted=%{3}', [$logicalTable, 0, 0]));
            return ['table' => $logicalTable, 'groups' => 0, 'deleted' => 0, 'backup' => ''];
        }

        $backup = $this->backupLosers($connector, $physical, $loserIds);
        if ($beforeDelete !== null) {
            $beforeDelete($connector, $physical, $winnerByLoser);
        }
        $this->deleteByIds($connector, $physical, $loserIds);

        $deleted = count($loserIds);
        $printing->note(__(
            'DocumentUniqueKeyHealer: %{1} groups=%{2} deleted=%{3} backup=%{4}',
            [$logicalTable, $dupGroups, $deleted, $backup]
        ));

        return ['table' => $logicalTable, 'groups' => $dupGroups, 'deleted' => $deleted, 'backup' => $backup];
    }

    /**
     * @param list<int> $ids
     */
    private function backupLosers(ConnectorInterface $connector, string $physicalTable, array $ids): string
    {
        $bak = $physicalTable . '_dedupe_bak_' . date('YmdHis');
        $qTable = $connector->quoteTable($physicalTable);
        $qBak = $connector->quoteTable($bak);
        $idList = implode(',', array_map('intval', $ids));
        $idCol = $connector->quoteIdentifier('id');
        try {
            $connector->getLink()->exec(sprintf(
                'CREATE TABLE %s AS SELECT * FROM %s WHERE %s IN (%s)',
                $qBak,
                $qTable,
                $idCol,
                $idList,
            ));
        } catch (\Throwable $e) {
            throw new \RuntimeException(__(
                'DocumentUniqueKeyHealer: backup failed for %{1}: %{2}',
                [$physicalTable, $e->getMessage()]
            ), 0, $e);
        }
        if (!$connector->tableExist($bak)) {
            throw new \RuntimeException(__('DocumentUniqueKeyHealer: backup table %{1} was not created', [$bak]));
        }

        return $bak;
    }

    /**
     * @param list<int> $ids
     */
    private function deleteByIds(ConnectorInterface $connector, string $physicalTable, array $ids): void
    {
        $qTable = $connector->quoteTable($physicalTable);
        $idCol = $connector->quoteIdentifier('id');
        $idList = implode(',', array_map('intval', $ids));
        $connector->getLink()->exec(sprintf('DELETE FROM %s WHERE %s IN (%s)', $qTable, $idCol, $idList));
    }

    private function physicalTable(Model $model): string
    {
        // Always return unquoted prefix+logical so backup suffixes stay valid identifiers.
        $prefix = (string)$model->getConnection()->getConfigProvider()->getPrefix();
        $logical = (string)$model::schema_table;

        return $prefix !== '' ? $prefix . $logical : $logical;
    }
}
