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
 * Hook元数据批量添加工具
 * 为所有缺少元数据的Hook文件添加默认元数据
 */
class AddMeta extends CommandAbstract
{
    /**
     * 为所有Hook文件添加元数据
     */
    public function execute(array $args = [], array $data = [])
    {
        // 确保 printer 已初始化
        if (!isset($this->printer)) {
            $this->__init();
        }
        
        try {
            $this->printer->setup(__('开始为Hook文件添加元数据...'));
            
            $env = Env::getInstance();
            $modules = $env->getModuleList();
            
            $totalFiles = 0;
            $updatedFiles = 0;
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
                
                // 扫描目录下的所有 .phtml 文件
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($hooksDir, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY
                );
                
                foreach ($iterator as $file) {
                    /** @var \SplFileInfo $file */
                    if ($file->isFile() && $file->getExtension() === 'phtml') {
                        $totalFiles++;
                        $filePath = $file->getPathname();
                        $content = file_get_contents($filePath);
                        
                        // 检查是否已有元数据
                        $hasPriority = preg_match('/@hook-priority|Hook优先级|优先级[：:]/i', $content);
                        $hasSortOrder = preg_match('/@hook-sort-order|Hook排序顺序|排序顺序[：:]/i', $content);
                        
                        if ($hasPriority || $hasSortOrder) {
                            $skippedFiles++;
                            continue; // 已有元数据，跳过
                        }
                        
                        // 计算默认优先级
                        $defaultPriority = $this->calculateDefaultPriority($env, $moduleName);
                        
                        // 在文件开头添加元数据
                        $metaComment = "\n * \n * @hook-priority {$defaultPriority}   Hook优先级：{$defaultPriority}（默认值）\n * @hook-sort-order 0  Hook排序顺序：0（默认值）\n * @hook-solo false    Hook独享：false（不独占）";
                        
                        // 查找第一个注释块的结束位置（查找 */ 之前的位置）
                        if (preg_match('/\/\*\*.*?\*\//s', $content, $matches, PREG_OFFSET_CAPTURE)) {
                            // 找到注释块，在注释结束前插入元数据
                            $commentEnd = $matches[0][1] + strlen($matches[0][0]);
                            $before = substr($content, 0, $commentEnd - strlen($matches[0][0]));
                            $after = substr($content, $commentEnd);
                            
                            // 在注释结束前插入元数据（在 */ 之前）
                            $newContent = $before . $metaComment . "\n * " . substr($matches[0][0], -2) . $after;
                            
                            if (file_put_contents($filePath, $newContent)) {
                                $updatedFiles++;
                                $this->printer->note(__('✓ 已更新：%{1}', [$filePath]));
                            } else {
                                $errors[] = $filePath;
                            }
                        } else {
                            // 如果没有注释块，在文件开头添加
                            $metaBlock = "<?php\n/**\n * {$moduleName}模块 - Hook实现\n * \n * Hook文件：{$file->getBasename()}\n" . $metaComment . "\n */\n\n";
                            $newContent = $metaBlock . ltrim($content, "<?php\n");
                            
                            if (file_put_contents($filePath, $newContent)) {
                                $updatedFiles++;
                                $this->printer->note(__('✓ 已更新：%{1}', [$filePath]));
                            } else {
                                $errors[] = $filePath;
                            }
                        }
                    }
                }
            }
            
            $this->printer->success(__('✓ Hook元数据添加完成。'));
            $this->printer->note(__('统计信息：'));
            $this->printer->note(__('  总Hook文件数：%{1}', [$totalFiles]));
            $this->printer->note(__('  已更新文件数：%{1}', [$updatedFiles]));
            $this->printer->note(__('  已跳过文件数：%{1}', [$skippedFiles]));
            
            if (!empty($errors)) {
                $this->printer->error(__('✖ 更新失败的文件数：%{1}', [count($errors)]));
                foreach ($errors as $errorFile) {
                    $this->printer->error(__('  - %{1}', [$errorFile]));
                }
            }
            
            $this->printer->note(__(''));
            $this->printer->note(__('请运行 php bin/w hook:rebuild 验证所有Hook文件。'));
            
        } catch (\Throwable $e) {
            $this->printer->error(__('添加元数据失败：%{1}', [$e->getMessage()]));
            if (DEV) {
                $this->printer->error($e->getTraceAsString());
            }
            exit(1);
        }
    }
    
    /**
     * 计算默认优先级
     */
    private function calculateDefaultPriority(Env $env, string $module): int
    {
        try {
            $moduleInfo = $env->getModuleInfo($module);
            $position = $moduleInfo['position'] ?? 'composer';
            
            return match($position) {
                'app' => 200,
                'composer' => 150,
                'framework' => 100,
                'system' => 50,
                default => 100,
            };
        } catch (\Exception $e) {
            return 100;
        }
    }
    
    public function tip(): string
    {
        return '为所有Hook文件添加默认元数据';
    }
    
    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'hook:add-meta',
            '为所有缺少元数据的Hook文件添加默认元数据',
            [],
            [
                '执行后会自动为所有Hook文件添加默认的priority和sort_order元数据。',
                '如果Hook文件已有元数据，则跳过。',
            ],
            [
                '添加元数据' => 'php bin/w hook:add-meta',
            ],
            'php bin/w hook:add-meta'
        );
    }
}
