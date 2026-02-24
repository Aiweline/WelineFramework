<?php

declare(strict_types=1);

/*
 * 备份管理器
 * 统一管理备份操作
 * 
 * @author Weline Framework
 * @package Weline\Maintenance\Service
 */

namespace Weline\Maintenance\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Exception\Core;
use Weline\Framework\Manager\ObjectManager;
use Weline\Maintenance\Model\Backup;
use Weline\Maintenance\Storage\LocalStorage;
use Weline\Maintenance\Storage\BackupStorageInterface;

class BackupManager
{
    private DatabaseBackupService $databaseBackupService;
    private FileBackupService $fileBackupService;
    private BackupStorageInterface $storage;
    private Backup $backupModel;

    public function __construct(
        DatabaseBackupService $databaseBackupService,
        FileBackupService $fileBackupService,
        Backup $backupModel
    ) {
        $this->databaseBackupService = $databaseBackupService;
        $this->fileBackupService = $fileBackupService;
        $this->backupModel = $backupModel;
        $this->storage = ObjectManager::getInstance(LocalStorage::class);
    }

    /**
     * 创建完整备份（数据库+代码）
     * 
     * @param int|null $createdBy 创建人ID
     * @return array 备份信息
     */
    public function createFullBackup(?int $createdBy = null): array
    {
        $timestamp = date('Ymd_His');
        $backupName = 'full_backup_' . $timestamp;

        $result = [
            'backup_type' => 'full',
            'backup_name' => $backupName,
            'files' => [],
            'total_size' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        try {
            // 备份数据库
            $dbBackupFile = $this->createDatabaseBackup($backupName, $createdBy);
            if ($dbBackupFile) {
                $result['files']['database'] = $dbBackupFile;
                $result['total_size'] += filesize($dbBackupFile);
            }

            // 备份代码
            $codeBackupFile = $this->createCodeBackup($backupName, $createdBy);
            if ($codeBackupFile) {
                $result['files']['code'] = $codeBackupFile;
                $result['total_size'] += filesize($codeBackupFile);
            }

            return $result;
        } catch (\Exception $e) {
            // 清理已创建的备份文件
            foreach ($result['files'] as $file) {
                @unlink($file);
            }
            throw new Core(__('创建完整备份失败：%{1}', $e->getMessage()));
        }
    }

    /**
     * 创建数据库备份
     * 
     * @param string $backupName 备份名称
     * @param int|null $createdBy 创建人ID
     * @return string 备份文件路径
     */
    public function createDatabaseBackup(string $backupName, ?int $createdBy = null): string
    {
        $timestamp = date('Ymd_His');
        $fileName = $backupName . '_database_' . $timestamp . '.sql';
        $tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $fileName;

        try {
            // 执行数据库备份
            $this->databaseBackupService->backupDatabase($tempFile);

            // 保存到存储
            $storedPath = $this->storage->save($tempFile, $fileName);

            // 记录到数据库
            $this->backupModel->clear()
                ->setData('backup_type', 'database')
                ->setData('backup_name', $fileName)
                ->setData('file_path', $storedPath)
                ->setData('file_size', filesize($storedPath))
                ->setData('status', 'completed')
                ->setData('created_at', date('Y-m-d H:i:s'))
                ->setData('created_by', $createdBy ?? 0)
                ->save();

            // 删除临时文件
            @unlink($tempFile);

            return $storedPath;
        } catch (\Exception $e) {
            @unlink($tempFile);
            throw new Core(__('数据库备份失败：%{1}', $e->getMessage()));
        }
    }

    /**
     * 创建代码备份
     * 
     * @param string $backupName 备份名称
     * @param int|null $createdBy 创建人ID
     * @return string 备份文件路径
     */
    public function createCodeBackup(string $backupName, ?int $createdBy = null): string
    {
        $timestamp = date('Ymd_His');
        $fileName = $backupName . '_code_' . $timestamp . '.zip';
        $tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $fileName;

        try {
            // 执行代码备份
            $this->fileBackupService->backupCode($tempFile);

            // 保存到存储
            $storedPath = $this->storage->save($tempFile, $fileName);

            // 记录到数据库
            $this->backupModel->clear()
                ->setData('backup_type', 'code')
                ->setData('backup_name', $fileName)
                ->setData('file_path', $storedPath)
                ->setData('file_size', filesize($storedPath))
                ->setData('status', 'completed')
                ->setData('created_at', date('Y-m-d H:i:s'))
                ->setData('created_by', $createdBy ?? 0)
                ->save();

            // 删除临时文件
            @unlink($tempFile);

            return $storedPath;
        } catch (\Exception $e) {
            @unlink($tempFile);
            throw new Core(__('代码备份失败：%{1}', $e->getMessage()));
        }
    }

    /**
     * 创建配置文件备份
     * 
     * @param string $backupName 备份名称
     * @param int|null $createdBy 创建人ID
     * @return string 备份文件路径
     */
    public function createConfigBackup(string $backupName, ?int $createdBy = null): string
    {
        $timestamp = date('Ymd_His');
        $fileName = $backupName . '_config_' . $timestamp . '.zip';
        $tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $fileName;

        try {
            // 执行配置文件备份
            $this->fileBackupService->backupConfig($tempFile);

            // 保存到存储
            $storedPath = $this->storage->save($tempFile, $fileName);

            // 记录到数据库
            $this->backupModel->clear()
                ->setData('backup_type', 'code') // 使用code类型
                ->setData('backup_name', $fileName)
                ->setData('file_path', $storedPath)
                ->setData('file_size', filesize($storedPath))
                ->setData('status', 'completed')
                ->setData('created_at', date('Y-m-d H:i:s'))
                ->setData('created_by', $createdBy ?? 0)
                ->save();

            // 删除临时文件
            @unlink($tempFile);

            return $storedPath;
        } catch (\Exception $e) {
            @unlink($tempFile);
            throw new Core(__('配置文件备份失败：%{1}', $e->getMessage()));
        }
    }

    /**
     * 根据类型创建备份
     * 
     * @param string $type 备份类型：full/database/code/config
     * @param int|null $createdBy 创建人ID
     * @return array|string 备份信息或文件路径
     */
    public function createBackup(string $type, ?int $createdBy = null)
    {
        return match ($type) {
            'full' => $this->createFullBackup($createdBy),
            'database' => $this->createDatabaseBackup('backup_' . date('Ymd_His'), $createdBy),
            'code' => $this->createCodeBackup('backup_' . date('Ymd_His'), $createdBy),
            'config' => $this->createConfigBackup('backup_' . date('Ymd_His'), $createdBy),
            default => throw new Core(__('不支持的备份类型：%{1}', $type)),
        };
    }

    /**
     * 删除备份
     * 
     * @param int $backupId 备份ID
     * @return bool
     */
    public function deleteBackup(int $backupId): bool
    {
        $backup = $this->backupModel->clear()->load($backupId);
        if (!$backup->getId()) {
            return false;
        }

        $filePath = $backup->getData('file_path');
        
        // 删除文件
        if ($filePath) {
            $this->storage->delete($filePath);
        }

        // 删除记录
        return $backup->delete();
    }

    /**
     * 获取备份列表
     * 
     * @param array $filters 过滤条件
     * @return array
     */
    public function getBackupList(array $filters = []): array
    {
        $query = $this->backupModel->reset();

        if (!empty($filters['backup_type'])) {
            $query->where('backup_type', $filters['backup_type']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $query->order('created_at', 'DESC');

        if (isset($filters['limit'])) {
            $query->limit((int) $filters['limit']);
        }

        $items = $query->select()->fetch()->getItems();
        $result = [];

        foreach ($items as $item) {
            $result[] = $item->getData();
        }

        return $result;
    }

    /**
     * 清理过期备份
     * 
     * @param int $retentionDays 保留天数
     * @return array 清理结果
     */
    public function cleanExpiredBackups(int $retentionDays = 30): array
    {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));

        $backups = $this->backupModel->reset()
            ->where('created_at', $cutoffDate, '<')
            ->select()
            ->fetch()
            ->getItems();

        $result = [
            'deleted_count' => 0,
            'freed_space' => 0,
            'errors' => [],
        ];

        foreach ($backups as $backup) {
            try {
                $filePath = $backup->getData('file_path');
                $fileSize = $backup->getData('file_size') ?? 0;

                // 删除文件
                if ($filePath) {
                    $this->storage->delete($filePath);
                }

                // 删除记录
                $backup->delete();

                $result['deleted_count']++;
                $result['freed_space'] += $fileSize;
            } catch (\Exception $e) {
                $result['errors'][] = [
                    'backup_id' => $backup->getId(),
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $result;
    }

    /**
     * 获取备份文件路径
     * 
     * @param int $backupId 备份ID
     * @return string|null
     */
    public function getBackupPath(int $backupId): ?string
    {
        $backup = $this->backupModel->clear()->load($backupId);
        if (!$backup->getId()) {
            return null;
        }

        $filePath = $backup->getData('file_path');
        if (!$filePath) {
            return null;
        }

        return $this->storage->get($filePath);
    }
}
