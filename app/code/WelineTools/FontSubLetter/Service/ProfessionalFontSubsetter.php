<?php

namespace WelineTools\FontSubLetter\Service;

use FontLib\Font;
use FontLib\Table\Type\cmap;
use FontLib\Table\Type\glyf;
use FontLib\Table\Type\loca;
use FontLib\Table\Type\name;
use FontLib\Table\Type\head;
use FontLib\Table\Type\hhea;
use FontLib\Table\Type\hmtx;
use FontLib\Table\Type\maxp;
use FontLib\Table\Type\post;
use FontLib\Table\Type\os2;
use FontLib\BinaryStream;

/**
 * 专业字体子集生成器
 * 使用php-font-lib库的正确API生成有效的字体子集
 */
class ProfessionalFontSubsetter
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

            // 加载字体文件
            $font = Font::load($inputPath);
            if (!$font) {
                throw new \Exception('无法加载字体文件');
            }

            // 解析字体
            $font->parse();

            // 验证字体是否有效
            if (!$this->isValidFont($font)) {
                throw new \Exception('字体文件格式无效或损坏');
            }

            // 使用php-font-lib的内置子集功能
            $font->setSubset($normalizedChars);

            // 编码字体子集
            $subsetData = $font->encode();

            // 检查编码结果
            if (empty($subsetData)) {
                throw new \Exception('字体子集编码失败');
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
            $font = Font::load($fontPath);
            if (!$font) {
                throw new \Exception('无法加载字体文件');
            }

            $font->parse();

            // 检查字体是否有效
            if (!$this->isValidFont($font)) {
                throw new \Exception('字体文件格式无效或损坏');
            }

            return [
                'name' => $font->getFontName(),
                'subfamily' => $font->getFontSubfamily(),
                'full_name' => $font->getFontFullName(),
                'copyright' => $font->getFontCopyright(),
                'version' => $font->getFontVersion(),
                'postscript_name' => $font->getFontPostscriptName(),
                'weight' => $font->getFontWeight()
            ];

        } catch (\Exception $e) {
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
            $font = Font::load($fontPath);
            if (!$font) {
                return false;
            }

            $font->parse();
            
            return $this->isValidFont($font);

        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 获取字体支持的字符
     */
    public function getSupportedCharacters(string $fontPath): array
    {
        try {
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

            return array_keys($charMap);

        } catch (\Exception $e) {
            return [];
        }
    }
}
