<?php

declare(strict_types=1);

/*
 * 本地备份存储实现
 * 
 * @author Weline Framework
 * @package Weline\Maintenance\Storage
 */

namespace Weline\Maintenance\Storage;

use Weline\Framework\App\Env;
use Weline\Framework\Exception\Core;

class LocalStorage implements BackupStorageInterface
{
    /**
     * 备份存储根目录
     */
    private string $storagePath;

    public function __construct()
    {
        $basePath = Env::getInstance()->getConfig('maintenance.backup.storage_path', 'var/backup/maintenance/');
        $this->storagePath = BP . rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        
        // 确保目录存在
        if (!is_dir($this->storagePath)) {
            @mkdir($this->storagePath, 0755, true);
        }
    }

    /**
     * 保存备份文件
     * 
     * @param string $filePath 本地文件路径
     * @param string $backupName 备份名称
     * @return string 存储后的文件路径
     */
    public function save(string $filePath, string $backupName): string
    {
        if (!is_file($filePath)) {
            throw new Core(__('备份文件不存在：%{1}', $filePath));
        }

        $targetPath = $this->storagePath . $backupName;
        $targetDir = dirname($targetPath);
        
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0755, true);
        }

        if (!copy($filePath, $targetPath)) {
            throw new Core(__('保存备份文件失败：%{1}', $targetPath));
        }

        return $targetPath;
    }

    /**
     * 获取备份文件路径
     * 
     * @param string $identifier 备份文件路径或相对路径
     * @return string 完整文件路径
     */
    public function get(string $identifier): string
    {
        // 如果已经是绝对路径，直接返回
        if (str_starts_with($identifier, BP)) {
            return $identifier;
        }

        // 如果是相对路径，拼接存储路径
        $fullPath = $this->storagePath . ltrim($identifier, DIRECTORY_SEPARATOR);
        
        return $fullPath;
    }

    /**
     * 删除备份文件
     * 
     * @param string $identifier 备份文件路径或相对路径
     * @return bool
     */
    public function delete(string $identifier): bool
    {
        $filePath = $this->get($identifier);
        
        if (!is_file($filePath)) {
            return false;
        }

        return @unlink($filePath);
    }

    /**
     * 检查备份文件是否存在
     * 
     * @param string $identifier 备份文件路径或相对路径
     * @return bool
     */
    public function exists(string $identifier): bool
    {
        $filePath = $this->get($identifier);
        return is_file($filePath);
    }

    /**
     * 获取备份文件大小
     * 
     * @param string $identifier 备份文件路径或相对路径
     * @return int 文件大小（字节）
     */
    public function getSize(string $identifier): int
    {
        $filePath = $this->get($identifier);
        
        if (!is_file($filePath)) {
            return 0;
        }

        return (int)filesize($filePath);
    }

    /**
     * 列出所有备份文件
     * 
     * @return array
     */
    public function list(): array
    {
        if (!is_dir($this->storagePath)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->storagePath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relativePath = str_replace($this->storagePath, '', $file->getPathname());
                $files[] = [
                    'name' => $file->getFilename(),
                    'path' => $relativePath,
                    'full_path' => $file->getPathname(),
                    'size' => $file->getSize(),
                    'modified' => $file->getMTime(),
                ];
            }
        }

        return $files;
    }

    /**
     * 获取存储路径
     * 
     * @return string
     */
    public function getStoragePath(): string
    {
        return $this->storagePath;
    }
}
