<?php

/**
 * 原生PHP字体子集化演示脚本
 * 展示如何使用新的原生PHP字体子集化功能
 */

require_once __DIR__ . '/../../../bootstrap.php';

use WelineTools\FontSubLetter\Service\FontSubsetterFactory;
use WelineTools\FontSubLetter\Service\AdvancedPhpFontSubsetter;
use WelineTools\FontSubLetter\Service\NativePhpFontSubsetter;

echo "=== 原生PHP字体子集化演示 ===\n\n";

// 1. 系统兼容性检查
echo "1. 系统兼容性检查\n";
echo "==================\n";

$compatibility = FontSubsetterFactory::getSystemCompatibility();
echo "PHP版本: {$compatibility['php_version']}\n";
echo "系统支持: " . (FontSubsetterFactory::isSupported() ? '✓' : '✗') . "\n";

echo "\n可用子集化器:\n";
foreach ($compatibility['available_subsetters'] as $method => $info) {
    echo "  - {$method}: {$info['description']}\n";
}

echo "\n推荐方法: {$compatibility['recommended_method']}\n\n";

// 2. 获取最佳子集化器信息
echo "2. 最佳子集化器信息\n";
echo "==================\n";

$bestInfo = FontSubsetterFactory::getBestSubsetterInfo();
echo "方法: {$bestInfo['method']}\n";
echo "类名: {$bestInfo['class']}\n";
echo "描述: {$bestInfo['description']}\n";
echo "支持格式: " . implode(', ', $bestInfo['supported_formats']) . "\n\n";

// 3. 演示字符处理
echo "3. 字符处理演示\n";
echo "==================\n";

// 字符串转字符代码
$text = "Hello World";
$charCodes = [];
for ($i = 0; $i < strlen($text); $i++) {
    $charCodes[] = ord($text[$i]);
}

echo "文本: \"{$text}\"\n";
echo "字符代码: [" . implode(', ', $charCodes) . "]\n";

// 处理中文字符
if (extension_loaded('mbstring')) {
    $chineseText = "你好世界";
    $chineseCharCodes = [];
    for ($i = 0; $i < mb_strlen($chineseText, 'UTF-8'); $i++) {
        $char = mb_substr($chineseText, $i, 1, 'UTF-8');
        $chineseCharCodes[] = mb_ord($char, 'UTF-8');
    }
    
    echo "\n中文文本: \"{$chineseText}\"\n";
    echo "字符代码: [" . implode(', ', $chineseCharCodes) . "]\n";
}

// 字符范围处理
$alphabetCodes = range(ord('A'), ord('Z'));
echo "\n英文字母范围 (A-Z): [" . implode(', ', $alphabetCodes) . "]\n";

echo "\n";

// 4. 演示字体子集化（如果有测试字体）
echo "4. 字体子集化演示\n";
echo "==================\n";

$testFontPath = __DIR__ . '/../../../pub/DouyinSansBold_subset.ttf';
$outputDir = __DIR__ . '/demo_output';

// 创建输出目录
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

if (file_exists($testFontPath)) {
    echo "找到测试字体文件: " . basename($testFontPath) . "\n";
    
    // 获取字体信息
    try {
        $fontInfo = FontSubsetterFactory::getFontInfo($testFontPath);
        echo "字体信息: {$fontInfo['filename']} ({$fontInfo['size_formatted']})\n";
        
        // 验证字体
        $isValid = FontSubsetterFactory::validateFont($testFontPath);
        echo "字体验证: " . ($isValid ? '✓' : '✗') . "\n";
        
        // 创建子集
        $selectedChars = [65, 66, 67, 68, 69]; // A, B, C, D, E
        $outputPath = $outputDir . '/demo_subset.ttf';
        
        echo "\n创建字体子集...\n";
        echo "选择的字符: A, B, C, D, E\n";
        
        $startTime = microtime(true);
        $result = FontSubsetterFactory::createSubset($testFontPath, $outputPath, $selectedChars);
        $endTime = microtime(true);
        
        if ($result['success']) {
            echo "✓ 子集创建成功！\n";
            echo "  原始大小: {$result['original_size']} 字节\n";
            echo "  子集大小: {$result['subset_size']} 字节\n";
            echo "  压缩率: " . round($result['compression_ratio'], 2) . "%\n";
            echo "  字符数量: {$result['characters_count']}\n";
            echo "  处理时间: " . round(($endTime - $startTime) * 1000, 2) . "ms\n";
            echo "  输出文件: " . basename($outputPath) . "\n";
        } else {
            echo "✗ 子集创建失败: {$result['error']}\n";
        }
        
    } catch (Exception $e) {
        echo "错误: " . $e->getMessage() . "\n";
    }
    
} else {
    echo "测试字体文件不存在，跳过字体子集化演示\n";
    echo "请确保文件存在: {$testFontPath}\n";
}

echo "\n";

// 5. 演示不同方法的对比
echo "5. 方法对比演示\n";
echo "==================\n";

$methods = ['native_php', 'advanced_native_php'];
$testChars = [65, 66, 67, 68, 69]; // A-E

foreach ($methods as $method) {
    try {
        echo "\n测试方法: {$method}\n";
        
        $subsetter = FontSubsetterFactory::getSubsetter($method);
        $outputPath = $outputDir . "/demo_{$method}.ttf";
        
        if (file_exists($testFontPath)) {
            $startTime = microtime(true);
            $result = $subsetter->createSubset($testFontPath, $outputPath, $testChars);
            $endTime = microtime(true);
            
            if ($result['success']) {
                echo "  ✓ 成功\n";
                echo "    处理时间: " . round(($endTime - $startTime) * 1000, 2) . "ms\n";
                echo "    压缩率: " . round($result['compression_ratio'], 2) . "%\n";
                echo "    子集大小: " . $this->formatBytes($result['subset_size']) . "\n";
            } else {
                echo "  ✗ 失败: {$result['error']}\n";
            }
        } else {
            echo "  - 跳过（无测试字体）\n";
        }
        
    } catch (Exception $e) {
        echo "  ✗ 错误: " . $e->getMessage() . "\n";
    }
}

echo "\n";

// 6. 性能优化演示
echo "6. 性能优化演示\n";
echo "==================\n";

if (file_exists($testFontPath)) {
    echo "批量处理演示:\n";
    
    $fonts = [$testFontPath]; // 可以添加更多字体文件
    $selectedChars = [65, 66, 67, 68, 69]; // A-E
    
    $totalTime = 0;
    $totalSize = 0;
    
    foreach ($fonts as $fontPath) {
        $outputPath = $outputDir . '/batch_' . basename($fontPath);
        
        $startTime = microtime(true);
        $result = FontSubsetterFactory::createSubset($fontPath, $outputPath, $selectedChars);
        $endTime = microtime(true);
        
        if ($result['success']) {
            $processingTime = ($endTime - $startTime) * 1000;
            $totalTime += $processingTime;
            $totalSize += $result['subset_size'];
            
            echo "  " . basename($fontPath) . ": {$processingTime}ms, " . 
                 $this->formatBytes($result['subset_size']) . "\n";
        }
    }
    
    echo "\n总计:\n";
    echo "  处理时间: " . round($totalTime, 2) . "ms\n";
    echo "  总大小: " . $this->formatBytes($totalSize) . "\n";
} else {
    echo "无测试字体，跳过性能优化演示\n";
}

echo "\n";

// 7. 错误处理演示
echo "7. 错误处理演示\n";
echo "==================\n";

// 测试无效输入
echo "测试无效字体文件:\n";
$invalidResult = FontSubsetterFactory::createSubset(
    '/path/to/nonexistent/font.ttf',
    $outputDir . '/invalid.ttf',
    [65, 66, 67]
);

if (!$invalidResult['success']) {
    echo "  ✓ 正确捕获错误: {$invalidResult['error']}\n";
} else {
    echo "  ✗ 应该失败但没有失败\n";
}

// 测试无效字符代码
echo "\n测试无效字符代码:\n";
if (file_exists($testFontPath)) {
    $invalidCharsResult = FontSubsetterFactory::createSubset(
        $testFontPath,
        $outputDir . '/invalid_chars.ttf',
        [-1, 999999] // 无效的字符代码
    );
    
    if (!$invalidCharsResult['success']) {
        echo "  ✓ 正确捕获错误: {$invalidCharsResult['error']}\n";
    } else {
        echo "  ✗ 应该失败但没有失败\n";
    }
} else {
    echo "  跳过（无测试字体）\n";
}

echo "\n";

// 8. 总结
echo "8. 总结\n";
echo "==================\n";

echo "原生PHP字体子集化功能特点:\n";
echo "  ✓ 无需外部依赖（如Python）\n";
echo "  ✓ 支持多种实现方法\n";
echo "  ✓ 自动选择最佳可用方法\n";
echo "  ✓ 支持TTF和OTF格式\n";
echo "  ✓ 包含完整的错误处理\n";
echo "  ✓ 提供性能优化选项\n";
echo "  ✓ 支持批量处理\n";

echo "\n推荐使用方式:\n";
echo "  // 使用最佳可用方法\n";
echo "  \$result = FontSubsetterFactory::createSubset(\$inputPath, \$outputPath, \$charCodes);\n";
echo "\n  // 使用指定方法\n";
echo "  \$result = FontSubsetterFactory::createSubsetWithMethod('advanced_native_php', \$inputPath, \$outputPath, \$charCodes);\n";

echo "\n=== 演示完成 ===\n";

/**
 * 格式化字节数
 */
function formatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);

    $bytes /= pow(1024, $pow);

    return round($bytes, 2) . ' ' . $units[$pow];
}
