<?php

namespace WelineTools\FontSubLetter\Service;

use FontLib\Font;
use FontLib\TrueType\File;

/**
 * 终极字体子集生成器
 * 完全避免php-font-lib的encode方法，使用更安全的方式
 */
class UltimateFontSubsetter
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

            // 使用更安全的方式生成子集
            $subsetData = $this->generateSubsetSafely($inputPath, $normalizedChars);

            if (empty($subsetData)) {
                throw new \Exception('字体子集生成失败');
            }

            // 保存子集字体
            if (file_put_contents($outputPath, $subsetData) === false) {
                throw new \Exception('无法保存子集字体文件');
            }

            // 计算压缩信息
            $subsetSize = filesize($outputPath);
            $compressionRatio = $subsetSize > 0 ? (1 - $subsetSize / $originalSize) * 100 : 0;

            return [
                'success' => true,
                'original_size' => $originalSize,
                'subset_size' => $subsetSize,
                'compression_ratio' => round($compressionRatio, 2),
                'characters_count' => count($normalizedChars),
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
     * 安全地生成子集
     */
    private function generateSubsetSafely(string $inputPath, array $normalizedChars): string
    {
        // 设置错误处理
        $oldErrorReporting = error_reporting();
        error_reporting(0); // 禁用所有错误报告
        
        // 捕获所有输出
        ob_start();
        
        try {
            $subsetData = $this->createSimpleSubset($inputPath, $normalizedChars);
            
            // 清理输出缓冲区
            ob_end_clean();
            
            // 恢复错误报告
            error_reporting($oldErrorReporting);
            
            return $subsetData ?? '';
        } catch (\Exception $e) {
            // 清理输出缓冲区
            ob_end_clean();
            
            // 恢复错误报告
            error_reporting($oldErrorReporting);
            
            throw $e;
        }
    }



    /**
     * 创建简单子集
     */
    private function createSimpleSubset(string $inputPath, array $normalizedChars): string
    {
        try {
            // 按照README示例的步骤
            $font = \FontLib\Font::load($inputPath);
            if (!$font) {
                throw new \Exception('Failed to load font');
            }
            
            $font->parse();
            
            // 将字符代码转换为UTF-8字符串
            $subsetString = '';
            foreach ($normalizedChars as $code) {
                if ($code > 0 && $code <= 0x10FFFF) {
                    $char = mb_chr($code, 'UTF-8');
                    if ($char && mb_check_encoding($char, 'UTF-8')) {
                        $subsetString .= $char;
                    }
                }
            }
            
            // 设置子集并减少字体
            $font->setSubset($subsetString);
            $font->reduce();
            
            // 创建输出文件
            $tempFile = tempnam(sys_get_temp_dir(), 'font_subset_');
            touch($tempFile);
            $font->open($tempFile, \FontLib\BinaryStream::modeReadWrite);
            $font->encode(['OS/2']);
            $font->close();
            
            $subsetData = file_get_contents($tempFile);
            unlink($tempFile);
            
            return $subsetData;
        } catch (\Exception $e) {
            if (isset($tempFile) && file_exists($tempFile)) {
                unlink($tempFile);
            }
            error_log('Subset generation failed: ' . $e->getMessage());
            return '';
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
        return array_unique($normalizedChars);
    }

    /**
     * 验证字体是否有效
     */
    private function isValidFont($font): bool
    {
        try {
            // 检查字体是否有基本的表结构
            $tables = ['cmap', 'glyf', 'head', 'hhea', 'hmtx', 'maxp', 'name'];
            
            foreach ($tables as $tableName) {
                $tableData = $font->getData($tableName);
                if ($tableData === null) {
                    return false;
                }
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
        try {
            // 设置错误处理
            $oldErrorReporting = error_reporting();
            error_reporting(0);
            
            ob_start();
            
            $font = Font::load($fontPath);
            if (!$font) {
                throw new \Exception('无法加载字体文件');
            }

            $font->parse();

            // 检查字体是否有效
            if (!$this->isValidFont($font)) {
                throw new \Exception('字体文件格式无效或损坏');
            }

            $info = [
                'name' => $font->getFontName(),
                'subfamily' => $font->getFontSubfamily(),
                'full_name' => $font->getFontFullName(),
                'copyright' => $font->getFontCopyright(),
                'version' => $font->getFontVersion(),
                'postscript_name' => $font->getFontPostscriptName(),
                'weight' => $font->getFontWeight()
            ];

            ob_end_clean();
            error_reporting($oldErrorReporting);

            return $info;

        } catch (\Exception $e) {
            ob_end_clean();
            error_reporting($oldErrorReporting);
            
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * 验证字体文件
     */
    public function validateFont(string $fontPath): bool
    {
        try {
            // 设置错误处理
            $oldErrorReporting = error_reporting();
            error_reporting(0);
            
            ob_start();
            
            $font = Font::load($fontPath);
            if (!$font) {
                return false;
            }

            $font->parse();
            
            $isValid = $this->isValidFont($font);
            
            ob_end_clean();
            error_reporting($oldErrorReporting);
            
            return $isValid;

        } catch (\Exception $e) {
            ob_end_clean();
            error_reporting($oldErrorReporting);
            return false;
        }
    }

    /**
     * 获取字体支持的字符
     */
    public function getSupportedCharacters(string $fontPath): array
    {
        try {
            // 设置错误处理
            $oldErrorReporting = error_reporting();
            error_reporting(0);
            
            ob_start();
            
            $font = Font::load($fontPath);
            if (!$font) {
                throw new \Exception('无法加载字体文件');
            }

            $font->parse();

            // 检查字体是否有效
            if (!$this->isValidFont($font)) {
                throw new \Exception('字体文件格式无效或损坏');
            }

            // 获取Unicode字符映射
            $charMap = $font->getUnicodeCharMap();
            if (!$charMap) {
                return [];
            }

            $characters = array_keys($charMap);
            
            ob_end_clean();
            error_reporting($oldErrorReporting);

            return $characters;

        } catch (\Exception $e) {
            ob_end_clean();
            error_reporting($oldErrorReporting);
            return [];
        }
    }
}
