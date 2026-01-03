<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Async\Console\Command;

use Weline\Framework\Console\CommandAbstract;

class BuildPhar extends CommandAbstract
{
    public function tip(): string
    {
        return '生成phar独立包';
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'async:build:phar',
            $this->tip(),
            [],
            [],
            [
                '生成phar包' => 'php bin/w async:build:phar',
            ]
        );
    }

    public function execute(array $args = [], array $data = []): void
    {
        $this->printer->note('开始生成phar包...');
        
        $pharPath = BP . DS . 'var' . DS . 'async' . DS . 'sync.phar';
        $pharDir = dirname($pharPath);
        
        if (!is_dir($pharDir)) {
            mkdir($pharDir, 0755, true);
        }

        // 删除旧的phar文件
        if (file_exists($pharPath)) {
            unlink($pharPath);
        }

        try {
            $phar = new \Phar($pharPath);
            $phar->startBuffering();

            // 添加必要文件
            $files = [
                'phar/sync.php',
                'bin/watcher.js',
                'phar/web/index.php',
                'phar/web/api.php',
            ];

            $baseDir = BP . DS . 'app' . DS . 'code' . DS . 'Weline' . DS . 'Async';
            
            foreach ($files as $file) {
                $sourceFile = $baseDir . DS . $file;
                if (file_exists($sourceFile)) {
                    $phar->addFile($sourceFile, $file);
                    $this->printer->print("  添加文件: {$file}");
                } else {
                    $this->printer->warning("  文件不存在: {$file}");
                }
            }

            // 设置stub
            $stub = "#!/usr/bin/env php\n";
            $stub .= "<?php\n";
            $stub .= "Phar::mapPhar('sync.phar');\n";
            $stub .= "require 'phar://sync.phar/phar/sync.php';\n";
            $stub .= "__HALT_COMPILER();\n";
            
            $phar->setStub($stub);
            $phar->stopBuffering();

            $this->printer->success("phar包生成成功: {$pharPath}");
            $this->printer->note("使用方法: php {$pharPath} -h");
            
        } catch (\Exception $e) {
            $this->printer->error("生成phar包失败: " . $e->getMessage());
        }
    }
}
