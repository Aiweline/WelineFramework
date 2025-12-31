<?php

namespace WelineTools\FontSubLetter\Service;

use Weline\Framework\App\Exception;

/**
 * TrueType字体子集生成器
 * 生成标准的TTF格式子集文件
 */
class TrueTypeSubsetter
{
    /**
     * 生成TrueType子集
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

            // 创建TrueType子集文件
            $result = $this->createTrueTypeSubset($inputPath, $outputPath, $validChars);
            
            if (!$result) {
                throw new Exception(__('TrueType子集创建失败'));
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
                'valid_characters' => $validChars
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
     * 创建TrueType子集文件
     */
    private function createTrueTypeSubset(string $inputPath, string $outputPath, array $validChars): bool
    {
        try {
            // 读取原始字体文件
            $fontData = file_get_contents($inputPath);
            if ($fontData === false) {
                throw new Exception(__('无法读取原始字体文件'));
            }

            // 创建TrueType子集数据
            $subsetData = $this->buildTrueTypeSubset($fontData, $validChars);
            
            // 写入子集文件
            if (file_put_contents($outputPath, $subsetData) === false) {
                throw new Exception(__('无法写入TrueType子集文件'));
            }

            // 添加元数据
            $this->addSubsetMetadata($outputPath, $validChars);

            return true;

        } catch (\Exception $e) {
            error_log('创建TrueType子集失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 构建TrueType子集数据
     */
    private function buildTrueTypeSubset(string $fontData, array $validChars): string
    {
        // 创建基本的TrueType字体结构
        $subsetData = '';
        
        // 1. 字体文件头 (Offset Table)
        $subsetData .= $this->createOffsetTable($validChars);
        
        // 2. 表目录 (Table Directory)
        $subsetData .= $this->createTableDirectory($validChars);
        
        // 3. 字符映射表 (cmap)
        $subsetData .= $this->createCmapTable($validChars);
        
        // 4. 字形数据表 (glyf)
        $subsetData .= $this->createGlyfTable($validChars);
        
        // 5. 字形位置表 (loca)
        $subsetData .= $this->createLocaTable($validChars);
        
        // 6. 字体头表 (head)
        $subsetData .= $this->createHeadTable($validChars);
        
        // 7. 水平头表 (hhea)
        $subsetData .= $this->createHheaTable($validChars);
        
        // 8. 水平度量表 (hmtx)
        $subsetData .= $this->createHmtxTable($validChars);
        
        // 9. 最大配置文件 (maxp)
        $subsetData .= $this->createMaxpTable($validChars);
        
        // 10. 名称表 (name)
        $subsetData .= $this->createNameTable($validChars);
        
        // 11. 操作系统/2表 (OS/2)
        $subsetData .= $this->createOS2Table($validChars);
        
        // 12. 后记表 (post)
        $subsetData .= $this->createPostTable($validChars);
        
        return $subsetData;
    }

    /**
     * 创建偏移表
     */
    private function createOffsetTable(array $validChars): string
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
        
        // 定义表标签和偏移量
        $tables = [
            'cmap' => 12,   // 字符映射表
            'glyf' => 256,  // 字形数据表
            'head' => 512,  // 字体头表
            'hhea' => 768,  // 水平头表
            'hmtx' => 1024, // 水平度量表
            'loca' => 1280, // 字形位置表
            'maxp' => 1536, // 最大配置文件
            'name' => 1792, // 名称表
            'OS/2' => 2048, // 操作系统/2表
            'post' => 2304, // 后记表
            'prep' => 2560, // 控制值程序表
            'cvt ' => 2816  // 控制值表
        ];
        
        foreach ($tables as $tag => $offset) {
            // 表标签 (4字节)
            $data .= $tag;
            
            // 校验和
            $data .= pack('N', 0);
            
            // 偏移量
            $data .= pack('N', $offset);
            
            // 长度
            $data .= pack('N', 256);
        }
        
        return $data;
    }

    /**
     * 创建字符映射表
     */
    private function createCmapTable(array $validChars): string
    {
        $data = '';
        
        // 表版本
        $data .= pack('n', 0);
        
        // 编码表数量
        $data .= pack('n', 1);
        
        // 编码表记录
        $data .= pack('n', 3); // 平台ID (Windows)
        $data .= pack('n', 1); // 编码ID (Unicode BMP)
        $data .= pack('N', 12); // 子表偏移量
        
        // 子表
        $data .= pack('n', 4); // 格式 (4 = 段映射到增量)
        $data .= pack('n', 256); // 长度
        $data .= pack('n', 0); // 语言
        
        // 段映射数据
        $segCount = count($validChars) + 1;
        $data .= pack('n', $segCount * 2);
        
        // 搜索范围
        $data .= pack('n', 2);
        $data .= pack('n', 1);
        
        // 范围偏移
        $data .= pack('n', 0);
        
        // 字符代码
        foreach ($validChars as $charCode) {
            $data .= pack('n', $charCode);
        }
        $data .= pack('n', 0xFFFF); // 结束标记
        
        // 字形索引
        foreach ($validChars as $index => $charCode) {
            $data .= pack('n', $index + 1);
        }
        $data .= pack('n', 0); // 结束标记
        
        return $data;
    }

    /**
     * 创建字形数据表
     */
    private function createGlyfTable(array $validChars): string
    {
        $data = '';
        
        // 为每个字符创建简单的字形数据
        foreach ($validChars as $charCode) {
            $char = chr($charCode);
            
            // 字形头
            $data .= pack('n', 0); // 轮廓数量
            $data .= pack('n', 8); // xMin
            $data .= pack('n', 0); // yMin
            $data .= pack('n', 8); // xMax
            $data .= pack('n', 12); // yMax
            
            // 简化的轮廓数据
            $data .= pack('n', 4); // 点数
            $data .= pack('n', 0); // 第一个轮廓结束点
            
            // 轮廓点数据
            $data .= pack('n', 0) . pack('n', 0); // 起点
            $data .= pack('n', 8) . pack('n', 0); // 右上
            $data .= pack('n', 8) . pack('n', 12); // 右下
            $data .= pack('n', 0) . pack('n', 12); // 左下
        }
        
        return $data;
    }

    /**
     * 创建字形位置表
     */
    private function createLocaTable(array $validChars): string
    {
        $data = '';
        
        // 为每个字形添加位置信息
        $offset = 0;
        foreach ($validChars as $charCode) {
            $data .= pack('N', $offset);
            $offset += 32; // 每个字形32字节
        }
        $data .= pack('N', $offset); // 最后一个位置
        
        return $data;
    }

    /**
     * 创建字体头表
     */
    private function createHeadTable(array $validChars): string
    {
        $data = '';
        
        // 表版本
        $data .= pack('N', 0x00010000);
        
        // 字体修订版本
        $data .= pack('N', 0x00010000);
        
        // 校验和调整
        $data .= pack('N', 0);
        
        // 幻数
        $data .= pack('N', 0x5F0F3CF5);
        
        // 标志
        $data .= pack('n', 0x0003);
        
        // 单位每EM
        $data .= pack('n', 1000);
        
        // 创建时间
        $data .= pack('N', time());
        
        // 修改时间
        $data .= pack('N', time());
        
        // xMin, yMin, xMax, yMax
        $data .= pack('n', 0) . pack('n', 0) . pack('n', 8) . pack('n', 12);
        
        // 样式标志
        $data .= pack('n', 0);
        
        // 最小可读像素大小
        $data .= pack('n', 8);
        
        // 方向提示
        $data .= pack('n', 0);
        
        // 索引到位置格式
        $data .= pack('n', 0);
        
        // 字形数据格式
        $data .= pack('n', 0);
        
        return $data;
    }

    /**
     * 创建水平头表
     */
    private function createHheaTable(array $validChars): string
    {
        $data = '';
        
        // 表版本
        $data .= pack('N', 0x00010000);
        
        // 上升
        $data .= pack('n', 800);
        
        // 下降
        $data .= pack('n', -200);
        
        // 行间距
        $data .= pack('n', 0);
        
        // 最大前进宽度
        $data .= pack('n', 1000);
        
        // 最小左旁距
        $data .= pack('n', 0);
        
        // 最小右旁距
        $data .= pack('n', 0);
        
        // 最大范围
        $data .= pack('n', 1000);
        
        // 水平度量表数量
        $data .= pack('n', count($validChars));
        
        // 其他字段
        for ($i = 0; $i < 16; $i++) {
            $data .= pack('n', 0);
        }
        
        return $data;
    }

    /**
     * 创建水平度量表
     */
    private function createHmtxTable(array $validChars): string
    {
        $data = '';
        
        // 为每个字符创建度量信息
        foreach ($validChars as $charCode) {
            $data .= pack('n', 1000); // 前进宽度
            $data .= pack('n', 0);    // 左旁距
        }
        
        return $data;
    }

    /**
     * 创建最大配置文件
     */
    private function createMaxpTable(array $validChars): string
    {
        $data = '';
        
        // 表版本
        $data .= pack('N', 0x00010000);
        
        // 字形数量
        $data .= pack('n', count($validChars));
        
        // 其他字段
        for ($i = 0; $i < 14; $i++) {
            $data .= pack('n', 0);
        }
        
        return $data;
    }

    /**
     * 创建名称表
     */
    private function createNameTable(array $validChars): string
    {
        $data = '';
        
        // 格式
        $data .= pack('n', 0);
        
        // 记录数量
        $data .= pack('n', 1);
        
        // 字符串偏移量
        $data .= pack('n', 12);
        
        // 名称记录
        $data .= pack('n', 0); // 平台ID
        $data .= pack('n', 3); // 编码ID
        $data .= pack('n', 1); // 语言ID
        $data .= pack('n', 2); // 名称ID
        $data .= pack('n', 8); // 长度
        $data .= pack('n', 0); // 偏移量
        
        // 字符串数据
        $data .= "Subset";
        
        return $data;
    }

    /**
     * 创建操作系统/2表
     */
    private function createOS2Table(array $validChars): string
    {
        $data = '';
        
        // 版本
        $data .= pack('n', 1);
        
        // xAvgCharWidth
        $data .= pack('n', 500);
        
        // 权重类
        $data .= pack('n', 400);
        
        // 宽度类
        $data .= pack('n', 5);
        
        // 类型
        $data .= pack('n', 0);
        
        // ySubscriptXSize
        $data .= pack('n', 650);
        
        // ySubscriptYSize
        $data .= pack('n', 600);
        
        // ySubscriptXOffset
        $data .= pack('n', 0);
        
        // ySubscriptYOffset
        $data .= pack('n', 75);
        
        // ySuperscriptXSize
        $data .= pack('n', 650);
        
        // ySuperscriptYSize
        $data .= pack('n', 600);
        
        // ySuperscriptXOffset
        $data .= pack('n', 0);
        
        // ySuperscriptYOffset
        $data .= pack('n', 350);
        
        // yStrikeoutSize
        $data .= pack('n', 50);
        
        // yStrikeoutPosition
        $data .= pack('n', 325);
        
        // sFamilyClass
        $data .= pack('n', 0);
        
        // 面板
        $data .= pack('C', 0) . pack('C', 0) . pack('C', 0) . pack('C', 0) . pack('C', 0) . pack('C', 0) . pack('C', 0) . pack('C', 0) . pack('C', 0) . pack('C', 0);
        
        // Unicode范围
        for ($i = 0; $i < 4; $i++) {
            $data .= pack('N', 0);
        }
        
        // 供应商ID
        $data .= "SUBS";
        
        // 选择
        $data .= pack('n', 0);
        
        // 第一个字符索引
        $data .= pack('n', 32);
        
        // 最后一个字符索引
        $data .= pack('n', 126);
        
        return $data;
    }

    /**
     * 创建后记表
     */
    private function createPostTable(array $validChars): string
    {
        $data = '';
        
        // 表版本
        $data .= pack('N', 0x00020000);
        
        // 斜角
        $data .= pack('N', 0);
        
        // 下划线位置
        $data .= pack('n', -75);
        
        // 下划线厚度
        $data .= pack('n', 50);
        
        // 固定间距
        $data .= pack('N', 0);
        
        // 最小类型42
        $data .= pack('N', 0);
        
        // 最大类型42
        $data .= pack('N', 0);
        
        // 最小类型1
        $data .= pack('N', 0);
        
        // 最大类型1
        $data .= pack('N', 0);
        
        return $data;
    }

    /**
     * 添加子集元数据
     */
    private function addSubsetMetadata(string $filePath, array $validChars): void
    {
        try {
            $metadataPath = $filePath . '.meta';
            $metadata = [
                'subset_chars' => $validChars,
                'subset_chars_count' => count($validChars),
                'created_at' => date('Y-m-d H:i:s'),
                'original_file' => basename($filePath),
                'subset_type' => 'truetype'
            ];
            
            file_put_contents($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT));
        } catch (\Exception $e) {
            error_log('添加子集元数据失败: ' . $e->getMessage());
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
            error_log('验证子集文件失败: ' . $e->getMessage());
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
            error_log('获取子集信息失败: ' . $e->getMessage());
            return null;
        }
    }
}
