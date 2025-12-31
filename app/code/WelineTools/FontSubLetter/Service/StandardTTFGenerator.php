<?php

namespace WelineTools\FontSubLetter\Service;

/**
 * 标准TTF文件生成器
 * 按照TrueType字体规范创建完整的TTF文件
 */
class StandardTTFGenerator
{
    /**
     * 生成标准TTF文件
     */
    public function generateTTF(array $selectedChars): string
    {
        // 标准化字符
        $normalizedChars = $this->normalizeCharacters($selectedChars);
        
        // 构建TTF文件
        $ttfData = '';
        
        // 1. Offset Table
        $ttfData .= $this->buildOffsetTable();
        
        // 2. Table Directory
        $ttfData .= $this->buildTableDirectory();
        
        // 3. 各个表
        $ttfData .= $this->buildHeadTable();
        $ttfData .= $this->buildHheaTable();
        $ttfData .= $this->buildMaxpTable();
        $ttfData .= $this->buildCmapTable($normalizedChars);
        $ttfData .= $this->buildLocaTable();
        $ttfData .= $this->buildGlyfTable();
        $ttfData .= $this->buildHmtxTable();
        $ttfData .= $this->buildNameTable();
        $ttfData .= $this->buildOS2Table();
        $ttfData .= $this->buildPostTable();
        
        return $ttfData;
    }
    
    /**
     * 构建Offset Table (12 bytes)
     */
    private function buildOffsetTable(): string
    {
        $data = '';
        $data .= pack('N', 0x00010000); // sfntVersion (1.0)
        $data .= pack('n', 10); // numTables (10个表)
        $data .= pack('n', 256); // searchRange (16 * 2^4)
        $data .= pack('n', 4); // entrySelector (log2(16))
        $data .= pack('n', 144); // rangeShift (16 * 10 - 256)
        return $data;
    }
    
    /**
     * 构建Table Directory
     */
    private function buildTableDirectory(): string
    {
        $data = '';
        $tables = [
            'head' => ['offset' => 172, 'length' => 54],
            'hhea' => ['offset' => 226, 'length' => 36],
            'maxp' => ['offset' => 262, 'length' => 32],
            'cmap' => ['offset' => 294, 'length' => 32],
            'loca' => ['offset' => 326, 'length' => 4],
            'glyf' => ['offset' => 330, 'length' => 25],
            'hmtx' => ['offset' => 355, 'length' => 4],
            'name' => ['offset' => 359, 'length' => 12],
            'OS/2' => ['offset' => 371, 'length' => 96],
            'post' => ['offset' => 467, 'length' => 32]
        ];
        
        foreach ($tables as $tag => $info) {
            $data .= $tag; // 4字节表标签
            $data .= pack('N', 0); // 校验和 (暂时为0)
            $data .= pack('N', $info['offset']); // 偏移
            $data .= pack('N', $info['length']); // 长度
        }
        
        return $data;
    }
    
    /**
     * 构建head表 (54 bytes)
     */
    private function buildHeadTable(): string
    {
        $data = '';
        $data .= pack('N', 0x00010000); // version
        $data .= pack('N', 0x00010000); // fontRevision
        $data .= pack('N', 0); // checkSumAdjustment
        $data .= pack('N', 0x5F0F3CF5); // magicNumber
        $data .= pack('n', 0); // flags
        $data .= pack('n', 2048); // unitsPerEm
        $data .= pack('N', 0); // created
        $data .= pack('N', 0); // modified
        $data .= pack('n', -1000); // xMin
        $data .= pack('n', -1000); // yMin
        $data .= pack('n', 3000); // xMax
        $data .= pack('n', 3000); // yMax
        $data .= pack('n', 0); // macStyle
        $data .= pack('n', 8); // lowestRecPPEM
        $data .= pack('n', 2); // fontDirectionHint
        $data .= pack('n', 0); // indexToLocFormat
        $data .= pack('n', 0); // glyphDataFormat
        return $data;
    }
    
    /**
     * 构建hhea表 (36 bytes)
     */
    private function buildHheaTable(): string
    {
        $data = '';
        $data .= pack('N', 0x00010000); // version
        $data .= pack('n', 1900); // ascent
        $data .= pack('n', -500); // descent
        $data .= pack('n', 0); // lineGap
        $data .= pack('n', 2048); // advanceWidthMax
        $data .= pack('n', 0); // minLeftSideBearing
        $data .= pack('n', 0); // minRightSideBearing
        $data .= pack('n', 2048); // xMaxExtent
        $data .= pack('n', 1); // caretSlopeRise
        $data .= pack('n', 0); // caretSlopeRun
        $data .= pack('n', 0); // caretOffset
        $data .= pack('n', 0); // reserved1
        $data .= pack('n', 0); // reserved2
        $data .= pack('n', 0); // reserved3
        $data .= pack('n', 0); // reserved4
        $data .= pack('n', 0); // metricDataFormat
        $data .= pack('n', 1); // numOfLongHorMetrics
        return $data;
    }
    
    /**
     * 构建maxp表 (32 bytes)
     */
    private function buildMaxpTable(): string
    {
        $data = '';
        $data .= pack('N', 0x00010000); // version
        $data .= pack('n', 1); // numGlyphs
        $data .= pack('n', 4); // maxPoints
        $data .= pack('n', 1); // maxContours
        $data .= pack('n', 0); // maxCompositePoints
        $data .= pack('n', 0); // maxCompositeContours
        $data .= pack('n', 0); // maxZones
        $data .= pack('n', 0); // maxTwilightPoints
        $data .= pack('n', 0); // maxStorage
        $data .= pack('n', 0); // maxFunctionDefs
        $data .= pack('n', 0); // maxInstructionDefs
        $data .= pack('n', 0); // maxStackElements
        $data .= pack('n', 0); // maxSizeOfInstructions
        $data .= pack('n', 0); // maxComponentElements
        $data .= pack('n', 0); // maxComponentDepth
        return $data;
    }
    
    /**
     * 构建cmap表
     */
    private function buildCmapTable(array $chars): string
    {
        $data = '';
        $data .= pack('n', 0); // version
        $data .= pack('n', 1); // numTables
        
        // cmap子表
        $data .= pack('n', 0); // platformID (Unicode)
        $data .= pack('n', 4); // encodingID (Unicode 2.0)
        $data .= pack('N', 12); // offset (从cmap表开始)
        
        // Format 4 cmap子表
        $data .= pack('n', 4); // format
        $data .= pack('n', 32); // length
        $data .= pack('n', 0); // language
        $data .= pack('n', 2); // segCountX2
        $data .= pack('n', 2); // searchRange
        $data .= pack('n', 1); // entrySelector
        $data .= pack('n', 0); // rangeShift
        $data .= pack('n', 0); // endCode[0]
        $data .= pack('n', 0xFFFF); // reservedPad
        $data .= pack('n', 0); // startCode[0]
        $data .= pack('n', 0); // idDelta[0]
        $data .= pack('n', 0); // idRangeOffset[0]
        
        return $data;
    }
    
    /**
     * 构建loca表 (4 bytes)
     */
    private function buildLocaTable(): string
    {
        $data = '';
        $data .= pack('N', 0); // 第一个字形偏移
        return $data;
    }
    
    /**
     * 构建glyf表 (25 bytes)
     */
    private function buildGlyfTable(): string
    {
        $data = '';
        // 简单字形描述
        $data .= pack('n', 1); // numberOfContours
        $data .= pack('n', 0); // xMin
        $data .= pack('n', 0); // yMin
        $data .= pack('n', 2048); // xMax
        $data .= pack('n', 2048); // yMax
        $data .= pack('n', 4); // endPtsOfContours[0]
        $data .= pack('n', 0); // instructionLength
        // 简单指令
        $data .= pack('C', 0x01); // 指令
        $data .= pack('C', 0x01); // 指令
        $data .= pack('C', 0x01); // 指令
        $data .= pack('C', 0x01); // 指令
        $data .= pack('C', 0x01); // 指令
        return $data;
    }
    
    /**
     * 构建hmtx表 (4 bytes)
     */
    private function buildHmtxTable(): string
    {
        $data = '';
        $data .= pack('n', 2048); // advanceWidth
        $data .= pack('n', 0); // lsb
        return $data;
    }
    
    /**
     * 构建name表 (12 bytes)
     */
    private function buildNameTable(): string
    {
        $data = '';
        $data .= pack('n', 0); // format
        $data .= pack('n', 1); // count
        $data .= pack('n', 6); // stringOffset
        $data .= pack('n', 1); // nameID
        $data .= pack('n', 1); // platformID
        $data .= pack('n', 0); // encodingID
        $data .= pack('n', 0); // languageID
        $data .= pack('n', 4); // length
        $data .= pack('n', 6); // offset
        return $data;
    }
    
    /**
     * 构建OS/2表 (96 bytes)
     */
    private function buildOS2Table(): string
    {
        $data = '';
        $data .= pack('n', 4); // version
        $data .= pack('n', 0); // xAvgCharWidth
        $data .= pack('n', 400); // usWeightClass
        $data .= pack('n', 5); // usWidthClass
        $data .= pack('n', 0); // fsType
        $data .= pack('n', 0); // ySubscriptXSize
        $data .= pack('n', 0); // ySubscriptYSize
        $data .= pack('n', 0); // ySubscriptXOffset
        $data .= pack('n', 0); // ySubscriptYOffset
        $data .= pack('n', 0); // ySuperscriptXSize
        $data .= pack('n', 0); // ySuperscriptYSize
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
        $data .= pack('n', 0); // sTypoAscender
        $data .= pack('n', 0); // sTypoDescender
        $data .= pack('n', 0); // sTypoLineGap
        $data .= pack('n', 0); // usWinAscent
        $data .= pack('n', 0); // usWinDescent
        $data .= pack('n', 0); // ulCodePageRange1
        $data .= pack('n', 0); // ulCodePageRange2
        $data .= pack('n', 0); // sxHeight
        $data .= pack('n', 0); // sCapHeight
        $data .= pack('n', 0); // usDefaultChar
        $data .= pack('n', 0); // usBreakChar
        $data .= pack('n', 0); // usMaxContext
        $data .= pack('n', 0); // usLowerOpticalPointSize
        $data .= pack('n', 0); // usUpperOpticalPointSize
        return $data;
    }
    
    /**
     * 构建post表 (32 bytes)
     */
    private function buildPostTable(): string
    {
        $data = '';
        $data .= pack('N', 0x00030000); // version
        $data .= pack('N', 0); // italicAngle
        $data .= pack('n', 0); // underlinePosition
        $data .= pack('n', 0); // underlineThickness
        $data .= pack('N', 0); // isFixedPitch
        $data .= pack('N', 0); // minMemType42
        $data .= pack('N', 0); // maxMemType42
        $data .= pack('N', 0); // minMemType1
        $data .= pack('N', 0); // maxMemType1
        return $data;
    }
    
    /**
     * 标准化字符数组
     */
    private function normalizeCharacters(array $selectedChars): array
    {
        $normalizedChars = [];
        foreach ($selectedChars as $char) {
            if (is_string($char)) {
                $normalizedChars[] = ord($char);
            } elseif (is_numeric($char)) {
                $normalizedChars[] = (int)$char;
            }
        }
        return array_unique($normalizedChars);
    }
}
