<?php
/**
 * 批量添加 CommandAliasesTrait 到所有直接实现 CommandInterface 的类
 */

$basePath = __DIR__;
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($basePath . '/app/code')
);

$processed = 0;
$errors = [];

foreach ($files as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $filePath = $file->getRealPath();
        $content = file_get_contents($filePath);
        
        // 检查是否直接实现 CommandInterface 且没有使用 CommandAliasesTrait 且没有继承 CommandAbstract
        if (preg_match('/class\s+\w+\s+implements\s+.*CommandInterface/', $content) 
            && !preg_match('/use\s+.*CommandAliasesTrait/', $content)
            && !preg_match('/extends\s+.*CommandAbstract/', $content)) {
            
            // 检查是否已经有 aliases() 方法
            if (preg_match('/public\s+function\s+aliases\s*\(\)\s*:/', $content)) {
                continue;
            }
            
            // 添加 use 语句
            if (preg_match('/use\s+Weline\\\Framework\\\Console\\\CommandInterface;/', $content)) {
                $content = str_replace(
                    'use Weline\Framework\Console\CommandInterface;',
                    "use Weline\Framework\Console\CommandInterface;\nuse Weline\Framework\Console\CommandAliasesTrait;",
                    $content
                );
            } elseif (preg_match('/use\s+.*\\\CommandInterface;/', $content)) {
                $content = preg_replace(
                    '/(use\s+.*\\\CommandInterface;)/',
                    "$1\nuse Weline\Framework\Console\CommandAliasesTrait;",
                    $content
                );
            } else {
                // 如果没有 use CommandInterface，添加完整的 use 语句
                $namespaceMatch = [];
                if (preg_match('/namespace\s+([^;]+);/', $content, $namespaceMatch)) {
                    $content = preg_replace(
                        '/(namespace\s+[^;]+;)/',
                        "$1\n\nuse Weline\Framework\Console\CommandInterface;\nuse Weline\Framework\Console\CommandAliasesTrait;",
                        $content,
                        1
                    );
                }
            }
            
            // 在类定义后添加 trait
            if (preg_match('/(class\s+\w+\s+implements\s+.*CommandInterface[^{]*\{)/', $content, $matches)) {
                $content = preg_replace(
                    '/(class\s+\w+\s+implements\s+.*CommandInterface[^{]*\{)/',
                    "$1\n    use CommandAliasesTrait;\n",
                    $content,
                    1
                );
                
                // 保存文件
                if (file_put_contents($filePath, $content)) {
                    $processed++;
                    echo "Processed: $filePath\n";
                } else {
                    $errors[] = "Failed to write: $filePath";
                }
            }
        }
    }
}

echo "\nProcessed: $processed files\n";
if (!empty($errors)) {
    echo "Errors:\n";
    foreach ($errors as $error) {
        echo "  - $error\n";
    }
}

