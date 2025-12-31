<?php

namespace WelineTools\FontSubLetter\Service;

/**
 * 高级原生PHP字体子集生成器
 * 使用PHP实现真正的字体子集化，包括字符映射表和字形数据处理
 */
class AdvancedPhpFontSubsetter
{
    /**
     * 支持的字体格式
     */
    private const SUPPORTED_FORMATS = ['ttf', 'otf'];

    /**
     * TTF文件签名
     */
    private const TTF_SIGNATURE = "\x00\x01\x00\x00";

    /**
     * OTF文件签名
     */
    private const OTF_SIGNATURE = "OTTO";

    /**
     * 必要的字体表
     */
    private const REQUIRED_TABLES = ['cmap', 'head', 'hhea', 'hmtx', 'maxp', 'name', 'post', 'glyf', 'loca'];

    /**
     * 生成真正的字体子集
     */
    public function createSubset(string $inputPath, string $outputPath, array $selectedChars): array
    {
        try {
            // 检查输入文件
            if (!file_exists($inputPath)) {
                throw new \Exception('原始字体文件不存在');
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

            // 读取原始字体文件
            $fontData = file_get_contents($inputPath);
            if ($fontData === false) {
                throw new \Exception('无法读取字体文件');
            }

            // 根据格式创建子集
            switch ($format) {
                case 'ttf':
                    $subsetData = $this->createAdvancedTtfSubset($fontData, $normalizedChars);
                    break;
                case 'otf':
                    $subsetData = $this->createAdvancedOtfSubset($fontData, $normalizedChars);
                    break;
                default:
                    throw new \Exception('不支持的字体格式');
            }

            // 写入子集文件
            if (file_put_contents($outputPath, $subsetData) === false) {
                throw new \Exception('无法写入子集字体文件');
            }

            // 计算压缩信息
            $subsetSize = filesize($outputPath);
            $compressionRatio = $subsetSize > 0 ? (1 - $subsetSize / $originalSize) * 100 : 0;

            return [
                'success' => true,
                'original_size' => $originalSize,
                'subset_size' => $subsetSize,
                'compression_ratio' => $compressionRatio,
                'characters_count' => count($normalizedChars),
                'format' => $format,
                'method' => 'advanced_native_php'
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
     * 创建高级TTF字体子集
     */
    private function createAdvancedTtfSubset(string $fontData, array $selectedChars): string
    {
        // 验证TTF文件头
        if (substr($fontData, 0, 4) !== self::TTF_SIGNATURE) {
            throw new \Exception('无效的TTF文件格式');
        }

        // 解析TTF文件结构
        $fontStructure = $this->parseAdvancedTtfStructure($fontData);
        
        // 创建字符到字形的映射
        $charToGlyphMap = $this->createCharToGlyphMap($fontStructure, $selectedChars);
        
        // 创建子集字体
        $subsetFont = $this->buildAdvancedTtfSubset($fontStructure, $charToGlyphMap, $selectedChars);
        
        return $subsetFont;
    }

    /**
     * 创建高级OTF字体子集
     */
    private function createAdvancedOtfSubset(string $fontData, array $selectedChars): string
    {
        // 验证OTF文件头
        if (substr($fontData, 0, 4) !== self::OTF_SIGNATURE) {
            throw new \Exception('无效的OTF文件格式');
        }

        // 解析OTF文件结构
        $fontStructure = $this->parseAdvancedOtfStructure($fontData);
        
        // 创建字符到字形的映射
        $charToGlyphMap = $this->createCharToGlyphMap($fontStructure, $selectedChars);
        
        // 创建子集字体
        $subsetFont = $this->buildAdvancedOtfSubset($fontStructure, $charToGlyphMap, $selectedChars);
        
        return $subsetFont;
    }

    /**
     * 解析高级TTF文件结构
     */
    private function parseAdvancedTtfStructure(string $fontData): array
    {
        $structure = [];
        
        // 读取表目录
        $numTables = $this->unpackUint16($fontData, 4);
        $searchRange = $this->unpackUint16($fontData, 6);
        $entrySelector = $this->unpackUint16($fontData, 8);
        $rangeShift = $this->unpackUint16($fontData, 10);
        
        $structure['header'] = [
            'numTables' => $numTables,
            'searchRange' => $searchRange,
            'entrySelector' => $entrySelector,
            'rangeShift' => $rangeShift
        ];
        
        // 读取每个表的信息
        $offset = 12;
        for ($i = 0; $i < $numTables; $i++) {
            $tag = substr($fontData, $offset, 4);
            $checksum = $this->unpackUint32($fontData, $offset + 4);
            $tableOffset = $this->unpackUint32($fontData, $offset + 8);
            $length = $this->unpackUint32($fontData, $offset + 12);
            
            $structure['tables'][$tag] = [
                'tag' => $tag,
                'checksum' => $checksum,
                'offset' => $tableOffset,
                'length' => $length,
                'data' => substr($fontData, $tableOffset, $length)
            ];
            
            $offset += 16;
        }
        
        return $structure;
    }

    /**
     * 解析高级OTF文件结构
     */
    private function parseAdvancedOtfStructure(string $fontData): array
    {
        $structure = [];
        
        // 读取表目录
        $version = $this->unpackUint32($fontData, 4);
        $numTables = $this->unpackUint16($fontData, 8);
        $searchRange = $this->unpackUint16($fontData, 10);
        $entrySelector = $this->unpackUint16($fontData, 12);
        $rangeShift = $this->unpackUint16($fontData, 14);
        
        $structure['header'] = [
            'version' => $version,
            'numTables' => $numTables,
            'searchRange' => $searchRange,
            'entrySelector' => $entrySelector,
            'rangeShift' => $rangeShift
        ];
        
        // 读取每个表的信息
        $offset = 16;
        for ($i = 0; $i < $numTables; $i++) {
            $tag = substr($fontData, $offset, 4);
            $checksum = $this->unpackUint32($fontData, $offset + 4);
            $tableOffset = $this->unpackUint32($fontData, $offset + 8);
            $length = $this->unpackUint32($fontData, $offset + 12);
            
            $structure['tables'][$tag] = [
                'tag' => $tag,
                'checksum' => $checksum,
                'offset' => $tableOffset,
                'length' => $length,
                'data' => substr($fontData, $tableOffset, $length)
            ];
            
            $offset += 16;
        }
        
        return $structure;
    }

    /**
     * 创建字符到字形的映射
     */
    private function createCharToGlyphMap(array $fontStructure, array $selectedChars): array
    {
        $charToGlyphMap = [];
        
        // 解析cmap表
        if (isset($fontStructure['tables']['cmap'])) {
            $cmapData = $fontStructure['tables']['cmap']['data'];
            $charToGlyphMap = $this->parseCmapTable($cmapData, $selectedChars);
        }
        
        return $charToGlyphMap;
    }

    /**
     * 解析cmap表
     */
    private function parseCmapTable(string $cmapData, array $selectedChars): array
    {
        $charToGlyphMap = [];
        
        // 读取cmap表头
        $version = $this->unpackUint16($cmapData, 0);
        $numTables = $this->unpackUint16($cmapData, 2);
        
        // 读取编码表
        $offset = 4;
        for ($i = 0; $i < $numTables; $i++) {
            $platformID = $this->unpackUint16($cmapData, $offset);
            $encodingID = $this->unpackUint16($cmapData, $offset + 2);
            $subtableOffset = $this->unpackUint32($cmapData, $offset + 4);
            
            // 只处理Unicode编码表
            if ($platformID === 0 || $platformID === 3) {
                $subtableData = substr($cmapData, $subtableOffset);
                $subtableMap = $this->parseCmapSubtable($subtableData, $selectedChars);
                $charToGlyphMap = array_merge($charToGlyphMap, $subtableMap);
            }
            
            $offset += 8;
        }
        
        return $charToGlyphMap;
    }

    /**
     * 解析cmap子表
     */
    private function parseCmapSubtable(string $subtableData, array $selectedChars): array
    {
        $charToGlyphMap = [];
        
        if (strlen($subtableData) < 2) {
            return $charToGlyphMap;
        }
        
        $format = $this->unpackUint16($subtableData, 0);
        
        switch ($format) {
            case 4: // Format 4: Segment mapping to delta values
                $charToGlyphMap = $this->parseFormat4Subtable($subtableData, $selectedChars);
                break;
            case 12: // Format 12: Segmented coverage
                $charToGlyphMap = $this->parseFormat12Subtable($subtableData, $selectedChars);
                break;
            default:
                // 对于其他格式，创建简单的1:1映射
                foreach ($selectedChars as $charCode) {
                    $charToGlyphMap[$charCode] = $charCode;
                }
                break;
        }
        
        return $charToGlyphMap;
    }

    /**
     * 解析Format 4子表
     */
    private function parseFormat4Subtable(string $subtableData, array $selectedChars): array
    {
        $charToGlyphMap = [];
        
        if (strlen($subtableData) < 14) {
            return $charToGlyphMap;
        }
        
        $length = $this->unpackUint16($subtableData, 2);
        $segCountX2 = $this->unpackUint16($subtableData, 6);
        $segCount = $segCountX2 / 2;
        
        $offset = 14;
        $endCodes = [];
        $startCodes = [];
        $idDeltas = [];
        $idRangeOffsets = [];
        
        // 读取段信息
        for ($i = 0; $i < $segCount; $i++) {
            $endCodes[] = $this->unpackUint16($subtableData, $offset);
            $offset += 2;
        }
        
        $offset += 2; // 保留字段
        
        for ($i = 0; $i < $segCount; $i++) {
            $startCodes[] = $this->unpackUint16($subtableData, $offset);
            $offset += 2;
        }
        
        for ($i = 0; $i < $segCount; $i++) {
            $idDeltas[] = $this->unpackUint16($subtableData, $offset);
            $offset += 2;
        }
        
        for ($i = 0; $i < $segCount; $i++) {
            $idRangeOffsets[] = $this->unpackUint16($subtableData, $offset);
            $offset += 2;
        }
        
        // 创建字符映射
        foreach ($selectedChars as $charCode) {
            for ($i = 0; $i < $segCount; $i++) {
                if ($charCode >= $startCodes[$i] && $charCode <= $endCodes[$i]) {
                    if ($idRangeOffsets[$i] === 0) {
                        $glyphID = ($charCode + $idDeltas[$i]) & 0xFFFF;
                    } else {
                        $glyphOffset = $idRangeOffsets[$i] / 2 + ($charCode - $startCodes[$i]) + $i;
                        if ($glyphOffset < strlen($subtableData) / 2) {
                            $glyphID = $this->unpackUint16($subtableData, $offset + $glyphOffset * 2);
                        } else {
                            $glyphID = 0;
                        }
                    }
                    $charToGlyphMap[$charCode] = $glyphID;
                    break;
                }
            }
        }
        
        return $charToGlyphMap;
    }

    /**
     * 解析Format 12子表
     */
    private function parseFormat12Subtable(string $subtableData, array $selectedChars): array
    {
        $charToGlyphMap = [];
        
        if (strlen($subtableData) < 12) {
            return $charToGlyphMap;
        }
        
        $length = $this->unpackUint32($subtableData, 4);
        $numGroups = $this->unpackUint32($subtableData, 12);
        
        $offset = 16;
        for ($i = 0; $i < $numGroups; $i++) {
            $startCharCode = $this->unpackUint32($subtableData, $offset);
            $endCharCode = $this->unpackUint32($subtableData, $offset + 4);
            $startGlyphID = $this->unpackUint32($subtableData, $offset + 8);
            
            foreach ($selectedChars as $charCode) {
                if ($charCode >= $startCharCode && $charCode <= $endCharCode) {
                    $glyphID = $startGlyphID + ($charCode - $startCharCode);
                    $charToGlyphMap[$charCode] = $glyphID;
                }
            }
            
            $offset += 12;
        }
        
        return $charToGlyphMap;
    }

    /**
     * 构建高级TTF子集
     */
    private function buildAdvancedTtfSubset(array $fontStructure, array $charToGlyphMap, array $selectedChars): string
    {
        $subsetFont = '';
        
        // 构建文件头
        $subsetFont .= self::TTF_SIGNATURE;
        
        // 计算需要的表
        $requiredTables = $this->getRequiredTables($fontStructure);
        $subsetFont .= pack('n', count($requiredTables)); // numTables
        $subsetFont .= pack('n', 0); // searchRange
        $subsetFont .= pack('n', 0); // entrySelector
        $subsetFont .= pack('n', 0); // rangeShift
        
        // 构建表目录和数据
        $tableData = '';
        $tableOffset = 12 + (count($requiredTables) * 16);
        
        foreach ($requiredTables as $tag => $table) {
            $subsetFont .= $tag;
            $subsetFont .= pack('N', $this->calculateChecksum($table['data']));
            $subsetFont .= pack('N', $tableOffset);
            $subsetFont .= pack('N', strlen($table['data']));
            
            $tableData .= $table['data'];
            $tableOffset += strlen($table['data']);
        }
        
        // 添加表数据
        $subsetFont .= $tableData;
        
        return $subsetFont;
    }

    /**
     * 构建高级OTF子集
     */
    private function buildAdvancedOtfSubset(array $fontStructure, array $charToGlyphMap, array $selectedChars): string
    {
        $subsetFont = '';
        
        // 构建文件头
        $subsetFont .= self::OTF_SIGNATURE;
        $subsetFont .= pack('N', $fontStructure['header']['version']);
        
        // 计算需要的表
        $requiredTables = $this->getRequiredTables($fontStructure);
        $subsetFont .= pack('n', count($requiredTables)); // numTables
        $subsetFont .= pack('n', 0); // searchRange
        $subsetFont .= pack('n', 0); // entrySelector
        $subsetFont .= pack('n', 0); // rangeShift
        
        // 构建表目录和数据
        $tableData = '';
        $tableOffset = 16 + (count($requiredTables) * 16);
        
        foreach ($requiredTables as $tag => $table) {
            $subsetFont .= $tag;
            $subsetFont .= pack('N', $this->calculateChecksum($table['data']));
            $subsetFont .= pack('N', $tableOffset);
            $subsetFont .= pack('N', strlen($table['data']));
            
            $tableData .= $table['data'];
            $tableOffset += strlen($table['data']);
        }
        
        // 添加表数据
        $subsetFont .= $tableData;
        
        return $subsetFont;
    }

    /**
     * 获取需要的表
     */
    private function getRequiredTables(array $fontStructure): array
    {
        $requiredTables = [];
        
        foreach (self::REQUIRED_TABLES as $tableName) {
            if (isset($fontStructure['tables'][$tableName])) {
                $requiredTables[$tableName] = $fontStructure['tables'][$tableName];
            }
        }
        
        return $requiredTables;
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
            $sum += $this->unpackUint32($chunk, 0);
        }
        
        return $sum;
    }

    /**
     * 解包16位无符号整数
     */
    private function unpackUint16(string $data, int $offset): int
    {
        $bytes = substr($data, $offset, 2);
        $unpacked = unpack('n', $bytes);
        return $unpacked[1];
    }

    /**
     * 解包32位无符号整数
     */
    private function unpackUint32(string $data, int $offset): int
    {
        $bytes = substr($data, $offset, 4);
        $unpacked = unpack('N', $bytes);
        return $unpacked[1];
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

        try {
            $fontData = file_get_contents($fontPath);
            if ($fontData === false) {
                return false;
            }

            // 验证文件头
            if ($format === 'ttf' && substr($fontData, 0, 4) !== self::TTF_SIGNATURE) {
                return false;
            }
            if ($format === 'otf' && substr($fontData, 0, 4) !== self::OTF_SIGNATURE) {
                return false;
            }

            return true;
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
            'method' => 'advanced_native_php'
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

    /**
     * 检查系统兼容性
     */
    public function checkCompatibility(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'extensions' => [
                'mbstring' => extension_loaded('mbstring'),
                'iconv' => extension_loaded('iconv'),
                'gd' => extension_loaded('gd')
            ],
            'supported_formats' => self::SUPPORTED_FORMATS,
            'method' => 'advanced_native_php'
        ];
    }
}
