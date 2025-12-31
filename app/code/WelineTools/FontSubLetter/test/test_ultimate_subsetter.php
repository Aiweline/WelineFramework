<?php

require_once __DIR__ . '/../../../../bootstrap.php';

use WelineTools\FontSubLetter\Service\UltimateFontSubsetter;
use WelineTools\FontSubLetter\Service\StandardTTFGenerator;

echo "=== UltimateFontSubsetter 测试 ===\n";

try {
    // 创建测试字体文件
    $testFontPath = __DIR__ . '/test_font.ttf';
    $testSubsetPath = __DIR__ . '/test_subset.ttf';
    
    // 使用StandardTTFGenerator创建一个有效的测试字体文件
    $ttfGenerator = new StandardTTFGenerator();
    $testFontData = $ttfGenerator->generateTTF(['A', 'B', 'C', 'D', 'E']);
    
    // 保存测试字体文件
    if (file_put_contents($testFontPath, $testFontData) === false) {
        throw new Exception('无法创建测试字体文件');
    }
    
    echo "测试字体文件已创建: $testFontPath\n";
    echo "测试字体文件大小: " . filesize($testFontPath) . " 字节\n";
    
    // 创建UltimateFontSubsetter实例
    $subsetter = new UltimateFontSubsetter();
    
    // 测试字符
    $testChars = ['A', 'B', 'C', '1', '2', '3'];
    
    echo "\n正在生成字体子集...\n";
    $result = $subsetter->createSubset($testFontPath, $testSubsetPath, $testChars);
    
    echo "生成结果:\n";
    print_r($result);
    
    if ($result['success']) {
        echo "\n=== 子集文件信息 ===\n";
        echo "原始大小: " . $result['original_size'] . " 字节\n";
        echo "子集大小: " . $result['subset_size'] . " 字节\n";
        echo "压缩率: " . $result['compression_ratio'] . "%\n";
        echo "字符数量: " . $result['characters_count'] . "\n";
        echo "格式: " . $result['format'] . "\n";
        
        // 验证子集文件
        if (file_exists($testSubsetPath)) {
            echo "\n=== 子集文件验证 ===\n";
            $subsetData = file_get_contents($testSubsetPath);
            echo "子集文件大小: " . strlen($subsetData) . " 字节\n";
            
            // 检查TTF文件头
            if (strlen($subsetData) >= 12) {
                $header = substr($subsetData, 0, 12);
                $sfntVersion = unpack('N', substr($header, 0, 4))[1];
                $numTables = unpack('n', substr($header, 4, 2))[1];
                
                echo "sfntVersion: 0x" . dechex($sfntVersion) . "\n";
                echo "numTables: $numTables\n";
                
                if ($sfntVersion === 0x00010000) {
                    echo "✓ TTF文件头验证通过\n";
                } else {
                    echo "✗ TTF文件头验证失败\n";
                }
                
                // 检查表目录
                if (strlen($subsetData) >= 12 + $numTables * 16) {
                    echo "\n=== 表目录验证 ===\n";
                    $tableDir = substr($subsetData, 12, $numTables * 16);
                    $expectedTables = ['head', 'hhea', 'maxp', 'cmap', 'loca', 'glyf', 'hmtx', 'name', 'OS/2', 'post'];
                    
                    for ($i = 0; $i < $numTables; $i++) {
                        $offset = $i * 16;
                        $tag = substr($tableDir, $offset, 4);
                        $tableOffset = unpack('N', substr($tableDir, $offset + 8, 4))[1];
                        $tableLength = unpack('N', substr($tableDir, $offset + 12, 4))[1];
                        
                        echo "表 $i: $tag, 偏移: $tableOffset, 长度: $tableLength\n";
                        
                        if (!in_array($tag, $expectedTables)) {
                            echo "警告: 未知表标签: $tag\n";
                        }
                    }
                }
            }
        }
        
        echo "\n=== 测试成功 ===\n";
    } else {
        echo "\n=== 测试失败 ===\n";
        echo "错误: " . $result['error'] . "\n";
    }
    
} catch (Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
    echo "堆栈跟踪:\n" . $e->getTraceAsString() . "\n";
}

// 清理测试文件
$testFiles = [$testFontPath, $testSubsetPath];
foreach ($testFiles as $file) {
    if (isset($file) && file_exists($file)) {
        unlink($file);
        echo "已清理: $file\n";
    }
}
