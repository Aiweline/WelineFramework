<?php
// 读取 zh_Hans_CN.csv 文件
$zhFile = __DIR__ . '/zh_Hans_CN.csv';
$enFile = __DIR__ . '/en_US.csv';

// 尝试不同的编码读取
$encodings = ['UTF-8', 'GBK', 'GB2312', 'Windows-1252'];
$content = null;
$encoding = null;

foreach ($encodings as $enc) {
    $test = file_get_contents($zhFile);
    if (mb_check_encoding($test, $enc)) {
        $content = mb_convert_encoding($test, 'UTF-8', $enc);
        $encoding = $enc;
        break;
    }
}

if (!$content) {
    // 如果都检测不到，尝试从代码中提取翻译键
    echo "无法检测编码，将根据代码中的翻译键创建新文件\n";
    exit(1);
}

echo "检测到编码: $encoding\n";

// 读取现有的 en_US.csv，保留已有的英文翻译
$existingEn = [];
if (file_exists($enFile)) {
    $enContent = file_get_contents($enFile);
    $enLines = explode("\n", $enContent);
    foreach ($enLines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        $parts = str_getcsv($line);
        if (count($parts) >= 2) {
            $existingEn[$parts[0]] = $parts[1];
        }
    }
}

// 解析中文文件
$lines = explode("\n", $content);
$newEnLines = [];

foreach ($lines as $line) {
    $line = trim($line);
    if (empty($line)) continue;
    
    $parts = str_getcsv($line);
    if (count($parts) >= 2) {
        $zh = $parts[0];
        $zhTrans = $parts[1];
        
        // 如果已有英文翻译，使用它；否则使用中文作为占位符
        if (isset($existingEn[$zh]) && !empty($existingEn[$zh])) {
            $en = $existingEn[$zh];
        } else {
            // 对于中文翻译文件，英文翻译应该与中文不同
            // 这里先用中文占位，后续需要手动翻译
            $en = $zhTrans;
        }
        
        $newEnLines[] = '"' . str_replace('"', '""', $zh) . '","' . str_replace('"', '""', $en) . '"';
    }
}

// 写入新文件
file_put_contents($enFile, implode("\n", $newEnLines));
echo "已创建 en_US.csv，共 " . count($newEnLines) . " 行\n";
