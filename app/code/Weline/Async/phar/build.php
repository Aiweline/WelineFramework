<?php

/**
 * phar打包脚本
 * 独立运行，不依赖框架
 */

$baseDir = __DIR__ . '/..';
$pharPath = $baseDir . '/../../../../var/async/sync.phar';

// 创建输出目录
$pharDir = dirname($pharPath);
if (!is_dir($pharDir)) {
    mkdir($pharDir, 0755, true);
}

// 删除旧的phar文件
if (file_exists($pharPath)) {
    unlink($pharPath);
}

echo "开始打包phar...\n";

try {
    $phar = new Phar($pharPath);
    $phar->startBuffering();

    // 添加必要文件
    $files = [
        'phar/sync.php',
        'bin/watcher.js',
        'bin/package.json',
    ];

    foreach ($files as $file) {
        $sourceFile = $baseDir . '/' . $file;
        if (file_exists($sourceFile)) {
            $phar->addFile($sourceFile, $file);
            echo "  添加文件: {$file}\n";
        } else {
            echo "  警告: 文件不存在: {$file}\n";
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

    // 设置可执行权限
    chmod($pharPath, 0755);

    echo "phar包生成成功: {$pharPath}\n";
    echo "文件大小: " . number_format(filesize($pharPath) / 1024, 2) . " KB\n";
    
} catch (Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
    exit(1);
}
