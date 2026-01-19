<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Hook\Console\Hook;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;

/**
 * Hook文件迁移工具
 * 将旧格式的Hook文件（使用--分隔符）迁移到新格式（目录层级结构）
 */
class MigrateToDirectoryStructure extends CommandAbstract
{
    /**
     * 迁移Hook文件到目录层级结构
     */
    public function execute(array $args = [], array $data = [])
    {
        // 确保 printer 已初始化
        if (!isset($this->printer)) {
            $this->__init();
        }
        
        try {
            $this->printer->setup(__('开始迁移Hook文件到目录层级结构...'));
            
            $env = Env::getInstance();
            $modules = $env->getModuleList();
            
            $totalFiles = 0;
            $migratedFiles = 0;
            $skippedFiles = 0;
            $errors = [];
            
            foreach ($modules as $moduleName => $moduleInfo) {
                $basePath = $moduleInfo['base_path'] ?? '';
                if (empty($basePath) || !($moduleInfo['status'] ?? false)) {
                    continue;
                }
                
                // 扫描 view/hooks/ 目录
                $hooksDir = $basePath . DS . 'view' . DS . 'hooks';
                if (!is_dir($hooksDir)) {
                    continue;
                }
                
                // 扫描目录下的所有 .phtml 文件（只扫描直接文件，不递归）
                $files = glob($hooksDir . DS . '*.phtml');
                
                foreach ($files as $filePath) {
                    $file = new \SplFileInfo($filePath);
                    if (!$file->isFile()) {
                        continue;
                    }
                    
                    $totalFiles++;
                    $fileName = $file->getBasename();
                    
                    // 检查是否已经是新格式（包含目录分隔符）
                    if (strpos($fileName, DS) !== false || strpos($fileName, '/') !== false || strpos($fileName, '\\') !== false) {
                        $skippedFiles++;
                        continue; // 已经是新格式，跳过
                    }
                    
                    // 检查是否是旧格式（包含--分隔符）
                    if (strpos($fileName, '--') === false) {
                        $skippedFiles++;
                        continue; // 不是旧格式，跳过
                    }
                    
                    // 解析旧格式文件名
                    // 格式：ModuleName--area--type--component--position.phtml
                    $fileNameWithoutExt = $file->getBasename('.phtml');
                    $parts = explode('--', $fileNameWithoutExt);
                    
                    if (count($parts) < 2) {
                        $skippedFiles++;
                        continue; // 格式不正确，跳过
                    }
                    
                    // 构建新格式的目录结构
                    // 第一部分是模块名，其余部分是路径层级
                    $modulePart = array_shift($parts);
                    $pathParts = $parts;
                    
                    // 构建目标目录路径
                    $targetDir = $hooksDir . DS . $modulePart;
                    foreach ($pathParts as $part) {
                        $targetDir .= DS . $part;
                    }
                    
                    // 创建目录（如果不存在）
                    $targetParentDir = dirname($targetDir);
                    if (!is_dir($targetParentDir)) {
                        if (!mkdir($targetParentDir, 0755, true)) {
                            $errors[] = "无法创建目录：{$targetParentDir}";
                            continue;
                        }
                    }
                    
                    // 目标文件路径
                    $targetFile = $targetDir . '.phtml';
                    
                    // 检查目标文件是否已存在
                    if (file_exists($targetFile)) {
                        $this->printer->warning(__('目标文件已存在，跳过：%{1}', [$targetFile]));
                        $skippedFiles++;
                        continue;
                    }
                    
                    // 移动文件
                    if (rename($filePath, $targetFile)) {
                        $migratedFiles++;
                        $this->printer->note(__('✓ 已迁移：%{1} → %{2}', [
                            str_replace($basePath . DS, '', $filePath),
                            str_replace($basePath . DS, '', $targetFile)
                        ]));
                    } else {
                        $errors[] = "无法移动文件：{$filePath} → {$targetFile}";
                    }
                }
            }
            
            $this->printer->success(__('✓ Hook文件迁移完成。'));
            $this->printer->note(__('统计信息：'));
            $this->printer->note(__('  总Hook文件数：%{1}', [$totalFiles]));
            $this->printer->note(__('  已迁移文件数：%{1}', [$migratedFiles]));
            $this->printer->note(__('  已跳过文件数：%{1}', [$skippedFiles]));
            
            if (!empty($errors)) {
                $this->printer->error(__('✖ 迁移失败的文件数：%{1}', [count($errors)]));
                foreach ($errors as $error) {
                    $this->printer->error(__('  - %{1}', [$error]));
                }
            }
            
            $this->printer->note(__(''));
            $this->printer->note(__('请运行 php bin/w hook:rebuild 重建Hook注册表。'));
            
        } catch (\Throwable $e) {
            $this->printer->error(__('迁移失败：%{1}', [$e->getMessage()]));
            if (DEV) {
                $this->printer->error($e->getTraceAsString());
            }
            exit(1);
        }
    }
    
    public function tip(): string
    {
        return '将Hook文件从旧格式（--分隔符）迁移到新格式（目录层级结构）';
    }
    
    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'hook:migrate',
            '将Hook文件从旧格式迁移到新格式（目录层级结构）',
            [],
            [
                '将旧格式的Hook文件（使用--分隔符）迁移到新格式（目录层级结构）。',
                '旧格式：Weline_Theme--frontend--layouts--base--html-attr.phtml',
                '新格式：Weline_Theme/frontend/layouts/base/html-attr.phtml',
            ],
            [
                '迁移Hook文件' => 'php bin/w hook:migrate',
            ],
            'php bin/w hook:migrate'
        );
    }
}
