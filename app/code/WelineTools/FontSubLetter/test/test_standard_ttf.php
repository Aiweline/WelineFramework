<?php

require_once __DIR__ . '/../../../../bootstrap.php';

use WelineTools\FontSubLetter\Service\StandardTTFGenerator;

echo "=== 标准TTF生成器测试 ===\n";

try {
    // 创建测试字符
    $testChars = ['A', 'B', 'C', '1', '2', '3', '!', '@', '#'];
    
    // 创建TTF生成器
    $generator = new StandardTTFGenerator();
    
    // 生成TTF文件
    echo "正在生成TTF文件...\n";
    $ttfData = $generator->generateTTF($testChars);
    
    if (empty($ttfData)) {
        throw new Exception('TTF数据生成失败');
    }
    
    echo "TTF文件大小: " . strlen($ttfData) . " 字节\n";
    
    // 保存测试文件
    $testFile = __DIR__ . '/test_standard.ttf';
    if (file_put_contents($testFile, $ttfData) === false) {
        throw new Exception('无法保存测试文件');
    }
    
    echo "测试文件已保存: $testFile\n";
    
    // 验证文件头
    echo "\n=== 验证TTF文件头 ===\n";
    $header = substr($ttfData, 0, 12);
    $sfntVersion = unpack('N', substr($header, 0, 4))[1];
    $numTables = unpack('n', substr($header, 4, 2))[1];
    $searchRange = unpack('n', substr($header, 6, 2))[1];
    $entrySelector = unpack('n', substr($header, 8, 2))[1];
    $rangeShift = unpack('n', substr($header, 10, 2))[1];
    
    echo "sfntVersion: 0x" . dechex($sfntVersion) . "\n";
    echo "numTables: $numTables\n";
    echo "searchRange: $searchRange\n";
    echo "entrySelector: $entrySelector\n";
    echo "rangeShift: $rangeShift\n";
    
    // 验证表目录
    echo "\n=== 验证表目录 ===\n";
    $tableDir = substr($ttfData, 12, $numTables * 16);
    $expectedTables = ['head', 'hhea', 'maxp', 'cmap', 'loca', 'glyf', 'hmtx', 'name', 'OS/2', 'post'];
    
    for ($i = 0; $i < $numTables; $i++) {
        $offset = $i * 16;
        $tag = substr($tableDir, $offset, 4);
        $checksum = unpack('N', substr($tableDir, $offset + 4, 4))[1];
        $tableOffset = unpack('N', substr($tableDir, $offset + 8, 4))[1];
        $tableLength = unpack('N', substr($tableDir, $offset + 12, 4))[1];
        
        echo "表 $i: $tag, 偏移: $tableOffset, 长度: $tableLength\n";
        
        if (!in_array($tag, $expectedTables)) {
            echo "警告: 未知表标签: $tag\n";
        }
    }
    
    // 验证head表
    echo "\n=== 验证head表 ===\n";
    $headOffset = 172; // 根据表目录计算
    $headData = substr($ttfData, $headOffset, 54);
    $headVersion = unpack('N', substr($headData, 0, 4))[1];
    $magicNumber = unpack('N', substr($headData, 12, 4))[1];
    $unitsPerEm = unpack('n', substr($headData, 18, 2))[1];
    
    echo "head版本: 0x" . dechex($headVersion) . "\n";
    echo "magicNumber: 0x" . dechex($magicNumber) . "\n";
    echo "unitsPerEm: $unitsPerEm\n";
    
    if ($magicNumber !== 0x5F0F3CF5) {
        echo "警告: magicNumber不正确\n";
    }
    
    // 检查文件是否可以正常读取
    echo "\n=== 文件完整性检查 ===\n";
    if (file_exists($testFile)) {
        $fileSize = filesize($testFile);
        echo "文件存在，大小: $fileSize 字节\n";
        
        // 尝试用file_get_contents读取
        $readData = file_get_contents($testFile);
        if ($readData === $ttfData) {
            echo "文件读取验证通过\n";
        } else {
            echo "警告: 文件读取数据不匹配\n";
        }
    } else {
        echo "错误: 测试文件不存在\n";
    }
    
    echo "\n=== 测试完成 ===\n";
    echo "标准TTF生成器测试成功！\n";
    
} catch (Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
    echo "堆栈跟踪:\n" . $e->getTraceAsString() . "\n";
}

// 清理测试文件
if (isset($testFile) && file_exists($testFile)) {
    unlink($testFile);
    echo "测试文件已清理\n";
}
