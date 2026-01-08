<?php

declare(strict_types=1);

/*
 * 数据库备份服务
 * 
 * @author Weline Framework
 * @package Weline\Maintenance\Service
 */

namespace Weline\Maintenance\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Exception\Core;
use Weline\Framework\Manager\ObjectManager;

class DatabaseBackupService
{
    private ConnectionFactory $connectionFactory;
    private $connection;
    private string $dbType;

    public function __construct(ConnectionFactory $connectionFactory)
    {
        $this->connectionFactory = $connectionFactory;
        $this->connection = $connectionFactory->getConnection();
        $this->dbType = strtolower($this->connection->getConnector()->getDriverName());
    }

    /**
     * 备份整个数据库
     * 
     * @param string $outputFile 输出文件路径
     * @param array $excludeTables 排除的表名数组
     * @return string 备份文件路径
     */
    public function backupDatabase(string $outputFile, array $excludeTables = []): string
    {
        // 尝试使用系统命令备份（更快）
        if ($this->trySystemCommandBackup($outputFile, $excludeTables)) {
            return $outputFile;
        }

        // 回退到程序化备份
        return $this->programmaticBackup($outputFile, $excludeTables);
    }

    /**
     * 备份指定表
     * 
     * @param array $tables 要备份的表名数组
     * @param string $outputFile 输出文件路径
     * @return string 备份文件路径
     */
    public function backupTables(array $tables, string $outputFile): string
    {
        $dir = dirname($outputFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $fp = @fopen($outputFile, 'w');
        if (!$fp) {
            throw new Core(__('无法创建备份文件：%{1}', $outputFile));
        }

        try {
            // 写入备份头部信息
            $this->writeBackupHeader($fp);

            $connector = $this->connection->getConnector();
            
            foreach ($tables as $table) {
                $this->backupTable($table, $fp, $connector);
            }

            // 写入备份尾部信息
            $this->writeBackupFooter($fp);

            fclose($fp);
            
            return $outputFile;
        } catch (\Exception $e) {
            @fclose($fp);
            @unlink($outputFile);
            throw new Core(__('备份数据库失败：%{1}', $e->getMessage()));
        }
    }

    /**
     * 获取所有表名
     * 
     * @return array
     */
    public function getAllTables(): array
    {
        $connector = $this->connection->getConnector();
        $tables = [];

        try {
            if ($this->dbType === 'mysql') {
                $result = $connector->query("SHOW TABLES")->fetch();
                foreach ($result as $row) {
                    $tables[] = reset($row);
                }
            } elseif ($this->dbType === 'pgsql') {
                $result = $connector->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public'")->fetch();
                foreach ($result as $row) {
                    $tables[] = $row['tablename'];
                }
            } elseif ($this->dbType === 'sqlite') {
                $result = $connector->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetch();
                foreach ($result as $row) {
                    $tables[] = $row['name'];
                }
            }

            return $tables;
        } catch (\Exception $e) {
            throw new Core(__('获取数据库表列表失败：%{1}', $e->getMessage()));
        }
    }

    /**
     * 尝试使用系统命令备份
     * 
     * @param string $outputFile
     * @param array $excludeTables
     * @return bool
     */
    private function trySystemCommandBackup(string $outputFile, array $excludeTables): bool
    {
        $connector = $this->connection->getConnector();
        $config = $connector->getConfigProvider();

        if ($this->dbType === 'mysql') {
            return $this->mysqlDump($config, $outputFile, $excludeTables);
        } elseif ($this->dbType === 'pgsql') {
            return $this->pgDump($config, $outputFile, $excludeTables);
        }

        return false;
    }

    /**
     * MySQL mysqldump备份
     * 
     * @param mixed $config
     * @param string $outputFile
     * @param array $excludeTables
     * @return bool
     */
    private function mysqlDump($config, string $outputFile, array $excludeTables): bool
    {
        // 检查mysqldump是否可用
        $mysqldump = $this->findExecutable('mysqldump');
        if (!$mysqldump) {
            return false;
        }

        $host = $config->getHost() ?? '127.0.0.1';
        $port = $config->getPort() ?? 3306;
        $username = $config->getUsername() ?? '';
        $password = $config->getPassword() ?? '';
        $database = $config->getDbname() ?? '';

        $command = sprintf(
            '%s -h %s -P %d -u %s -p%s %s',
            escapeshellarg($mysqldump),
            escapeshellarg($host),
            $port,
            escapeshellarg($username),
            escapeshellarg($password),
            escapeshellarg($database)
        );

        // 排除指定表
        if (!empty($excludeTables)) {
            foreach ($excludeTables as $table) {
                $command .= ' --ignore-table=' . escapeshellarg($database . '.' . $table);
            }
        }

        $command .= ' > ' . escapeshellarg($outputFile) . ' 2>&1';

        @exec($command, $output, $returnCode);

        return $returnCode === 0 && is_file($outputFile) && filesize($outputFile) > 0;
    }

    /**
     * PostgreSQL pg_dump备份
     * 
     * @param mixed $config
     * @param string $outputFile
     * @param array $excludeTables
     * @return bool
     */
    private function pgDump($config, string $outputFile, array $excludeTables): bool
    {
        $pgdump = $this->findExecutable('pg_dump');
        if (!$pgdump) {
            return false;
        }

        $host = $config->getHost() ?? '127.0.0.1';
        $port = $config->getPort() ?? 5432;
        $username = $config->getUsername() ?? '';
        $password = $config->getPassword() ?? '';
        $database = $config->getDbname() ?? '';

        $env = [
            'PGPASSWORD' => $password,
        ];

        $command = sprintf(
            'PGPASSWORD=%s %s -h %s -p %d -U %s -d %s -f %s',
            escapeshellarg($password),
            escapeshellarg($pgdump),
            escapeshellarg($host),
            $port,
            escapeshellarg($username),
            escapeshellarg($database),
            escapeshellarg($outputFile)
        );

        // PostgreSQL的排除表比较复杂，这里先简化处理
        // 如果需要排除表，建议使用程序化备份

        @exec($command, $output, $returnCode);

        return $returnCode === 0 && is_file($outputFile) && filesize($outputFile) > 0;
    }

    /**
     * 程序化备份（不使用系统命令）
     * 
     * @param string $outputFile
     * @param array $excludeTables
     * @return string
     */
    private function programmaticBackup(string $outputFile, array $excludeTables): string
    {
        $dir = dirname($outputFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $fp = @fopen($outputFile, 'w');
        if (!$fp) {
            throw new Core(__('无法创建备份文件：%{1}', $outputFile));
        }

        try {
            $this->writeBackupHeader($fp);

            $connector = $this->connection->getConnector();
            $tables = $this->getAllTables();

            foreach ($tables as $table) {
                if (in_array($table, $excludeTables)) {
                    continue;
                }
                $this->backupTable($table, $fp, $connector);
            }

            $this->writeBackupFooter($fp);
            fclose($fp);

            return $outputFile;
        } catch (\Exception $e) {
            @fclose($fp);
            @unlink($outputFile);
            throw new Core(__('程序化备份失败：%{1}', $e->getMessage()));
        }
    }

    /**
     * 备份单个表
     * 
     * @param string $table
     * @param resource $fp
     * @param mixed $connector
     * @return void
     */
    private function backupTable(string $table, $fp, $connector): void
    {
        // 获取建表语句
        try {
            $createTableSql = $connector->getCreateTableSql($table);
            
            // 写入建表语句
            fwrite($fp, "\n-- \n-- 表结构：{$table}\n-- \n");
            fwrite($fp, "DROP TABLE IF EXISTS `{$table}`;\n");
            fwrite($fp, $createTableSql . ";\n\n");

            // 备份表数据
            $this->backupTableData($table, $fp, $connector);
        } catch (\Exception $e) {
            // 如果表不存在或出错，记录但不中断
            fwrite($fp, "\n-- 表 {$table} 备份失败：{$e->getMessage()}\n");
        }
    }

    /**
     * 备份表数据
     * 
     * @param string $table
     * @param resource $fp
     * @param mixed $connector
     * @return void
     */
    private function backupTableData(string $table, $fp, $connector): void
    {
        try {
            $query = $connector->query("SELECT * FROM `{$table}`");
            $rows = $query->fetch();

            if (empty($rows)) {
                return;
            }

            fwrite($fp, "\n-- \n-- 表数据：{$table}\n-- \n");

            // 获取列名
            $firstRow = reset($rows);
            $columns = array_keys(is_array($firstRow) ? $firstRow : (array)$firstRow);

            foreach ($rows as $row) {
                $values = [];
                foreach ($columns as $column) {
                    $value = is_array($row) ? $row[$column] : $row->$column ?? null;
                    if ($value === null) {
                        $values[] = 'NULL';
                    } else {
                        $values[] = "'" . addslashes($value) . "'";
                    }
                }

                $sql = "INSERT INTO `{$table}` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $values) . ");\n";
                fwrite($fp, $sql);
            }

            fwrite($fp, "\n");
        } catch (\Exception $e) {
            fwrite($fp, "-- 表 {$table} 数据备份失败：{$e->getMessage()}\n");
        }
    }

    /**
     * 写入备份头部信息
     * 
     * @param resource $fp
     * @return void
     */
    private function writeBackupHeader($fp): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $header = <<<SQL
-- 
-- 数据库备份文件
-- 备份时间：{$timestamp}
-- 数据库类型：{$this->dbType}
-- 

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";


SQL;
        fwrite($fp, $header);
    }

    /**
     * 写入备份尾部信息
     * 
     * @param resource $fp
     * @return void
     */
    private function writeBackupFooter($fp): void
    {
        fwrite($fp, "\n-- 备份完成\n");
    }

    /**
     * 查找可执行文件
     * 
     * @param string $name
     * @return string|null
     */
    private function findExecutable(string $name): ?string
    {
        $paths = [
            '/usr/bin/' . $name,
            '/usr/local/bin/' . $name,
            '/bin/' . $name,
            $name, // 尝试直接使用（可能在PATH中）
        ];

        foreach ($paths as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        // 尝试使用which/where命令查找
        $command = (PHP_OS_FAMILY === 'Windows') ? "where {$name}" : "which {$name}";
        @exec($command, $output, $returnCode);
        
        if ($returnCode === 0 && !empty($output[0]) && is_executable($output[0])) {
            return $output[0];
        }

        return null;
    }
}
