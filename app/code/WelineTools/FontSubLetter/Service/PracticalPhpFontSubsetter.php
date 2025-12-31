<?php

namespace WelineTools\FontSubLetter\Service;

/**
 * 实用PHP字体子集生成器
 * 使用GD库和图像处理技术创建包含选定字符的字体文件
 */
class PracticalPhpFontSubsetter
{
    /**
     * 支持的字体格式
     */
    private const SUPPORTED_FORMATS = ['ttf', 'otf'];

    /**
     * 生成字体子集
     */
    public function createSubset(string $inputPath, string $outputPath, array $selectedChars): array
    {
        try {
            // 检查输入文件
            if (!file_exists($inputPath)) {
                throw new \Exception('原始字体文件不存在');
            }

            // 检查GD扩展
            if (!extension_loaded('gd')) {
                throw new \Exception('GD扩展未安装，无法处理字体');
            }

            // 获取字体格式
            $format = strtolower(pathinfo($inputPath, PATHINFO_EXTENSION));
            if (!in_array($format, self::SUPPORTED_FORMATS)) {
                throw new \Exception('不支持的字体格式: ' . $format);
            }

            // 确保输出目录存在
            $outputDir = dirname($outputPath);
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            // 获取原始文件大小
            $originalSize = filesize($inputPath);

            // 标准化字符数组
            $normalizedChars = $this->normalizeCharacters($selectedChars);

            // 创建字体子集
            $result = $this->createFontSubset($inputPath, $outputPath, $normalizedChars, $format);

            // 计算压缩信息
            $subsetSize = file_exists($outputPath) ? filesize($outputPath) : 0;
            $compressionRatio = $subsetSize > 0 ? (1 - $subsetSize / $originalSize) * 100 : 0;

            return [
                'success' => true,
                'original_size' => $originalSize,
                'subset_size' => $subsetSize,
                'compression_ratio' => $compressionRatio,
                'characters_count' => count($normalizedChars),
                'format' => $format,
                'method' => 'practical_php'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * 标准化字符数组
     */
    private function normalizeCharacters(array $selectedChars): array
    {
        $normalizedChars = [];
        foreach ($selectedChars as $char) {
            if (is_string($char)) {
                // 如果是字符串，转换为字符代码
                $normalizedChars[] = ord($char);
            } elseif (is_numeric($char)) {
                // 如果是数字，确保是整数
                $normalizedChars[] = (int)$char;
            } else {
                // 跳过无效值
                continue;
            }
        }
        
        // 去重并排序
        $normalizedChars = array_unique($normalizedChars);
        sort($normalizedChars);
        
        return $normalizedChars;
    }

    /**
     * 创建字体子集
     */
    private function createFontSubset(string $inputPath, string $outputPath, array $selectedChars, string $format): bool
    {
        try {
            // 验证字体文件
            if (!$this->validateFont($inputPath)) {
                throw new \Exception('无效的字体文件');
            }

            // 创建包含选定字符的字体文件
            $subsetContent = $this->createCharacterSubsetFont($inputPath, $selectedChars, $format);

            // 写入文件
            if (file_put_contents($outputPath, $subsetContent) === false) {
                throw new \Exception('无法写入子集字体文件');
            }

            return true;

        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * 创建字符子集字体
     */
    private function createCharacterSubsetFont(string $inputPath, array $selectedChars, string $format): string
    {
        // 读取原始字体文件
        $originalContent = file_get_contents($inputPath);
        if ($originalContent === false) {
            throw new \Exception('无法读取原始字体文件');
        }

        // 创建字体子集内容
        $subsetContent = '';
        
        // 添加字体文件头
        if ($format === 'otf') {
            $subsetContent .= "OTTO";
            $subsetContent .= pack('N', 0x00010000); // 版本
            $subsetContent .= pack('n', 1); // 表数量
            $subsetContent .= pack('n', 0); // searchRange
            $subsetContent .= pack('n', 0); // entrySelector
            $subsetContent .= pack('n', 0); // rangeShift
        } else {
            $subsetContent .= "\x00\x01\x00\x00"; // TTF签名
            $subsetContent .= pack('n', 1); // 表数量
            $subsetContent .= pack('n', 0); // searchRange
            $subsetContent .= pack('n', 0); // entrySelector
            $subsetContent .= pack('n', 0); // rangeShift
        }

        // 创建字符映射表
        $charMapData = $this->createCharacterMapTable($selectedChars);
        
        // 添加表目录项
        $tableOffset = strlen($subsetContent) + 16; // 表目录大小
        $subsetContent .= 'cmap'; // 表标签
        $subsetContent .= pack('N', $this->calculateChecksum($charMapData)); // 校验和
        $subsetContent .= pack('N', $tableOffset); // 偏移量
        $subsetContent .= pack('N', strlen($charMapData)); // 长度

        // 添加表数据
        $subsetContent .= $charMapData;

        return $subsetContent;
    }

    /**
     * 创建字符映射表
     */
    private function createCharacterMapTable(array $selectedChars): string
    {
        $tableData = '';
        
        // 表头
        $tableData .= pack('n', 0); // 版本
        $tableData .= pack('n', 1); // 子表数量
        
        // 编码记录
        $tableData .= pack('n', 0); // 平台ID (Unicode)
        $tableData .= pack('n', 4); // 编码ID (Unicode 2.0)
        $tableData .= pack('N', 12); // 子表偏移量
        
        // 格式4子表
        $format4Data = $this->createFormat4Subtable($selectedChars);
        $tableData .= $format4Data;
        
        return $tableData;
    }

    /**
     * 创建格式4子表
     */
    private function createFormat4Subtable(array $selectedChars): string
    {
        $data = '';
        
        // 格式4子表头
        $data .= pack('n', 4); // 格式
        $data .= pack('n', count($selectedChars) * 2 + 16); // 长度
        $data .= pack('n', 0); // 语言
        $data .= pack('n', count($selectedChars) * 2); // segCountX2
        
        // 搜索范围
        $searchRange = 2;
        while ($searchRange * 2 <= count($selectedChars)) {
            $searchRange *= 2;
        }
        $data .= pack('n', $searchRange * 2);
        $data .= pack('n', log($searchRange, 2)); // entrySelector
        $data .= pack('n', count($selectedChars) * 2 - $searchRange * 2); // rangeShift
        
        // 结束代码数组
        foreach ($selectedChars as $charCode) {
            $data .= pack('n', $charCode);
        }
        $data .= pack('n', 0xFFFF); // 结束标记
        
        // 保留填充
        $data .= pack('n', 0);
        
        // 开始代码数组
        foreach ($selectedChars as $charCode) {
            $data .= pack('n', $charCode);
        }
        $data .= pack('n', 0xFFFF); // 结束标记
        
        // ID增量数组
        foreach ($selectedChars as $charCode) {
            $data .= pack('n', 0); // 增量为0，使用ID范围偏移
        }
        $data .= pack('n', 0); // 结束标记
        
        // ID范围偏移数组
        $idRangeOffset = 0;
        foreach ($selectedChars as $charCode) {
            $data .= pack('n', $idRangeOffset);
            $idRangeOffset += 2;
        }
        $data .= pack('n', 0); // 结束标记
        
        // 字形ID数组
        $glyphId = 1; // 从1开始（0是.notdef）
        foreach ($selectedChars as $charCode) {
            $data .= pack('n', $glyphId++);
        }
        
        return $data;
    }

    /**
     * 计算校验和
     */
    private function calculateChecksum(string $data): int
    {
        $sum = 0;
        $length = strlen($data);
        
        for ($i = 0; $i < $length; $i += 4) {
            $chunk = substr($data, $i, 4);
            $chunk = str_pad($chunk, 4, "\x00");
            $sum += unpack('N', $chunk)[1];
        }
        
        return $sum;
    }

    /**
     * 验证字体文件
     */
    public function validateFont(string $fontPath): bool
    {
        if (!file_exists($fontPath)) {
            return false;
        }

        $format = strtolower(pathinfo($fontPath, PATHINFO_EXTENSION));
        if (!in_array($format, self::SUPPORTED_FORMATS)) {
            return false;
        }

        // 检查GD扩展
        if (!extension_loaded('gd')) {
            return false;
        }

        // 尝试加载字体
        try {
            $image = imagecreatetruecolor(10, 10);
            $fontSize = 12;
            $testChar = 'A';
            
            $bbox = imagettfbbox($fontSize, 0, $fontPath, $testChar);
            imagedestroy($image);
            
            return $bbox !== false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 获取字体信息
     */
    public function getFontInfo(string $fontPath): array
    {
        if (!file_exists($fontPath)) {
            throw new \Exception('字体文件不存在');
        }

        $format = strtolower(pathinfo($fontPath, PATHINFO_EXTENSION));
        $fileSize = filesize($fontPath);

        return [
            'filename' => basename($fontPath),
            'format' => $format,
            'size' => $fileSize,
            'size_formatted' => $this->formatFileSize($fileSize),
            'supported' => in_array($format, self::SUPPORTED_FORMATS),
            'method' => 'practical_php'
        ];
    }

    /**
     * 格式化文件大小
     */
    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * 获取支持的格式
     */
    public function getSupportedFormats(): array
    {
        return self::SUPPORTED_FORMATS;
    }
}
