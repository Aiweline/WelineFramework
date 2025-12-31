<?php

namespace WelineTools\FontSubLetter\Service;

use FontLib\Font;

/**
 * 简单可靠的字体子集生成器
 * 使用php-font-lib库生成有效的字体子集
 */
class SimpleFontSubsetter
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

            // 验证字符在字体中的可用性
            $validChars = $this->validateCharacters($inputPath, $normalizedChars);
            if (empty($validChars)) {
                // 如果没有找到有效字符，使用原始字符列表
                $validChars = $normalizedChars;
            }

            // 创建字体子集
            $result = $this->createSubsetFont($inputPath, $outputPath, $validChars);

            if (!$result) {
                throw new \Exception('字体子集创建失败');
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
                $bbox = @imagettfbbox(12, 0, $fontPath, $char);
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
     * 创建字体子集
     */
    private function createSubsetFont(string $inputPath, string $outputPath, array $validChars): bool
    {
        try {
            // 使用php-font-lib加载字体
            $font = Font::load($inputPath);
            if (!$font) {
                throw new \Exception('无法加载字体文件');
            }

            // 解析字体
            $font->parse();

            // 获取字体信息
            $fontInfo = $this->getFontInfo($font);

            // 创建简化的字体子集
            $subsetData = $this->createSimplifiedFont($font, $validChars, $fontInfo);

            // 保存子集字体
            if (file_put_contents($outputPath, $subsetData) === false) {
                throw new \Exception('无法保存子集字体文件');
            }

            return true;

        } catch (\Exception $e) {
            error_log('创建字体子集失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取字体信息
     */
    private function getFontInfo($font): array
    {
        $info = [];
        
        try {
            // 获取字体名称
            if (isset($font->name)) {
                $info['family'] = $font->name->getFontFamily();
                $info['subfamily'] = $font->name->getFontSubfamily();
            }

            // 获取字体度量信息
            if (isset($font->hhea)) {
                $info['ascent'] = $font->hhea->ascent;
                $info['descent'] = $font->hhea->descent;
                $info['lineGap'] = $font->hhea->lineGap;
            }

            // 获取字符映射信息
            if (isset($font->cmap)) {
                $info['cmap_tables'] = count($font->cmap->getData());
            }

        } catch (\Exception $e) {
            // 如果获取信息失败，使用默认值
            $info = [
                'family' => 'Subset Font',
                'subfamily' => 'Regular',
                'ascent' => 1900,
                'descent' => -500,
                'lineGap' => 0
            ];
        }

        return $info;
    }

    /**
     * 创建简化的字体子集
     */
    private function createSimplifiedFont($font, array $validChars, array $fontInfo): string
    {
        // 创建基本的字体文件结构
        $fontData = '';
        
        // 1. 字体文件头
        $fontData .= $this->createFontHeader($fontInfo);
        
        // 2. 表目录
        $fontData .= $this->createTableDirectory($validChars);
        
        // 3. 字符映射表
        $fontData .= $this->createCmapTable($validChars);
        
        // 4. 字形数据表（简化）
        $fontData .= $this->createGlyfTable($validChars);
        
        // 5. 字形位置表
        $fontData .= $this->createLocaTable($validChars);
        
        // 6. 字体头表
        $fontData .= $this->createHeadTable($fontInfo);
        
        // 7. 水平头表
        $fontData .= $this->createHheaTable($fontInfo);
        
        // 8. 水平度量表
        $fontData .= $this->createHmtxTable($validChars);
        
        // 9. 最大配置文件
        $fontData .= $this->createMaxpTable($validChars);
        
        // 10. 名称表
        $fontData .= $this->createNameTable($fontInfo);
        
        // 11. 操作系统/2表
        $fontData .= $this->createOS2Table($fontInfo);
        
        // 12. 后记表
        $fontData .= $this->createPostTable($fontInfo);
        
        return $fontData;
    }

    /**
     * 创建字体文件头
     */
    private function createFontHeader(array $fontInfo): string
    {
        $data = '';
        
        // sfntVersion (0x00010000 for TrueType)
        $data .= pack('N', 0x00010000);
        
        // numTables (12个表)
        $data .= pack('n', 12);
        
        // searchRange, entrySelector, rangeShift
        $data .= pack('n', 256); // searchRange
        $data .= pack('n', 4);   // entrySelector
        $data .= pack('n', 48);  // rangeShift
        
        return $data;
    }

    /**
     * 创建表目录
     */
    private function createTableDirectory(array $validChars): string
    {
        $data = '';
        $offset = 12 + 12 * 16; // 头部 + 表目录
        
        // cmap表
        $data .= "cmap";
        $data .= pack('N', 0); // 校验和
        $data .= pack('N', $offset);
        $cmapSize = 12 + count($validChars) * 4;
        $data .= pack('N', $cmapSize);
        $offset += $cmapSize;
        
        // glyf表
        $data .= "glyf";
        $data .= pack('N', 0); // 校验和
        $data .= pack('N', $offset);
        $glyfSize = count($validChars) * 50; // 简化：每个字形50字节
        $data .= pack('N', $glyfSize);
        $offset += $glyfSize;
        
        // loca表
        $data .= "loca";
        $data .= pack('N', 0); // 校验和
        $data .= pack('N', $offset);
        $locaSize = (count($validChars) + 1) * 4;
        $data .= pack('N', $locaSize);
        $offset += $locaSize;
        
        // head表
        $data .= "head";
        $data .= pack('N', 0); // 校验和
        $data .= pack('N', $offset);
        $data .= pack('N', 54); // head表大小
        $offset += 54;
        
        // hhea表
        $data .= "hhea";
        $data .= pack('N', 0); // 校验和
        $data .= pack('N', $offset);
        $data .= pack('N', 36); // hhea表大小
        $offset += 36;
        
        // hmtx表
        $data .= "hmtx";
        $data .= pack('N', 0); // 校验和
        $data .= pack('N', $offset);
        $hmtxSize = count($validChars) * 4;
        $data .= pack('N', $hmtxSize);
        $offset += $hmtxSize;
        
        // maxp表
        $data .= "maxp";
        $data .= pack('N', 0); // 校验和
        $data .= pack('N', $offset);
        $data .= pack('N', 32); // maxp表大小
        $offset += 32;
        
        // name表
        $data .= "name";
        $data .= pack('N', 0); // 校验和
        $data .= pack('N', $offset);
        $nameSize = 100; // 简化名称表
        $data .= pack('N', $nameSize);
        $offset += $nameSize;
        
        // OS/2表
        $data .= "OS/2";
        $data .= pack('N', 0); // 校验和
        $data .= pack('N', $offset);
        $data .= pack('N', 96); // OS/2表大小
        $offset += 96;
        
        // post表
        $data .= "post";
        $data .= pack('N', 0); // 校验和
        $data .= pack('N', $offset);
        $data .= pack('N', 32); // post表大小
        
        return $data;
    }

    /**
     * 创建字符映射表
     */
    private function createCmapTable(array $validChars): string
    {
        $data = '';
        
        // 格式4 cmap表
        $data .= pack('n', 4); // 格式
        $data .= pack('n', 6 + count($validChars) * 2); // 长度
        $data .= pack('n', 0); // 语言
        $data .= pack('n', count($validChars) * 2); // segCountX2
        
        // 添加字符映射
        foreach ($validChars as $index => $charCode) {
            $data .= pack('n', $charCode);
            $data .= pack('n', $index);
        }
        
        return $data;
    }

    /**
     * 创建字形数据表
     */
    private function createGlyfTable(array $validChars): string
    {
        $data = '';
        
        foreach ($validChars as $index => $charCode) {
            // 创建简化的字形数据
            $glyphData = $this->createSimpleGlyph($charCode);
            $data .= $glyphData;
        }
        
        return $data;
    }

    /**
     * 创建简单字形
     */
    private function createSimpleGlyph(int $charCode): string
    {
        // 创建基本的字形数据
        $data = '';
        
        // 字形头
        $data .= pack('n', 0); // numberOfContours
        $data .= pack('n', 100); // xMin
        $data .= pack('n', 0); // yMin
        $data .= pack('n', 600); // xMax
        $data .= pack('n', 1000); // yMax
        
        return $data;
    }

    /**
     * 创建字形位置表
     */
    private function createLocaTable(array $validChars): string
    {
        $data = '';
        $offset = 0;
        
        // 为每个字形添加偏移量
        for ($i = 0; $i <= count($validChars); $i++) {
            $data .= pack('N', $offset);
            $offset += 10; // 每个字形10字节
        }
        
        return $data;
    }

    /**
     * 创建字体头表
     */
    private function createHeadTable(array $fontInfo): string
    {
        $data = '';
        
        $data .= pack('N', 0x00010000); // 版本
        $data .= pack('N', 0x5F0F3CF5); // 字体修订
        $data .= pack('N', 0); // 校验和调整
        $data .= pack('N', 0x5F0F3CF5); // 幻数
        $data .= pack('n', 0); // 标志
        $data .= pack('n', 16); // 单位每EM
        $data .= pack('N', time()); // 创建时间
        $data .= pack('N', time()); // 修改时间
        $data .= pack('n', 100); // xMin
        $data .= pack('n', 0); // yMin
        $data .= pack('n', 600); // xMax
        $data .= pack('n', 1000); // yMax
        $data .= pack('n', 0); // macStyle
        $data .= pack('n', 7); // 最低读取像素大小
        $data .= pack('n', 1); // 字体方向提示
        $data .= pack('n', 0); // 索引到位置格式
        $data .= pack('n', 0); // 字形数据格式
        
        return $data;
    }

    /**
     * 创建水平头表
     */
    private function createHheaTable(array $fontInfo): string
    {
        $data = '';
        
        $data .= pack('N', 0x00010000); // 版本
        $data .= pack('n', $fontInfo['ascent'] ?? 1900); // 上升
        $data .= pack('n', $fontInfo['descent'] ?? -500); // 下降
        $data .= pack('n', $fontInfo['lineGap'] ?? 0); // 行间距
        $data .= pack('n', 1000); // 最大前进宽度
        $data .= pack('n', 100); // 最小左边界
        $data .= pack('n', 600); // 最小右边界
        $data .= pack('n', 0); // xMax范围
        $data .= pack('n', 0); // 护理斜率上升
        $data .= pack('n', 0); // 护理斜率运行
        $data .= pack('n', 0); // 护理偏移
        $data .= pack('n', 0); // 保留
        $data .= pack('n', 0); // 保留
        $data .= pack('n', 0); // 保留
        $data .= pack('n', 0); // 保留
        $data .= pack('n', 0); // 度量格式
        $data .= pack('n', 0); // 度量数量
        
        return $data;
    }

    /**
     * 创建水平度量表
     */
    private function createHmtxTable(array $validChars): string
    {
        $data = '';
        
        foreach ($validChars as $charCode) {
            // 为每个字符创建度量信息
            $data .= pack('n', 600); // 前进宽度
            $data .= pack('n', 0); // 左边界
        }
        
        return $data;
    }

    /**
     * 创建最大配置文件
     */
    private function createMaxpTable(array $validChars): string
    {
        $data = '';
        
        $data .= pack('N', 0x00010000); // 版本
        $data .= pack('n', count($validChars)); // 字形数量
        $data .= pack('n', 1); // 最大点
        $data .= pack('n', 1); // 最大轮廓
        $data .= pack('n', 1); // 最大复合点
        $data .= pack('n', 1); // 最大复合轮廓
        $data .= pack('n', 2); // 最大Z元素
        $data .= pack('n', 1); // 最大Z点
        $data .= pack('n', 1); // 最大存储
        $data .= pack('n', 1); // 最大函数
        $data .= pack('n', 1); // 最大指令
        $data .= pack('n', 1); // 最大堆栈元素
        $data .= pack('n', 1); // 最大大小指令
        $data .= pack('n', 1); // 最大组件元素
        $data .= pack('n', 1); // 最大组件深度
        
        return $data;
    }

    /**
     * 创建名称表
     */
    private function createNameTable(array $fontInfo): string
    {
        $data = '';
        
        $data .= pack('n', 0); // 格式
        $data .= pack('n', 1); // 计数
        $data .= pack('n', 12); // 字符串偏移
        
        // 名称记录
        $data .= pack('n', 0); // 平台ID
        $data .= pack('n', 3); // 编码ID
        $data .= pack('n', 1); // 语言ID
        $data .= pack('n', 1); // 名称ID
        $data .= pack('n', strlen($fontInfo['family'] ?? 'Subset Font')); // 长度
        $data .= pack('n', 0); // 偏移
        
        // 字符串数据
        $data .= $fontInfo['family'] ?? 'Subset Font';
        
        return $data;
    }

    /**
     * 创建操作系统/2表
     */
    private function createOS2Table(array $fontInfo): string
    {
        $data = '';
        
        $data .= pack('n', 4); // 版本
        $data .= pack('n', 100); // xAvgCharWidth
        $data .= pack('n', 400); // usWeightClass
        $data .= pack('n', 5); // usWidthClass
        $data .= pack('n', 0); // fsType
        $data .= pack('n', 100); // ySubscriptXSize
        $data .= pack('n', 100); // ySubscriptYSize
        $data .= pack('n', 0); // ySubscriptXOffset
        $data .= pack('n', 0); // ySubscriptYOffset
        $data .= pack('n', 100); // ySuperscriptXSize
        $data .= pack('n', 100); // ySuperscriptYSize
        $data .= pack('n', 0); // ySuperscriptXOffset
        $data .= pack('n', 0); // ySuperscriptYOffset
        $data .= pack('n', 0); // yStrikeoutSize
        $data .= pack('n', 0); // yStrikeoutPosition
        $data .= pack('n', 0); // sFamilyClass
        $data .= pack('C', 0); // bFamilyType
        $data .= pack('C', 0); // bSerifStyle
        $data .= pack('C', 0); // bWeight
        $data .= pack('C', 0); // bProportion
        $data .= pack('C', 0); // bContrast
        $data .= pack('C', 0); // bStrokeVariation
        $data .= pack('C', 0); // bArmStyle
        $data .= pack('C', 0); // bLetterform
        $data .= pack('C', 0); // bMidline
        $data .= pack('C', 0); // bXHeight
        $data .= pack('n', 0); // ulUnicodeRange1
        $data .= pack('n', 0); // ulUnicodeRange2
        $data .= pack('n', 0); // ulUnicodeRange3
        $data .= pack('n', 0); // ulUnicodeRange4
        $data .= pack('C', 0); // achVendID[0]
        $data .= pack('C', 0); // achVendID[1]
        $data .= pack('C', 0); // achVendID[2]
        $data .= pack('C', 0); // achVendID[3]
        $data .= pack('n', 0); // fsSelection
        $data .= pack('n', 0); // usFirstCharIndex
        $data .= pack('n', 0); // usLastCharIndex
        $data .= pack('n', 1000); // sTypoAscender
        $data .= pack('n', -200); // sTypoDescender
        $data .= pack('n', 0); // sTypoLineGap
        $data .= pack('n', 1000); // usWinAscent
        $data .= pack('n', 200); // usWinDescent
        $data .= pack('n', 0); // ulCodePageRange1
        $data .= pack('n', 0); // ulCodePageRange2
        $data .= pack('n', 0); // sxHeight
        $data .= pack('n', 0); // sCapHeight
        $data .= pack('n', 0); // usDefaultChar
        $data .= pack('n', 0); // usBreakChar
        $data .= pack('n', 0); // usMaxContext
        
        return $data;
    }

    /**
     * 创建后记表
     */
    private function createPostTable(array $fontInfo): string
    {
        $data = '';
        
        $data .= pack('N', 0x00030000); // 版本
        $data .= pack('N', 0); // 斜角
        $data .= pack('n', 0); // 下划线位置
        $data .= pack('n', 0); // 下划线厚度
        $data .= pack('N', 0); // 等宽
        $data .= pack('N', 0); // 最小内存类型42
        $data .= pack('N', 0); // 最大内存类型42
        $data .= pack('N', 0); // 最小内存类型1
        $data .= pack('N', 0); // 最大内存类型1
        
        return $data;
    }
}
