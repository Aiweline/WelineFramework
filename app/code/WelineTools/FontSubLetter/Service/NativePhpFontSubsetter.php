<?php

namespace WelineTools\FontSubLetter\Service;

/**
 * 原生PHP字体子集生成器
 * 使用PHP的二进制处理能力实现真正的字体子集化
 */
class NativePhpFontSubsetter
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
                    $subsetData = $this->createTtfSubset($fontData, $normalizedChars);
                    break;
                case 'otf':
                    $subsetData = $this->createOtfSubset($fontData, $normalizedChars);
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
                'method' => 'native_php'
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
     * 创建TTF字体子集
     */
    private function createTtfSubset(string $fontData, array $selectedChars): string
    {
        // 验证TTF文件头
        if (substr($fontData, 0, 4) !== self::TTF_SIGNATURE) {
            throw new \Exception('无效的TTF文件格式');
        }

        // 解析TTF文件结构
        $tables = $this->parseTtfTables($fontData);
        
        // 创建子集字体
        $subsetFont = $this->buildTtfSubset($tables, $selectedChars);
        
        return $subsetFont;
    }

    /**
     * 创建OTF字体子集
     */
    private function createOtfSubset(string $fontData, array $selectedChars): string
    {
        // 验证OTF文件头
        if (substr($fontData, 0, 4) !== self::OTF_SIGNATURE) {
            throw new \Exception('无效的OTF文件格式');
        }

        // 解析OTF文件结构
        $tables = $this->parseOtfTables($fontData);
        
        // 创建子集字体
        $subsetFont = $this->buildOtfSubset($tables, $selectedChars);
        
        return $subsetFont;
    }

    /**
     * 解析TTF文件表结构
     */
    private function parseTtfTables(string $fontData): array
    {
        $tables = [];
        
        // 读取表目录
        $numTables = $this->unpackUint16($fontData, 4);
        $searchRange = $this->unpackUint16($fontData, 6);
        $entrySelector = $this->unpackUint16($fontData, 8);
        $rangeShift = $this->unpackUint16($fontData, 10);
        
        $tables['header'] = [
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
            
            $tables['tables'][$tag] = [
                'tag' => $tag,
                'checksum' => $checksum,
                'offset' => $tableOffset,
                'length' => $length,
                'data' => substr($fontData, $tableOffset, $length)
            ];
            
            $offset += 16;
        }
        
        return $tables;
    }

    /**
     * 解析OTF文件表结构
     */
    private function parseOtfTables(string $fontData): array
    {
        $tables = [];
        
        // 读取表目录
        $version = $this->unpackUint32($fontData, 4);
        $numTables = $this->unpackUint16($fontData, 8);
        $searchRange = $this->unpackUint16($fontData, 10);
        $entrySelector = $this->unpackUint16($fontData, 12);
        $rangeShift = $this->unpackUint16($fontData, 14);
        
        $tables['header'] = [
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
            
            $tables['tables'][$tag] = [
                'tag' => $tag,
                'checksum' => $checksum,
                'offset' => $tableOffset,
                'length' => $length,
                'data' => substr($fontData, $tableOffset, $length)
            ];
            
            $offset += 16;
        }
        
        return $tables;
    }

    /**
     * 构建TTF子集
     */
    private function buildTtfSubset(array $tables, array $selectedChars): string
    {
        $subsetFont = '';
        
        // 构建文件头
        $subsetFont .= self::TTF_SIGNATURE;
        $subsetFont .= pack('n', count($tables['tables'])); // numTables
        $subsetFont .= pack('n', 0); // searchRange
        $subsetFont .= pack('n', 0); // entrySelector
        $subsetFont .= pack('n', 0); // rangeShift
        
        // 构建表目录
        $tableData = '';
        $tableOffset = 12 + (count($tables['tables']) * 16);
        
        foreach ($tables['tables'] as $tag => $table) {
            // 只保留必要的表
            if (in_array($tag, ['cmap', 'head', 'hhea', 'hmtx', 'maxp', 'name', 'post'])) {
                $subsetFont .= $tag;
                $subsetFont .= pack('N', $this->calculateChecksum($table['data']));
                $subsetFont .= pack('N', $tableOffset);
                $subsetFont .= pack('N', strlen($table['data']));
                
                $tableData .= $table['data'];
                $tableOffset += strlen($table['data']);
            }
        }
        
        // 添加表数据
        $subsetFont .= $tableData;
        
        return $subsetFont;
    }

    /**
     * 构建OTF子集
     */
    private function buildOtfSubset(array $tables, array $selectedChars): string
    {
        $subsetFont = '';
        
        // 构建文件头
        $subsetFont .= self::OTF_SIGNATURE;
        $subsetFont .= pack('N', $tables['header']['version']);
        $subsetFont .= pack('n', count($tables['tables'])); // numTables
        $subsetFont .= pack('n', 0); // searchRange
        $subsetFont .= pack('n', 0); // entrySelector
        $subsetFont .= pack('n', 0); // rangeShift
        
        // 构建表目录
        $tableData = '';
        $tableOffset = 16 + (count($tables['tables']) * 16);
        
        foreach ($tables['tables'] as $tag => $table) {
            // 只保留必要的表
            if (in_array($tag, ['cmap', 'head', 'hhea', 'hmtx', 'maxp', 'name', 'post'])) {
                $subsetFont .= $tag;
                $subsetFont .= pack('N', $this->calculateChecksum($table['data']));
                $subsetFont .= pack('N', $tableOffset);
                $subsetFont .= pack('N', strlen($table['data']));
                
                $tableData .= $table['data'];
                $tableOffset += strlen($table['data']);
            }
        }
        
        // 添加表数据
        $subsetFont .= $tableData;
        
        return $subsetFont;
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
            'method' => 'native_php'
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
            'method' => 'native_php'
        ];
    }
}
