<?php

declare(strict_types=1);

namespace Weline\ModuleManager\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Database\DbManager;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Model\ModuleTable;
use Weline\ModuleManager\Model\ModuleUninstallAudit;

/**
 * 模块数据包 MDP：manifest + 表数据（v1 单文件 JSON / v2 大表 JSONL 分块）。
 *
 * 目录：var/module_data_packages/{模块名}/{package_id}/
 */
class ModuleDataPackageService
{
    public const MDP_SCHEMA_VERSION = 1;

    public const MDP_SCHEMA_VERSION_V2 = 2;

    public const RELATIVE_ROOT = 'module_data_packages';

    private const INSERT_BATCH_SIZE = 100;

    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly ModuleTable $moduleTableModel
    ) {
    }

    /**
     * @return array{
     *   success: bool,
     *   message: string,
     *   package_id?: string,
     *   package_path?: string,
     *   manifest_path?: string,
     *   table_count?: int,
     *   row_count?: int,
     *   tables?: list<array<string, mixed>>,
     *   schema_version?: int
     * }
     */
    public function createPackage(string $moduleName): array
    {
        $moduleName = trim($moduleName);
        if ($moduleName === '') {
            return ['success' => false, 'message' => __('模块名称不能为空')];
        }

        $chunkSize = max(100, (int) Env::get('mdp_chunk_rows', '10000', 'Weline_ModuleManager'));

        $items = $this->moduleTableModel->reset()
            ->where(ModuleTable::schema_fields_module_name, $moduleName)
            ->select()
            ->fetch()
            ->getItems();

        $packageId = 'mdp_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $moduleName) . '_' . date('Y-m-d_His') . '_' . bin2hex(random_bytes(3));
        $root = $this->getPackagesRoot();
        $packagePath = $root . DS . $this->sanitizeDirSegment($moduleName) . DS . $packageId;
        $tablesDir = $packagePath . DS . 'tables';

        if (!is_dir($tablesDir) && !mkdir($tablesDir, 0755, true)) {
            return ['success' => false, 'message' => __('无法创建数据包目录：%{1}', [$packagePath])];
        }

        $connection = $this->connectionFactory->getConnection();
        $registrySnapshot = [];
        $manifestTables = [];
        $totalRows = 0;
        $tableCount = 0;
        $manifestSchema = self::MDP_SCHEMA_VERSION;

        foreach ($items as $row) {
            if (!$row instanceof ModuleTable) {
                continue;
            }
            $registrySnapshot[] = [
                'module_name' => $row->getModuleName(),
                'name' => $row->getName(),
                'model' => $row->getModel(),
            ];

            $logical = $row->getName();
            if ($logical === '') {
                continue;
            }

            $physical = $this->resolvePhysicalTableName($row);
            if ($physical === null || $physical === '') {
                continue;
            }

            try {
                $countRow = $connection->query("SELECT COUNT(*) AS cnt FROM {$physical}")->fetch();
                $cnt = isset($countRow[0]['cnt'])
                    ? (int) $countRow[0]['cnt']
                    : (int) ($countRow['cnt'] ?? 0);
            } catch (\Throwable) {
                continue;
            }

            $safeBase = $this->sanitizeDirSegment($logical);

            if ($cnt > $chunkSize) {
                $manifestSchema = self::MDP_SCHEMA_VERSION_V2;
                $chunkMeta = [];
                $offset = 0;
                $part = 0;
                while ($offset < $cnt) {
                    $part++;
                    try {
                        $all = $connection->query(
                            "SELECT * FROM {$physical} LIMIT {$chunkSize} OFFSET {$offset}"
                        )->fetchAll(\PDO::FETCH_ASSOC);
                        $batch = \is_array($all) ? $all : [];
                    } catch (\Throwable $e) {
                        return [
                            'success' => false,
                            'message' => __('导出表 %{1}（分块）失败：%{2}', [$logical, $e->getMessage()]),
                        ];
                    }
                    if ($batch === []) {
                        break;
                    }
                    $chunkName = $safeBase . '_part' . str_pad((string) $part, 5, '0', STR_PAD_LEFT) . '.jsonl';
                    $chunkPath = $tablesDir . DS . $chunkName;
                    $fh = fopen($chunkPath, 'wb');
                    if ($fh === false) {
                        return ['success' => false, 'message' => __('无法写入：%{1}', [$chunkPath])];
                    }
                    $n = 0;
                    foreach ($batch as $r) {
                        fwrite($fh, json_encode($r, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
                        $n++;
                    }
                    fclose($fh);
                    $sha = hash_file('sha256', $chunkPath);
                    $rel = 'tables/' . $chunkName;
                    $chunkMeta[] = ['file' => $rel, 'row_count' => $n, 'sha256' => $sha];
                    $totalRows += $n;
                    $offset += $n;
                }
                $manifestTables[] = [
                    'logical' => $logical,
                    'physical' => $physical,
                    'model' => $row->getModel(),
                    'storage' => 'jsonl',
                    'chunks' => $chunkMeta,
                    'row_count' => $cnt,
                ];
                $tableCount++;
            } else {
                $safeFile = $safeBase . '.json';
                $dataFile = $tablesDir . DS . $safeFile;
                $rows = [];

                if ($cnt > 0) {
                    try {
                        $all = $connection->query("SELECT * FROM {$physical}")->fetchAll(\PDO::FETCH_ASSOC);
                        $rows = \is_array($all) ? $all : [];
                    } catch (\Throwable $e) {
                        return [
                            'success' => false,
                            'message' => __('导出表 %{1}（%{2}）失败：%{3}', [$logical, $physical, $e->getMessage()]),
                        ];
                    }
                }

                $payload = [
                    'logical_name' => $logical,
                    'physical_table' => $physical,
                    'model' => $row->getModel(),
                    'row_count' => \count($rows),
                    'rows' => $rows,
                ];
                $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                if (file_put_contents($dataFile, $json) === false) {
                    return ['success' => false, 'message' => __('无法写入：%{1}', [$dataFile])];
                }

                $sha = hash('sha256', $json);
                $manifestTables[] = [
                    'logical' => $logical,
                    'physical' => $physical,
                    'model' => $row->getModel(),
                    'storage' => 'inline',
                    'file' => 'tables/' . $safeFile,
                    'row_count' => \count($rows),
                    'sha256' => $sha,
                ];
                $totalRows += \count($rows);
                $tableCount++;
            }
        }

        file_put_contents(
            $packagePath . DS . 'module_table_registry.json',
            json_encode($registrySnapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)
        );

        $manifest = [
            'schema_version' => $manifestSchema,
            'module_name' => $moduleName,
            'created_at' => gmdate('c'),
            'package_id' => $packageId,
            'tables' => $manifestTables,
            'module_table_registry_snapshot' => $registrySnapshot,
            'row_count_total' => $totalRows,
            'table_count' => $tableCount,
            'mdp_chunk_rows' => $chunkSize,
        ];
        $manifestPath = $packagePath . DS . 'manifest.json';
        file_put_contents($manifestPath, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return [
            'success' => true,
            'message' => __('模块数据包已生成'),
            'package_id' => $packageId,
            'package_path' => $packagePath,
            'manifest_path' => $manifestPath,
            'table_count' => $tableCount,
            'row_count' => $totalRows,
            'tables' => $manifestTables,
            'schema_version' => $manifestSchema,
        ];
    }

    /**
     * @return list<array{path: string, module_name: string, package_id: string, created_at: string, row_count: int}>
     */
    public function listPackages(?string $moduleNameFilter = null): array
    {
        $root = $this->getPackagesRoot();
        if (!is_dir($root)) {
            return [];
        }

        $out = [];
        foreach (glob($root . DS . '*', GLOB_ONLYDIR) ?: [] as $modDir) {
            $mod = basename($modDir);
            if ($moduleNameFilter !== null && $moduleNameFilter !== '' && strcasecmp($mod, $this->sanitizeDirSegment($moduleNameFilter)) !== 0) {
                if (!str_contains(strtolower($mod), strtolower($moduleNameFilter))) {
                    continue;
                }
            }
            foreach (glob($modDir . DS . '*', GLOB_ONLYDIR) ?: [] as $pkgDir) {
                $mf = $pkgDir . DS . 'manifest.json';
                if (!is_file($mf)) {
                    continue;
                }
                $m = json_decode((string) file_get_contents($mf), true);
                if (!\is_array($m)) {
                    continue;
                }
                $out[] = [
                    'path' => $pkgDir,
                    'module_name' => (string) ($m['module_name'] ?? $mod),
                    'package_id' => (string) ($m['package_id'] ?? basename($pkgDir)),
                    'created_at' => (string) ($m['created_at'] ?? ''),
                    'row_count' => (int) ($m['row_count_total'] ?? 0),
                ];
            }
        }

        usort($out, static fn (array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));

        return $out;
    }

    /**
     * @return array{
     *   success: bool,
     *   message: string,
     *   restored_tables?: int,
     *   restored_rows?: int,
     *   dry_run?: bool
     * }
     */
    public function restoreFromPackage(
        string $packagePath,
        bool $truncateFirst = true,
        bool $dryRun = false,
        bool $logAudit = true
    ): array {
        $packagePath = rtrim(str_replace(['/', '\\'], DS, $packagePath), DS);
        $manifestFile = $packagePath . DS . 'manifest.json';
        if (!is_file($manifestFile)) {
            return ['success' => false, 'message' => __('未找到 manifest.json：%{1}', [$packagePath])];
        }

        $manifest = json_decode((string) file_get_contents($manifestFile), true);
        if (!\is_array($manifest)) {
            return ['success' => false, 'message' => __('manifest 损坏')];
        }
        $sv = (int) ($manifest['schema_version'] ?? 0);
        if ($sv !== self::MDP_SCHEMA_VERSION && $sv !== self::MDP_SCHEMA_VERSION_V2) {
            return ['success' => false, 'message' => __('不支持的 MDP 版本：%{1}', [(string) $sv])];
        }

        $connection = $this->connectionFactory->getConnection();
        $dbType = '';
        try {
            $dbType = (string) ObjectManager::getInstance(DbManager::class)->getConfig()->getDbType();
        } catch (\Throwable) {
        }
        $isPgsql = str_contains(strtolower($dbType), 'pgsql');

        $restoredTables = 0;
        $restoredRows = 0;

        foreach ($manifest['tables'] ?? [] as $t) {
            if (!\is_array($t)) {
                continue;
            }
            $physical = (string) ($t['physical'] ?? '');
            $storage = (string) ($t['storage'] ?? 'inline');

            if ($storage === 'jsonl' && !empty($t['chunks']) && \is_array($t['chunks'])) {
                $r = $this->restoreTableFromJsonlChunks(
                    $connection,
                    $packagePath,
                    $physical,
                    $t['chunks'],
                    $truncateFirst,
                    $dryRun,
                    $isPgsql
                );
                if (!$r['success']) {
                    return $r;
                }
                $restoredTables += $r['restored_tables'] ?? 0;
                $restoredRows += $r['restored_rows'] ?? 0;

                continue;
            }

            $file = $packagePath . DS . str_replace('/', DS, (string) ($t['file'] ?? ''));
            if (!is_file($file)) {
                continue;
            }
            $data = json_decode((string) file_get_contents($file), true);
            if (!\is_array($data)) {
                continue;
            }
            $physical = (string) ($data['physical_table'] ?? $physical);
            $rows = $data['rows'] ?? [];
            if ($physical === '') {
                continue;
            }
            if (!\is_array($rows) || $rows === []) {
                continue;
            }

            if ($dryRun) {
                $restoredTables++;
                $restoredRows += \count($rows);

                continue;
            }

            if ($truncateFirst) {
                try {
                    $connection->query("DELETE FROM {$physical}")->fetch();
                } catch (\Throwable $e) {
                    return [
                        'success' => false,
                        'message' => __('清空表 %{1} 失败：%{2}', [$physical, $e->getMessage()]),
                    ];
                }
            }

            try {
                $this->insertRowsInBatches($connection, $physical, $rows, $isPgsql);
            } catch (\Throwable $e) {
                return [
                    'success' => false,
                    'message' => __('恢复表 %{1} 批量插入失败：%{2}', [$physical, $e->getMessage()]),
                ];
            }
            $restoredTables++;
            $restoredRows += \count($rows);
        }

        $modName = (string) ($manifest['module_name'] ?? '');
        if (!$dryRun && $logAudit && $modName !== '') {
            try {
                $audit = ObjectManager::getInstance(ModuleUninstallAudit::class);
                /** @var ModuleUninstallAudit $audit */
                $audit->reset()->clearData()
                    ->setData(ModuleUninstallAudit::schema_fields_MODULE_NAME, $modName)
                    ->setData(ModuleUninstallAudit::schema_fields_ACTION, ModuleUninstallAudit::ACTION_RESTORE)
                    ->setData(ModuleUninstallAudit::schema_fields_PACKAGE_PATH, $packagePath)
                    ->setData(ModuleUninstallAudit::schema_fields_TABLE_COUNT, $restoredTables)
                    ->setData(ModuleUninstallAudit::schema_fields_ROW_COUNT, $restoredRows)
                    ->setData(ModuleUninstallAudit::schema_fields_CREATED_AT, date('Y-m-d H:i:s'))
                    ->save(true);
            } catch (\Throwable) {
            }
        }

        if ($dryRun) {
            return [
                'success' => true,
                'message' => __('dry-run：将恢复 %{1} 张表，约 %{2} 行（未写入数据库）', [$restoredTables, $restoredRows]),
                'restored_tables' => $restoredTables,
                'restored_rows' => $restoredRows,
                'dry_run' => true,
            ];
        }

        return [
            'success' => true,
            'message' => __('已从数据包恢复 %{1} 张表，共 %{2} 行', [$restoredTables, $restoredRows]),
            'restored_tables' => $restoredTables,
            'restored_rows' => $restoredRows,
        ];
    }

    /**
     * @param list<array{file?: string, row_count?: int}> $chunks
     *
     * @return array{success: bool, message?: string, restored_tables?: int, restored_rows?: int}
     */
    private function restoreTableFromJsonlChunks(
        $connection,
        string $packagePath,
        string $physical,
        array $chunks,
        bool $truncateFirst,
        bool $dryRun,
        bool $isPgsql
    ): array {
        $totalDry = 0;
        foreach ($chunks as $ch) {
            if (!\is_array($ch)) {
                continue;
            }
            $totalDry += (int) ($ch['row_count'] ?? 0);
        }

        if ($dryRun) {
            return [
                'success' => true,
                'restored_tables' => $chunks !== [] && $physical !== '' ? 1 : 0,
                'restored_rows' => $totalDry,
            ];
        }

        if ($physical === '') {
            return ['success' => true, 'restored_tables' => 0, 'restored_rows' => 0];
        }

        if ($truncateFirst) {
            try {
                $connection->query("DELETE FROM {$physical}")->fetch();
            } catch (\Throwable $e) {
                return [
                    'success' => false,
                    'message' => __('清空表 %{1} 失败：%{2}', [$physical, $e->getMessage()]),
                ];
            }
        }

        $restoredRows = 0;
        $cols = null;
        $batch = [];

        foreach ($chunks as $ch) {
            if (!\is_array($ch)) {
                continue;
            }
            $fp = $packagePath . DS . str_replace('/', DS, (string) ($ch['file'] ?? ''));
            if (!is_file($fp)) {
                return [
                    'success' => false,
                    'message' => __('缺少分块文件：%{1}', [$fp]),
                ];
            }
            $fh = fopen($fp, 'rb');
            if ($fh === false) {
                return ['success' => false, 'message' => __('无法读取：%{1}', [$fp])];
            }
            while (($line = fgets($fh)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                try {
                    $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                } catch (\Throwable $e) {
                    fclose($fh);

                    return ['success' => false, 'message' => __('JSONL 解析失败：%{1}', [$e->getMessage()])];
                }
                if (!\is_array($row)) {
                    continue;
                }
                if ($cols === null) {
                    $cols = array_keys($row);
                }
                $batch[] = $row;
                if (\count($batch) >= self::INSERT_BATCH_SIZE) {
                    try {
                        $this->insertRowsInBatches($connection, $physical, $batch, $isPgsql, $cols);
                    } catch (\Throwable $e) {
                        fclose($fh);

                        return [
                            'success' => false,
                            'message' => __('恢复表 %{1} 插入失败：%{2}', [$physical, $e->getMessage()]),
                        ];
                    }
                    $restoredRows += \count($batch);
                    $batch = [];
                }
            }
            fclose($fh);
        }

        if ($batch !== [] && $cols !== null) {
            try {
                $this->insertRowsInBatches($connection, $physical, $batch, $isPgsql, $cols);
            } catch (\Throwable $e) {
                return [
                    'success' => false,
                    'message' => __('恢复表 %{1} 插入失败：%{2}', [$physical, $e->getMessage()]),
                ];
            }
            $restoredRows += \count($batch);
        }

        return [
            'success' => true,
            'restored_tables' => $restoredRows > 0 || $truncateFirst ? 1 : 0,
            'restored_rows' => $restoredRows,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string>|null $fixedCols
     */
    private function insertRowsInBatches($connection, string $physical, array $rows, bool $isPgsql, ?array $fixedCols = null): void
    {
        if ($rows === []) {
            return;
        }
        $cols = $fixedCols ?? array_keys($rows[0]);
        $quotedCols = array_map(
            static function (string $c) use ($isPgsql): string {
                return $isPgsql
                    ? '"' . str_replace('"', '""', $c) . '"'
                    : '`' . str_replace('`', '``', $c) . '`';
            },
            $cols
        );

        $chunks = array_chunk($rows, self::INSERT_BATCH_SIZE);
        foreach ($chunks as $chunk) {
            $valueGroups = [];
            foreach ($chunk as $r) {
                if (!\is_array($r)) {
                    continue;
                }
                $vals = [];
                foreach ($cols as $c) {
                    $v = $r[$c] ?? null;
                    if ($v === null) {
                        $vals[] = 'NULL';
                    } else {
                        try {
                            $vals[] = $connection->quote((string) $v);
                        } catch (\Throwable) {
                            $vals[] = "'" . str_replace(["\\", "'"], ["\\\\", "''"], (string) $v) . "'";
                        }
                    }
                }
                $valueGroups[] = '(' . implode(', ', $vals) . ')';
            }
            if ($valueGroups === []) {
                continue;
            }
            $sql = 'INSERT INTO ' . $physical . ' (' . implode(', ', $quotedCols) . ') VALUES ' . implode(', ', $valueGroups);
            $connection->query($sql)->fetch();
        }
    }

    public function getPackagesRoot(): string
    {
        return BP . 'var' . DS . self::RELATIVE_ROOT;
    }

    private function resolvePhysicalTableName(ModuleTable $row): ?string
    {
        $model = $row->getModel();
        $logical = $row->getName();

        if ($model !== '' && str_starts_with($model, 'Eav::')) {
            $base = substr($model, 5);

            return $this->prefixedPhysicalName($base);
        }

        if ($model !== '' && class_exists($model)) {
            try {
                $ref = new \ReflectionClass($model);
                if ($ref->isSubclassOf(Model::class) && !$ref->isAbstract()) {
                    /** @var Model $inst */
                    $inst = ObjectManager::getInstance($model);

                    return $inst->getTable();
                }
            } catch (\Throwable) {
            }
        }

        return $this->prefixedPhysicalName($logical);
    }

    private function prefixedPhysicalName(string $logicalBase): string
    {
        $logicalBase = trim($logicalBase);
        if ($logicalBase === '') {
            return '';
        }
        try {
            $prefix = $this->connectionFactory->getConfigProvider()->getPrefix() ?: '';
        } catch (\Throwable) {
            $prefix = '';
        }
        if ($prefix !== '' && !str_starts_with($logicalBase, $prefix)) {
            return $prefix . $logicalBase;
        }

        return $logicalBase;
    }

    private function sanitizeDirSegment(string $s): string
    {
        return preg_replace('/[^a-zA-Z0-9._-]/', '_', $s) ?: 'pkg';
    }
}
