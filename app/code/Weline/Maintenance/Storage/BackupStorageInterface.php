<?php

declare(strict_types=1);

/*
 * 备份存储接口
 * 
 * @author Weline Framework
 * @package Weline\Maintenance\Storage
 */

namespace Weline\Maintenance\Storage;

interface BackupStorageInterface
{
    /**
     * 保存备份文件
     * 
     * @param string $filePath 本地文件路径
     * @param string $backupName 备份名称
     * @return string 存储后的文件路径或标识
     */
    public function save(string $filePath, string $backupName): string;

    /**
     * 获取备份文件路径或下载URL
     * 
     * @param string $identifier 备份标识
     * @return string 文件路径或下载URL
     */
    public function get(string $identifier): string;

    /**
     * 删除备份文件
     * 
     * @param string $identifier 备份标识
     * @return bool
     */
    public function delete(string $identifier): bool;

    /**
     * 检查备份文件是否存在
     * 
     * @param string $identifier 备份标识
     * @return bool
     */
    public function exists(string $identifier): bool;

    /**
     * 获取备份文件大小
     * 
     * @param string $identifier 备份标识
     * @return int 文件大小（字节）
     */
    public function getSize(string $identifier): int;

    /**
     * 列出所有备份文件
     * 
     * @return array
     */
    public function list(): array;
}
