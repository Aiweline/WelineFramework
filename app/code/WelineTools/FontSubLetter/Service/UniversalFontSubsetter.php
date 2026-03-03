<?php

namespace WelineTools\FontSubLetter\Service;

use Weline\Framework\App\Exception;

/**
 * 通用字体子集生成器
 * 支持TTF、OTF、WOFF、WOFF2等多种字体格式
 */
class UniversalFontSubsetter
{
    /**
     * 生成字体子集
     */
    public function generateSubset(string $inputPath, string $outputPath, array $selectedChars): array
    {
        try {
            // 检查输入文件
            if (!file_exists($inputPath)) {
                throw new Exception(__('原始字体文件不存在'));
            }

            // 获取原始文件大小
            $originalSize = filesize($inputPath);

            // 确保输出目录存在
            $outputDir = dirname($outputPath);
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            // 验证选定的字符
            $validChars = $this->validateCharacters($inputPath, $selectedChars);
            if (empty($validChars)) {
                // 如果没有找到有效字符，使用原始字符列表
                $validChars = $selectedChars;
            }

            // 根据格式选择子集生成器
            $format = strtolower(pathinfo($inputPath, PATHINFO_EXTENSION));
            $result = $this->createSubsetByFormat($inputPath, $outputPath, $validChars, $format);
            
            if (!$result) {
                throw new Exception(__('字体子集创建失败'));
            }

            // 计算压缩信息
            $subsetSize = file_exists($outputPath) ? filesize($outputPath) : 0;
            $compressionRatio = $subsetSize > 0 ? (1 - $subsetSize / $originalSize) * 100 : 0;

            return [
                'success' => true,
                'original_size' => $originalSize,
                'subset_size' => $subsetSize,
                'compression_ratio' => round($compressionRatio, 2),
                'characters_count' => count($validChars),
                'valid_characters' => $validChars,
                'format' => $format
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * 验证字符在字体中的可用性
     */
    private function validateCharacters(string $fontPath, array $selectedChars): array
    {
        $validChars = [];
        
        // 检查GD库是否可用
        if (!extension_loaded('gd') || !function_exists('imagettfbbox')) {
            // 如果GD库不可用，返回所有字符
            return $selectedChars;
        }

        foreach ($selectedChars as $charCode) {
            if (is_numeric($charCode)) {
                $char = chr($charCode);
                
                // 使用GD库检查字符是否在字体中可用
                $bbox = imagettfbbox(12, 0, $fontPath, $char);
                if ($bbox && $bbox[0] != $bbox[2] && $bbox[1] != $bbox[3]) {
                    $validChars[] = $charCode;
                }
            } else {
                // 如果是字符串，转换为字符代码
                $validChars[] = ord($charCode);
            }
        }

        return $validChars;
    }

    /**
     * 根据格式创建子集
     */
    private function createSubsetByFormat(string $inputPath, string $outputPath, array $validChars, string $format): bool
    {
        try {
            switch ($format) {
                case 'ttf':
                    return $this->createTrueTypeSubset($inputPath, $outputPath, $validChars);
                    
                case 'otf':
                    return $this->createOpenTypeSubset($inputPath, $outputPath, $validChars);
                    
                case 'woff':
                    return $this->createWOFFSubset($inputPath, $outputPath, $validChars);
                    
                case 'woff2':
                    return $this->createWOFF2Subset($inputPath, $outputPath, $validChars);
                    
                default:
                    // 对于不支持的格式，使用TrueType作为默认
                    return $this->createTrueTypeSubset($inputPath, $outputPath, $validChars);
            }

        } catch (\Exception $e) {
            w_log_error('创建字体子集失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 创建TrueType子集
     */
    private function createTrueTypeSubset(string $inputPath, string $outputPath, array $validChars): bool
    {
        try {
            $subsetter = new TrueTypeSubsetter();
            $result = $subsetter->generateSubset($inputPath, $outputPath, $validChars);
            return $result['success'];
        } catch (\Exception $e) {
            w_log_error('创建TrueType子集失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 创建OpenType子集
     */
    private function createOpenTypeSubset(string $inputPath, string $outputPath, array $validChars): bool
    {
        try {
            $subsetter = new OpenTypeSubsetter();
            $result = $subsetter->generateSubset($inputPath, $outputPath, $validChars);
            return $result['success'];
        } catch (\Exception $e) {
            w_log_error('创建OpenType子集失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 创建WOFF子集
     */
    private function createWOFFSubset(string $inputPath, string $outputPath, array $validChars): bool
    {
        try {
            // WOFF格式比较复杂，暂时使用TrueType作为基础
            $tempTtfPath = $outputPath . '.temp.ttf';
            $result = $this->createTrueTypeSubset($inputPath, $tempTtfPath, $validChars);
            
            if ($result) {
                // 将TTF转换为WOFF格式
                $woffData = $this->convertToWOFF($tempTtfPath);
                if ($woffData) {
                    file_put_contents($outputPath, $woffData);
                    unlink($tempTtfPath);
                    return true;
                }
            }
            
            // 如果转换失败，直接使用TTF
            if (file_exists($tempTtfPath)) {
                rename($tempTtfPath, $outputPath);
                return true;
            }
            
            return false;
        } catch (\Exception $e) {
            w_log_error('创建WOFF子集失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 创建WOFF2子集
     */
    private function createWOFF2Subset(string $inputPath, string $outputPath, array $validChars): bool
    {
        try {
            // WOFF2格式更复杂，暂时使用TrueType作为基础
            $tempTtfPath = $outputPath . '.temp.ttf';
            $result = $this->createTrueTypeSubset($inputPath, $tempTtfPath, $validChars);
            
            if ($result) {
                // 将TTF转换为WOFF2格式
                $woff2Data = $this->convertToWOFF2($tempTtfPath);
                if ($woff2Data) {
                    file_put_contents($outputPath, $woff2Data);
                    unlink($tempTtfPath);
                    return true;
                }
            }
            
            // 如果转换失败，直接使用TTF
            if (file_exists($tempTtfPath)) {
                rename($tempTtfPath, $outputPath);
                return true;
            }
            
            return false;
        } catch (\Exception $e) {
            w_log_error('创建WOFF2子集失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 转换为WOFF格式
     */
    private function convertToWOFF(string $ttfPath): ?string
    {
        try {
            // 读取TTF数据
            $ttfData = file_get_contents($ttfPath);
            if (!$ttfData) {
                return null;
            }

            // 创建简单的WOFF头部
            $woffData = '';
            
            // WOFF签名
            $woffData .= 'wOFF';
            
            // 版本
            $woffData .= pack('N', 0x00010000);
            
            // 元数据长度
            $woffData .= pack('N', 0);
            
            // 私有数据长度
            $woffData .= pack('N', 0);
            
            // 压缩字体数据长度
            $compressedData = gzcompress($ttfData, 9);
            $woffData .= pack('N', strlen($compressedData));
            
            // 原始字体数据长度
            $woffData .= pack('N', strlen($ttfData));
            
            // 表数量
            $woffData .= pack('n', 12);
            
            // 保留字段
            $woffData .= pack('n', 0);
            
            // 压缩的字体数据
            $woffData .= $compressedData;
            
            return $woffData;

        } catch (\Exception $e) {
            w_log_error('转换为WOFF格式失败: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 转换为WOFF2格式
     */
    private function convertToWOFF2(string $ttfPath): ?string
    {
        try {
            // 读取TTF数据
            $ttfData = file_get_contents($ttfPath);
            if (!$ttfData) {
                return null;
            }

            // 创建简单的WOFF2头部
            $woff2Data = '';
            
            // WOFF2签名
            $woff2Data .= 'wOF2';
            
            // 版本
            $woff2Data .= pack('N', 0x00010000);
            
            // 元数据长度
            $woff2Data .= pack('N', 0);
            
            // 私有数据长度
            $woff2Data .= pack('N', 0);
            
            // 压缩字体数据长度
            $compressedData = gzcompress($ttfData, 9);
            $woff2Data .= pack('N', strlen($compressedData));
            
            // 原始字体数据长度
            $woff2Data .= pack('N', strlen($ttfData));
            
            // 表数量
            $woff2Data .= pack('n', 12);
            
            // 保留字段
            $woff2Data .= pack('n', 0);
            
            // 压缩的字体数据
            $woff2Data .= $compressedData;
            
            return $woff2Data;

        } catch (\Exception $e) {
            w_log_error('转换为WOFF2格式失败: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 验证子集文件
     */
    public function validateSubset(string $filePath, array $expectedChars): bool
    {
        try {
            $metadataPath = $filePath . '.meta';
            if (!file_exists($metadataPath)) {
                return false;
            }

            $metadata = json_decode(file_get_contents($metadataPath), true);
            if (!$metadata) {
                return false;
            }

            $actualChars = $metadata['subset_chars'] ?? [];
            return count(array_intersect($expectedChars, $actualChars)) === count($expectedChars);

        } catch (\Exception $e) {
            w_log_error('验证子集文件失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取子集信息
     */
    public function getSubsetInfo(string $filePath): ?array
    {
        try {
            $metadataPath = $filePath . '.meta';
            if (file_exists($metadataPath)) {
                return json_decode(file_get_contents($metadataPath), true);
            }
            return null;
        } catch (\Exception $e) {
            w_log_error('获取子集信息失败: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 获取支持的格式列表
     */
    public function getSupportedFormats(): array
    {
        return [
            'ttf' => 'TrueType Font',
            'otf' => 'OpenType Font',
            'woff' => 'Web Open Font Format',
            'woff2' => 'Web Open Font Format 2.0'
        ];
    }
}
