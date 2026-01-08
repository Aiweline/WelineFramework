<?php

declare(strict_types=1);

/*
 * 文件备份服务
 * 备份应用代码和配置文件
 * 
 * @author Weline Framework
 * @package Weline\Maintenance\Service
 */

namespace Weline\Maintenance\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Exception\Core;

class FileBackupService
{
    /**
     * 备份应用代码
     * 
     * @param string $outputFile 输出文件路径（zip格式）
     * @param array $excludePaths 排除的路径数组
     * @return string 备份文件路径
     */
    public function backupCode(string $outputFile, array $excludePaths = []): string
    {
        $excludePaths = array_merge([
            'vendor/',
            'var/',
            'generated/',
            'pub/static/',
            'pub/media/',
            '.git/',
            'node_modules/',
            '.idea/',
            '.vscode/',
        ], $excludePaths);

        return $this->createZipArchive(BP, $outputFile, $excludePaths);
    }

    /**
     * 备份配置文件
     * 
     * @param string $outputFile 输出文件路径（zip格式）
     * @return string 备份文件路径
     */
    public function backupConfig(string $outputFile): string
    {
        $configFiles = [
            'app/etc/env.php',
            '.env',
            '.htaccess',
            'nginx.conf',
        ];

        $dir = dirname($outputFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $zip = new \ZipArchive();
        if ($zip->open($outputFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new Core(__('无法创建备份压缩文件：%{1}', $outputFile));
        }

        try {
            foreach ($configFiles as $file) {
                $fullPath = BP . $file;
                if (is_file($fullPath)) {
                    $zip->addFile($fullPath, $file);
                }
            }

            // 备份env.php中的敏感信息（可选）
            $envFile = BP . 'app/etc/env.php';
            if (is_file($envFile)) {
                $zip->addFile($envFile, 'config/env.php');
            }

            $zip->close();

            if (!is_file($outputFile) || filesize($outputFile) === 0) {
                throw new Core(__('配置文件备份失败'));
            }

            return $outputFile;
        } catch (\Exception $e) {
            $zip->close();
            @unlink($outputFile);
            throw new Core(__('备份配置文件失败：%{1}', $e->getMessage()));
        }
    }

    /**
     * 创建ZIP压缩包
     * 
     * @param string $sourceDir 源目录
     * @param string $outputFile 输出文件
     * @param array $excludePaths 排除的路径
     * @return string
     */
    private function createZipArchive(string $sourceDir, string $outputFile, array $excludePaths = []): string
    {
        $dir = dirname($outputFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $zip = new \ZipArchive();
        if ($zip->open($outputFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new Core(__('无法创建压缩文件：%{1}', $outputFile));
        }

        try {
            $this->addDirectoryToZip($zip, $sourceDir, '', $excludePaths);
            $zip->close();

            if (!is_file($outputFile) || filesize($outputFile) === 0) {
                throw new Core(__('创建压缩文件失败'));
            }

            return $outputFile;
        } catch (\Exception $e) {
            $zip->close();
            @unlink($outputFile);
            throw new Core(__('压缩文件失败：%{1}', $e->getMessage()));
        }
    }

    /**
     * 递归添加目录到ZIP
     * 
     * @param \ZipArchive $zip
     * @param string $dir
     * @param string $zipPath
     * @param array $excludePaths
     * @return void
     */
    private function addDirectoryToZip(\ZipArchive $zip, string $dir, string $zipPath, array $excludePaths): void
    {
        $files = scandir($dir);
        
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $filePath = $dir . DIRECTORY_SEPARATOR . $file;
            $relativePath = ($zipPath ? $zipPath . '/' : '') . $file;

            // 检查是否在排除列表中
            $shouldExclude = false;
            foreach ($excludePaths as $exclude) {
                $exclude = rtrim($exclude, '/\\');
                if (str_starts_with($relativePath, $exclude) || str_starts_with($filePath, BP . $exclude)) {
                    $shouldExclude = true;
                    break;
                }
            }

            if ($shouldExclude) {
                continue;
            }

            if (is_dir($filePath)) {
                // 递归添加子目录
                $this->addDirectoryToZip($zip, $filePath, $relativePath, $excludePaths);
            } elseif (is_file($filePath)) {
                // 添加文件
                $zip->addFile($filePath, $relativePath);
            }
        }
    }

    /**
     * 获取备份文件大小
     * 
     * @param string $filePath
     * @return int
     */
    public function getBackupSize(string $filePath): int
    {
        if (!is_file($filePath)) {
            return 0;
        }

        return (int)filesize($filePath);
    }
}
