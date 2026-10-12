<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\Framework\Database\ConnectionFactory;
use Weline\I18n\Model\Dictionary as WordDictionary;
use Weline\I18n\Model\Locale\Dictionary as LocaleDictionary;

/**
 * 清理地区/基础词典中违反 assertWord / assertLocaleCode 的脏行。
 * 典型来源：event.php 多行 __()、BOM 粘贴、引号失控采集、非法 locale_code、AI 扇出。
 */
final class DictionaryInvalidWordPurgeService
{
    public function __construct(
        private readonly LocaleDictionary $localeDictionary,
        private readonly WordDictionary $wordDictionary,
    ) {
    }

    /**
     * @return array{
     *   dry_run: bool,
     *   locale: array{oversize:int,newline:int,bom:int,quote_run:int,bad_locale:int,deleted:int},
     *   base: array{oversize:int,newline:int,bom:int,quote_run:int,deleted:int},
     *   before: array{locale_total:int,locale_max_word:int,base_total:int,base_max_word:int},
     *   after: array{locale_total:int,locale_max_word:int,base_total:int,base_max_word:int}
     * }
     */
    public function purge(bool $dryRun = false): array
    {
        $localeConn = $this->localeDictionary->getConnection();
        $localeTable = $this->localeDictionary->getTable();
        $localeDriver = $this->driverName($localeConn);

        $baseConn = $this->wordDictionary->getConnection();
        $baseTable = $this->wordDictionary->getTable();
        $baseDriver = $this->driverName($baseConn);

        $before = [
            'locale_total' => $this->scalarInt($localeConn, "SELECT COUNT(*) FROM {$localeTable}"),
            'locale_max_word' => $this->scalarInt($localeConn, "SELECT COALESCE(MAX(CHAR_LENGTH(word)),0) FROM {$localeTable}"),
            'base_total' => $this->scalarInt($baseConn, "SELECT COUNT(*) FROM {$baseTable}"),
            'base_max_word' => $this->scalarInt($baseConn, "SELECT COALESCE(MAX(CHAR_LENGTH(word)),0) FROM {$baseTable}"),
        ];

        $localeCounts = [
            'oversize' => $this->scalarInt($localeConn, "SELECT COUNT(*) FROM {$localeTable} WHERE CHAR_LENGTH(word) > " . WordDictionary::MAX_WORD_LENGTH),
            'newline' => $this->scalarInt($localeConn, $this->sqlCountNewline($localeTable, $localeDriver)),
            'bom' => $this->scalarInt($localeConn, $this->sqlCountBom($localeTable, $localeDriver)),
            'quote_run' => $this->scalarInt($localeConn, "SELECT COUNT(*) FROM {$localeTable} WHERE word LIKE '%\"\"\"\"%' OR word LIKE '%''''%'"),
            'bad_locale' => $this->scalarInt($localeConn, $this->sqlCountBadLocale($localeTable, $localeDriver)),
            'deleted' => 0,
        ];

        $baseCounts = [
            'oversize' => $this->scalarInt($baseConn, "SELECT COUNT(*) FROM {$baseTable} WHERE CHAR_LENGTH(word) > " . WordDictionary::MAX_WORD_LENGTH),
            'newline' => $this->scalarInt($baseConn, $this->sqlCountNewline($baseTable, $baseDriver)),
            'bom' => $this->scalarInt($baseConn, $this->sqlCountBom($baseTable, $baseDriver)),
            'quote_run' => $this->scalarInt($baseConn, "SELECT COUNT(*) FROM {$baseTable} WHERE word LIKE '%\"\"\"\"%' OR word LIKE '%''''%'"),
            'deleted' => 0,
        ];

        if (!$dryRun) {
            $localeCounts['deleted'] = $this->deleteInvalidLocaleRows($localeConn, $localeTable, $localeDriver);
            $baseCounts['deleted'] = $this->deleteInvalidBaseRows($baseConn, $baseTable, $baseDriver);
        }

        $after = [
            'locale_total' => $this->scalarInt($localeConn, "SELECT COUNT(*) FROM {$localeTable}"),
            'locale_max_word' => $this->scalarInt($localeConn, "SELECT COALESCE(MAX(CHAR_LENGTH(word)),0) FROM {$localeTable}"),
            'base_total' => $this->scalarInt($baseConn, "SELECT COUNT(*) FROM {$baseTable}"),
            'base_max_word' => $this->scalarInt($baseConn, "SELECT COALESCE(MAX(CHAR_LENGTH(word)),0) FROM {$baseTable}"),
        ];

        return [
            'dry_run' => $dryRun,
            'locale' => $localeCounts,
            'base' => $baseCounts,
            'before' => $before,
            'after' => $after,
        ];
    }

    private function deleteInvalidLocaleRows(ConnectionFactory $conn, string $table, string $driver): int
    {
        $total = 0;
        $total += $this->execDelete($conn, "DELETE FROM {$table} WHERE CHAR_LENGTH(word) > " . WordDictionary::MAX_WORD_LENGTH);
        $total += $this->execDelete($conn, $this->sqlDeleteNewline($table, $driver));
        $total += $this->execDelete($conn, $this->sqlDeleteBom($table, $driver));
        $total += $this->execDelete($conn, "DELETE FROM {$table} WHERE word LIKE '%\"\"\"\"%' OR word LIKE '%''''%'");
        $total += $this->execDelete($conn, $this->sqlDeleteBadLocale($table, $driver));

        return $total;
    }

    private function deleteInvalidBaseRows(ConnectionFactory $conn, string $table, string $driver): int
    {
        $total = 0;
        $total += $this->execDelete($conn, "DELETE FROM {$table} WHERE CHAR_LENGTH(word) > " . WordDictionary::MAX_WORD_LENGTH);
        $total += $this->execDelete($conn, $this->sqlDeleteNewline($table, $driver));
        $total += $this->execDelete($conn, $this->sqlDeleteBom($table, $driver));
        $total += $this->execDelete($conn, "DELETE FROM {$table} WHERE word LIKE '%\"\"\"\"%' OR word LIKE '%''''%'");

        return $total;
    }

    private function sqlCountNewline(string $table, string $driver): string
    {
        if ($driver === 'pgsql') {
            return "SELECT COUNT(*) FROM {$table} WHERE POSITION(CHR(10) IN word) > 0 OR POSITION(CHR(13) IN word) > 0";
        }

        return "SELECT COUNT(*) FROM {$table} WHERE LOCATE(CHAR(10), word) > 0 OR LOCATE(CHAR(13), word) > 0";
    }

    private function sqlDeleteNewline(string $table, string $driver): string
    {
        if ($driver === 'pgsql') {
            return "DELETE FROM {$table} WHERE POSITION(CHR(10) IN word) > 0 OR POSITION(CHR(13) IN word) > 0";
        }

        return "DELETE FROM {$table} WHERE LOCATE(CHAR(10), word) > 0 OR LOCATE(CHAR(13), word) > 0";
    }

    private function sqlCountBom(string $table, string $driver): string
    {
        if ($driver === 'pgsql') {
            return "SELECT COUNT(*) FROM {$table} WHERE POSITION(CHR(65279) IN word) > 0";
        }

        return "SELECT COUNT(*) FROM {$table} WHERE LOCATE(UNHEX('EFBBBF'), word) > 0";
    }

    private function sqlDeleteBom(string $table, string $driver): string
    {
        if ($driver === 'pgsql') {
            return "DELETE FROM {$table} WHERE POSITION(CHR(65279) IN word) > 0";
        }

        return "DELETE FROM {$table} WHERE LOCATE(UNHEX('EFBBBF'), word) > 0";
    }

    private function sqlCountBadLocale(string $table, string $driver): string
    {
        if ($driver === 'pgsql') {
            return "SELECT COUNT(*) FROM {$table} WHERE locale_code = '' OR CHAR_LENGTH(locale_code) > 12 OR locale_code !~ '^[A-Za-z][A-Za-z0-9_]{1,11}$'";
        }

        return "SELECT COUNT(*) FROM {$table} WHERE locale_code = '' OR CHAR_LENGTH(locale_code) > 12 OR locale_code NOT REGEXP '^[A-Za-z][A-Za-z0-9_]{1,11}$'";
    }

    private function sqlDeleteBadLocale(string $table, string $driver): string
    {
        if ($driver === 'pgsql') {
            return "DELETE FROM {$table} WHERE locale_code = '' OR CHAR_LENGTH(locale_code) > 12 OR locale_code !~ '^[A-Za-z][A-Za-z0-9_]{1,11}$'";
        }

        return "DELETE FROM {$table} WHERE locale_code = '' OR CHAR_LENGTH(locale_code) > 12 OR locale_code NOT REGEXP '^[A-Za-z][A-Za-z0-9_]{1,11}$'";
    }

    private function driverName(ConnectionFactory $conn): string
    {
        $type = strtolower((string)$conn->getConnector()->getWrappedConnection()->getDriverType());

        return str_contains($type, 'pgsql') || str_contains($type, 'postgres') ? 'pgsql' : 'mysql';
    }

    private function scalarInt(ConnectionFactory $conn, string $sql): int
    {
        $pdo = $conn->getConnector()->getWrappedConnection()->getPdo();
        $value = $pdo->query($sql)->fetchColumn();

        return (int)$value;
    }

    private function execDelete(ConnectionFactory $conn, string $sql): int
    {
        $pdo = $conn->getConnector()->getWrappedConnection()->getPdo();
        $affected = $pdo->exec($sql);

        return $affected === false ? 0 : (int)$affected;
    }
}
