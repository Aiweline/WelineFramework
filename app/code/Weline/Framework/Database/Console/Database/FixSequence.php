<?php
declare(strict_types=1);

/*
 * PostgreSQL 序列修复命令
 * 修复因直接插入数据导致的序列不同步问题
 */

namespace Weline\Framework\Database\Console\Database;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Database\DbManager;

class FixSequence extends CommandAbstract
{
    public const ALIASES = ['db:fix-sequence', 'db:fs'];

    private DbManager $dbManager;

    public function __construct(DbManager $dbManager)
    {
        $this->dbManager = $dbManager;
    }

    public function tip(): string
    {
        return '修复 PostgreSQL 序列不同步问题';
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'db:fix-sequence',
            $this->tip(),
            [
                'table' => '表名（可选，不指定则修复所有表）',
                '-c, --connection' => '指定数据库连接名',
            ],
            [
                'php bin/m db:fix-sequence m_website' => '修复 m_website 表的序列',
                'php bin/m db:fix-sequence' => '修复所有表的序列',
            ],
            [
                '此命令仅适用于 PostgreSQL 数据库',
                '用于修复因直接插入带 ID 数据导致的主键序列不同步',
            ]
        );
    }

    public function execute(array $arguments = [], array $options = []): bool
    {
        $tableName = $arguments['table'] ?? null;
        if (!is_string($tableName) || trim($tableName) === '') {
            foreach ($arguments as $arg) {
                if (!is_string($arg)) {
                    continue;
                }
                $candidate = trim($arg);
                if ($candidate === '' || str_contains($candidate, ':')) {
                    continue;
                }
                $tableName = $candidate;
                break;
            }
        }
        if (!is_string($tableName) || trim($tableName) === '') {
            $tableName = null;
        } else {
            $tableName = trim($tableName);
        }
        $connectionName = $options['c'] ?? $options['connection'] ?? 'default';

        try {
            // 使用 create() 确保连接被正确初始化
            $connection = $this->dbManager->create($connectionName);
            $connector = $connection->getConnector();

            // 检查是否是 PostgreSQL
            if (!($connector instanceof \Weline\Framework\Database\Connection\Adapter\Pgsql\Connector)) {
                $this->printer->warning(__('此命令仅适用于 PostgreSQL 数据库'));
                return true;
            }

            $pdo = $connector->getLink();

            if ($tableName) {
                // 修复单个表
                $this->fixTableSequence($pdo, $tableName);
            } else {
                // 修复所有表
                $this->fixAllSequences($pdo);
            }

            $this->printer->success(__('序列修复完成！'));
            return true;

        } catch (\Exception $e) {
            $this->printer->error(__('修复失败: %{1}', [$e->getMessage()]));
            return false;
        }
    }

    private function fixTableSequence(\PDO $pdo, string $tableName): void
    {
        $this->printer->note(__('正在修复表 %{1} 的序列...', [$tableName]));

        // 获取表的主键列
        $sql = "SELECT a.attname as column_name
                FROM pg_index i
                JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
                WHERE i.indrelid = '\"{$tableName}\"'::regclass
                AND i.indisprimary";
        
        $stmt = $pdo->query($sql);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$result) {
            $this->printer->warning(__('表 %{1} 没有主键，跳过', [$tableName]));
            return;
        }

        $primaryKey = $result['column_name'];
        $sequenceName = "{$tableName}_{$primaryKey}_seq";

        // 检查序列是否存在
        $seqCheckSql = "SELECT 1 FROM pg_sequences WHERE schemaname = 'public' AND sequencename = :seq_name";
        $seqStmt = $pdo->prepare($seqCheckSql);
        $seqStmt->execute(['seq_name' => $sequenceName]);
        
        if (!$seqStmt->fetch()) {
            $this->printer->warning(__('序列 %{1} 不存在，跳过', [$sequenceName]));
            return;
        }

        // 获取当前最大 ID
        $maxSql = "SELECT COALESCE(MAX(\"{$primaryKey}\"), 0) as max_id FROM \"{$tableName}\"";
        $maxStmt = $pdo->query($maxSql);
        $maxResult = $maxStmt->fetch(\PDO::FETCH_ASSOC);
        $maxId = (int)$maxResult['max_id'];

        // 重置序列：
        // - 有数据时，设为 max_id 且 is_called=true，下一次 nextval 返回 max_id+1
        // - 空表时，设为 1 且 is_called=false，下一次 nextval 返回 1，避免 setval(0, true) 越界
        $setValue = $maxId > 0 ? $maxId : 1;
        $isCalled = $maxId > 0 ? 'true' : 'false';
        $resetSql = "SELECT setval('\"{$sequenceName}\"', {$setValue}, {$isCalled})";
        $pdo->exec($resetSql);

        $this->printer->success(__('✓ 表 %{1}: 序列 %{2} 重置为 %{3}', [$tableName, $sequenceName, $setValue]));
    }

    private function fixAllSequences(\PDO $pdo): void
    {
        $this->printer->note(__('正在查找所有需要修复的序列...'));

        // 获取所有以 m_ 开头的表
        $sql = "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'm_%'";
        $stmt = $pdo->query($sql);
        $tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        if (empty($tables)) {
            $this->printer->warning(__('没有找到需要修复的表'));
            return;
        }

        $this->printer->note(__('找到 %{1} 个表', [count($tables)]));

        foreach ($tables as $tableName) {
            try {
                $this->fixTableSequence($pdo, $tableName);
            } catch (\Exception $e) {
                $this->printer->warning(__('表 %{1} 修复失败: %{2}', [$tableName, $e->getMessage()]));
            }
        }
    }
}
