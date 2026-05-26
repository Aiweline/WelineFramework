<?php

declare(strict_types=1);

namespace Weline\Database\Service\Admin;

use Weline\Framework\Database\ConnectionFactory;

class SqlImportService
{
    private const MAX_SQL_BYTES = 26214400;
    private const CONFIRM_PHRASE = 'I_UNDERSTAND_SQL_IMPORT';

    public function __construct(
        private readonly ConnectionFactory $connectionFactory
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function prepare(string $sql, string $originalName, int|string|null $userId, ?string $username, string $clientIp): array
    {
        $sql = $this->normalizeSql($sql);
        $bytes = strlen($sql);
        if ($bytes <= 0) {
            throw new \InvalidArgumentException((string)__('SQL 文件内容不能为空'));
        }
        if ($bytes > self::MAX_SQL_BYTES) {
            throw new \InvalidArgumentException((string)__('SQL 文件不能超过 25MB'));
        }

        $analysis = $this->analyze($sql);
        if (!empty($analysis['banned_keywords'])) {
            throw new \InvalidArgumentException((string)__(
                'SQL 导入暂不允许 DDL/权限类高危语句：%{1}',
                [implode(', ', $analysis['banned_keywords'])]
            ));
        }
        if (!empty($analysis['has_dynamic_execute'])) {
            throw new \InvalidArgumentException((string)__('SQL 导入暂不允许动态 EXECUTE 语句'));
        }
        if (empty($analysis['target_tables'])) {
            throw new \InvalidArgumentException((string)__('无法识别 SQL 将修改的数据表，已拒绝导入'));
        }
        if (!empty($analysis['missing_tables'])) {
            throw new \InvalidArgumentException((string)__(
                'SQL 引用了不存在的数据表：%{1}',
                [implode(', ', $analysis['missing_tables'])]
            ));
        }

        $token = bin2hex(random_bytes(16));
        $this->ensureStorage();

        $sqlPath = $this->sqlPath($token);
        file_put_contents($sqlPath, $sql, LOCK_EX);
        @chmod($sqlPath, 0600);

        $backupPath = $this->backupPath($token);
        $backupInfo = $this->createBackup($backupPath, $analysis['existing_target_tables'], $originalName, $analysis);

        $manifest = [
            'token' => $token,
            'original_name' => $this->sanitizeFilename($originalName !== '' ? $originalName : 'import.sql'),
            'sql_path' => $sqlPath,
            'backup_path' => $backupPath,
            'sql_sha256' => hash('sha256', $sql),
            'sql_bytes' => $bytes,
            'analysis' => $analysis,
            'backup' => $backupInfo,
            'created_at' => date('Y-m-d H:i:s'),
            'created_by' => (string)($userId ?? ''),
            'created_username' => (string)($username ?? ''),
            'created_ip' => $clientIp,
            'backup_downloaded_at' => '',
            'executed_at' => '',
            'status' => 'prepared',
            'message' => '',
        ];
        $this->writeManifest($token, $manifest);

        return [
            'token' => $token,
            'sql_sha256' => $manifest['sql_sha256'],
            'sql_bytes' => $bytes,
            'target_tables' => $analysis['target_tables'],
            'statement_count' => $analysis['statement_count'],
            'backup_filename' => basename($backupPath),
            'backup_bytes' => filesize($backupPath) ?: 0,
            'backup_tables' => $backupInfo['tables'],
            'guarded_missing_tables' => $analysis['guarded_missing_tables'],
            'confirm_phrase' => self::CONFIRM_PHRASE,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function execute(string $token, bool $backupConfirmed, string $confirmPhrase): array
    {
        $manifest = $this->readManifest($token);
        if (($manifest['status'] ?? '') === 'executed') {
            throw new \RuntimeException((string)__('该 SQL 导入任务已经执行过'));
        }
        if (($manifest['backup_downloaded_at'] ?? '') === '') {
            throw new \RuntimeException((string)__('必须先下载备份文件，然后才能执行导入'));
        }
        if (!$backupConfirmed) {
            throw new \RuntimeException((string)__('请确认备份文件已下载到本地'));
        }
        if ($confirmPhrase !== self::CONFIRM_PHRASE) {
            throw new \RuntimeException((string)__(
                'SQL 导入必须输入确认短语 %{1}',
                [self::CONFIRM_PHRASE]
            ));
        }

        $sqlPath = (string)($manifest['sql_path'] ?? '');
        if (!is_file($sqlPath)) {
            throw new \RuntimeException((string)__('暂存 SQL 文件不存在'));
        }
        $sql = (string)file_get_contents($sqlPath);
        if (hash('sha256', $sql) !== (string)($manifest['sql_sha256'] ?? '')) {
            throw new \RuntimeException((string)__('暂存 SQL 文件校验失败'));
        }

        $analysis = $this->analyze($sql);
        if (!empty($analysis['banned_keywords']) || !empty($analysis['has_dynamic_execute']) || !empty($analysis['missing_tables'])) {
            throw new \RuntimeException((string)__('SQL 导入前安全检查失败'));
        }

        $connection = $this->connectionFactory->getConnector()->getWrappedConnection();
        $start = microtime(true);
        try {
            $affected = $connection->execute($sql);
            $manifest['status'] = 'executed';
            $manifest['executed_at'] = date('Y-m-d H:i:s');
            $manifest['message'] = 'ok';
            $manifest['affected_rows'] = $affected;
            $manifest['elapsed_ms'] = (int)round((microtime(true) - $start) * 1000);
            $this->writeManifest($token, $manifest);

            return [
                'token' => $token,
                'status' => 'executed',
                'affected_rows' => $affected,
                'elapsed_ms' => $manifest['elapsed_ms'],
                'target_tables' => $analysis['target_tables'],
                'sql_sha256' => $manifest['sql_sha256'],
            ];
        } catch (\Throwable $throwable) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            $manifest['status'] = 'failed';
            $manifest['executed_at'] = date('Y-m-d H:i:s');
            $manifest['message'] = $throwable->getMessage();
            $this->writeManifest($token, $manifest);
            throw $throwable;
        }
    }

    /**
     * @return array{path:string,filename:string,bytes:int,manifest:array<string,mixed>}
     */
    public function markBackupDownloaded(string $token): array
    {
        $manifest = $this->readManifest($token);
        $backupPath = (string)($manifest['backup_path'] ?? '');
        if (!is_file($backupPath)) {
            throw new \RuntimeException((string)__('备份文件不存在'));
        }
        $manifest['backup_downloaded_at'] = date('Y-m-d H:i:s');
        $this->writeManifest($token, $manifest);

        return [
            'path' => $backupPath,
            'filename' => basename($backupPath),
            'bytes' => filesize($backupPath) ?: 0,
            'manifest' => $manifest,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function readManifest(string $token): array
    {
        $this->validateToken($token);
        $path = $this->manifestPath($token);
        if (!is_file($path)) {
            throw new \RuntimeException((string)__('SQL 导入任务不存在'));
        }
        $decoded = json_decode((string)file_get_contents($path), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException((string)__('SQL 导入任务清单损坏'));
        }
        return $decoded;
    }

    /**
     * @return array<string,mixed>
     */
    public function analyze(string $sql): array
    {
        $codeSql = $this->stripCommentsAndStringLiterals($sql);
        $commentStrippedSql = $this->stripComments($sql);
        $banned = $this->extractBannedKeywords($codeSql);

        $tables = array_values(array_unique(array_merge(
            $this->extractTargetTables($codeSql),
            $this->extractTargetTables($commentStrippedSql)
        )));
        sort($tables);
        $existing = [];
        $missing = [];
        $guardedMissing = [];
        foreach ($tables as $table) {
            if ($this->tableExists($table)) {
                $existing[] = $table;
                continue;
            }
            if ($this->hasMissingTableSkipGuard($sql, $table)) {
                $guardedMissing[] = $table;
            } else {
                $missing[] = $table;
            }
        }

        return [
            'is_write' => (bool)preg_match('/\b(INSERT|UPDATE|DELETE|MERGE|CALL|DO)\b/i', $commentStrippedSql),
            'statement_count' => $this->countStatements($codeSql),
            'banned_keywords' => $banned,
            'has_dynamic_execute' => (bool)preg_match('/\bEXECUTE\b/i', $codeSql),
            'target_tables' => $tables,
            'existing_target_tables' => $existing,
            'missing_tables' => $missing,
            'guarded_missing_tables' => $guardedMissing,
            'preview' => mb_substr(trim(preg_replace('/\s+/', ' ', $codeSql) ?? ''), 0, 400),
        ];
    }

    /**
     * @return list<string>
     */
    private function extractBannedKeywords(string $sql): array
    {
        $banned = [];
        if (preg_match_all(
            '/(?:^|;|\bBEGIN\b|\bTHEN\b|\bELSE\b)\s*(DROP|TRUNCATE|ALTER|CREATE|RENAME|GRANT|REVOKE)\b/i',
            $sql,
            $matches
        )) {
            $banned = array_values(array_unique(array_map('strtoupper', $matches[1])));
            sort($banned);
        }
        return $banned;
    }

    private function stripComments(string $sql): string
    {
        $result = '';
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            if ($char === '-' && ($sql[$i + 1] ?? '') === '-') {
                $result .= ' ';
                $i += 2;
                while ($i < $length && $sql[$i] !== "\n" && $sql[$i] !== "\r") {
                    $i++;
                }
                if ($i < $length) {
                    $result .= $sql[$i];
                }
                continue;
            }
            if ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
                $result .= ' ';
                $i += 2;
                while ($i + 1 < $length && !($sql[$i] === '*' && $sql[$i + 1] === '/')) {
                    if ($sql[$i] === "\n" || $sql[$i] === "\r") {
                        $result .= $sql[$i];
                    }
                    $i++;
                }
                $i++;
                continue;
            }
            $result .= $char;
        }
        return $result;
    }

    private function hasMissingTableSkipGuard(string $sql, string $table): bool
    {
        $tablePattern = preg_quote($table, '/');
        $identifier = '(?:"[^"]+"|[A-Za-z_][A-Za-z0-9_]*)';
        $pattern = '/to_regclass\s*\(\s*\'(?:(?:' . $identifier . ')\.)?"?' . $tablePattern . '"?\'\s*\)\s+IS\s+NULL/i';
        return (bool)preg_match($pattern, $sql);
    }

    private function createBackup(string $backupPath, array $tables, string $originalName, array $analysis): array
    {
        $connection = $this->connectionFactory->getConnector()->getWrappedConnection();
        $schema = $this->currentSchema();
        $totalRows = 0;
        $backupDir = dirname($backupPath);
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0775, true);
        }

        $handle = fopen($backupPath, 'wb');
        if ($handle === false) {
            throw new \RuntimeException((string)__('无法创建备份文件'));
        }

        try {
            fwrite($handle, "-- Weline Database SQL import affected-table backup\n");
            fwrite($handle, '-- Created at: ' . date('Y-m-d H:i:s') . "\n");
            fwrite($handle, '-- Source SQL: ' . $this->sanitizeFilename($originalName) . "\n");
            fwrite($handle, '-- Target tables: ' . implode(', ', $tables) . "\n");
            fwrite($handle, "-- This backup restores data for affected tables only.\n\n");
            fwrite($handle, "BEGIN;\n\n");

            foreach ($tables as $table) {
                $columns = $this->tableColumns($schema, $table);
                if ($columns === []) {
                    continue;
                }
                $quotedTable = $this->quoteQualifiedTable($schema, $table);
                fwrite($handle, "-- Table: {$table}\n");
                fwrite($handle, "DELETE FROM {$quotedTable};\n");

                $stmt = $connection->prepare('SELECT * FROM ' . $quotedTable);
                $stmt->execute();
                $rowCount = 0;
                while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                    $values = [];
                    foreach ($columns as $column) {
                        $values[] = array_key_exists($column, $row) && $row[$column] !== null
                            ? $connection->quote((string)$row[$column])
                            : 'NULL';
                    }
                    fwrite(
                        $handle,
                        'INSERT INTO ' . $quotedTable . ' ('
                        . implode(', ', array_map([$this, 'quoteIdentifier'], $columns))
                        . ') VALUES (' . implode(', ', $values) . ");\n"
                    );
                    $rowCount++;
                }
                $totalRows += $rowCount;
                fwrite($handle, "\n");
            }

            fwrite($handle, "COMMIT;\n");
        } finally {
            fclose($handle);
        }
        @chmod($backupPath, 0600);

        return [
            'tables' => $tables,
            'rows' => $totalRows,
            'bytes' => filesize($backupPath) ?: 0,
            'analysis_preview' => $analysis['preview'] ?? '',
        ];
    }

    /**
     * @return list<string>
     */
    private function extractTargetTables(string $sql): array
    {
        $tables = [];
        if (preg_match_all(
            '/\b(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+((?:"[^"]+"|[A-Za-z_][A-Za-z0-9_]*)(?:\s*\.\s*(?:"[^"]+"|[A-Za-z_][A-Za-z0-9_]*))?)/i',
            $sql,
            $matches
        )) {
            foreach ($matches[1] as $rawTable) {
                $table = $this->normalizeTableName((string)$rawTable);
                if ($table !== '') {
                    $tables[] = $table;
                }
            }
        }

        $tables = array_values(array_unique($tables));
        sort($tables);
        return $tables;
    }

    private function normalizeTableName(string $table): string
    {
        $table = trim(str_replace(["\r", "\n", "\t"], ' ', $table));
        $parts = array_map('trim', explode('.', $table));
        $tablePart = end($parts);
        $tablePart = trim((string)$tablePart, " \t\n\r\0\x0B\"");
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $tablePart)) {
            return '';
        }
        return $tablePart;
    }

    private function tableExists(string $table): bool
    {
        $connection = $this->connectionFactory->getConnector()->getWrappedConnection();
        $stmt = $connection->prepare(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = :schema AND table_name = :table LIMIT 1'
        );
        $stmt->execute([':schema' => $this->currentSchema(), ':table' => $table]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * @return list<string>
     */
    private function tableColumns(string $schema, string $table): array
    {
        $connection = $this->connectionFactory->getConnector()->getWrappedConnection();
        $stmt = $connection->prepare(
            'SELECT column_name FROM information_schema.columns '
            . 'WHERE table_schema = :schema AND table_name = :table ORDER BY ordinal_position'
        );
        $stmt->execute([':schema' => $schema, ':table' => $table]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
    }

    private function currentSchema(): string
    {
        $connection = $this->connectionFactory->getConnector()->getWrappedConnection();
        $stmt = $connection->prepare('SELECT current_schema()');
        $stmt->execute();
        return (string)($stmt->fetchColumn() ?: 'public');
    }

    private function quoteQualifiedTable(string $schema, string $table): string
    {
        return $this->quoteIdentifier($schema) . '.' . $this->quoteIdentifier($table);
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private function stripCommentsAndStringLiterals(string $sql): string
    {
        $result = '';
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            if ($char === '-' && ($sql[$i + 1] ?? '') === '-') {
                $result .= ' ';
                $i += 2;
                while ($i < $length && $sql[$i] !== "\n" && $sql[$i] !== "\r") {
                    $i++;
                }
                if ($i < $length) {
                    $result .= $sql[$i];
                }
                continue;
            }
            if ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
                $result .= ' ';
                $i += 2;
                while ($i + 1 < $length && !($sql[$i] === '*' && $sql[$i + 1] === '/')) {
                    if ($sql[$i] === "\n" || $sql[$i] === "\r") {
                        $result .= $sql[$i];
                    }
                    $i++;
                }
                $i++;
                continue;
            }
            $isEscapeString = ($char === 'E' || $char === 'e')
                && ($sql[$i + 1] ?? '') === "'"
                && ($i === 0 || !preg_match('/[A-Za-z0-9_]/', $sql[$i - 1]));
            if ($isEscapeString) {
                $result .= ' ';
                $i = $this->consumeSingleQuotedString($sql, $i + 1, true);
                continue;
            }
            if ($char === "'") {
                $result .= ' ';
                $i = $this->consumeSingleQuotedString($sql, $i, false);
                continue;
            }
            $result .= $char;
        }
        return $result;
    }

    private function consumeSingleQuotedString(string $sql, int $quoteOffset, bool $backslashEscapes): int
    {
        $length = strlen($sql);
        for ($i = $quoteOffset + 1; $i < $length; $i++) {
            if ($backslashEscapes && $sql[$i] === '\\') {
                $i++;
                continue;
            }
            if ($sql[$i] === "'" && ($sql[$i + 1] ?? '') === "'") {
                $i++;
                continue;
            }
            if ($sql[$i] === "'") {
                return $i;
            }
        }
        return $length - 1;
    }

    private function countStatements(string $sql): int
    {
        $parts = preg_split('/;/', $sql) ?: [];
        $count = 0;
        foreach ($parts as $part) {
            if (trim($part) !== '') {
                $count++;
            }
        }
        return $count;
    }

    private function normalizeSql(string $sql): string
    {
        $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql;
        if (str_contains($sql, "\0")) {
            throw new \InvalidArgumentException((string)__('SQL 文件包含非法空字节'));
        }
        return trim($sql);
    }

    private function storageRoot(): string
    {
        return BP . 'var' . DS . 'database' . DS . 'sql-import' . DS;
    }

    private function ensureStorage(): void
    {
        foreach (['pending', 'backup', 'manifest'] as $dir) {
            $path = $this->storageRoot() . $dir;
            if (!is_dir($path)) {
                mkdir($path, 0775, true);
            }
        }
    }

    private function sqlPath(string $token): string
    {
        return $this->storageRoot() . 'pending' . DS . $token . '.sql';
    }

    private function backupPath(string $token): string
    {
        return $this->storageRoot() . 'backup' . DS . 'weline_sql_import_backup_' . $token . '.sql';
    }

    private function manifestPath(string $token): string
    {
        return $this->storageRoot() . 'manifest' . DS . $token . '.json';
    }

    /**
     * @param array<string,mixed> $manifest
     */
    private function writeManifest(string $token, array $manifest): void
    {
        $this->ensureStorage();
        file_put_contents(
            $this->manifestPath($token),
            json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            LOCK_EX
        );
        @chmod($this->manifestPath($token), 0600);
    }

    private function validateToken(string $token): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            throw new \InvalidArgumentException((string)__('非法导入令牌'));
        }
    }

    private function sanitizeFilename(string $filename): string
    {
        $filename = basename($filename);
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?? 'import.sql';
        return trim($filename, '._-') !== '' ? $filename : 'import.sql';
    }
}
