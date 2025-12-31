<?php

namespace WelineTools\FontSubLetter\Service;

/**
 * 字体子集生成器
 * 使用多种方法生成字体子集
 */
class FontSubsetGenerator
{
    private UltimateFontSubsetter $ultimateSubsetter;
    private ReliableFontSubsetter $reliableSubsetter;
    private ProfessionalFontSubsetter $professionalSubsetter;
    private SimpleFontSubsetter $simpleSubsetter;
    private UniversalFontSubsetter $universalSubsetter;

    public function __construct()
    {
        $this->ultimateSubsetter = new UltimateFontSubsetter();
        $this->reliableSubsetter = new ReliableFontSubsetter();
        $this->professionalSubsetter = new ProfessionalFontSubsetter();
        $this->simpleSubsetter = new SimpleFontSubsetter();
        $this->universalSubsetter = new UniversalFontSubsetter();
    }

    /**
     * 生成字体子集
     */
    public function generateSubset(string $inputPath, string $outputPath, array $selectedChars): array
    {
        try {
            // 首先尝试使用UltimateFontSubsetter（最强错误处理）
            $result = $this->ultimateSubsetter->createSubset($inputPath, $outputPath, $selectedChars);
            
            if ($result['success']) {
                return $result;
            }

            // 如果UltimateFontSubsetter失败，使用ReliableFontSubsetter
            $result = $this->reliableSubsetter->createSubset($inputPath, $outputPath, $selectedChars);
            
            if ($result['success']) {
                return $result;
            }

            // 如果ReliableFontSubsetter失败，使用ProfessionalFontSubsetter
            $result = $this->professionalSubsetter->createSubset($inputPath, $outputPath, $selectedChars);
            
            if ($result['success']) {
                return $result;
            }

            // 如果ProfessionalFontSubsetter失败，使用SimpleFontSubsetter
            $result = $this->simpleSubsetter->createSubset($inputPath, $outputPath, $selectedChars);
            
            if ($result['success']) {
                return $result;
            }

            // 如果SimpleFontSubsetter失败，使用UniversalFontSubsetter作为备选
            $result = $this->universalSubsetter->generateSubset($inputPath, $outputPath, $selectedChars);
            
            if ($result['success']) {
                return $result;
            }

            // 如果所有方法都失败，返回错误信息
            return [
                'success' => false,
                'error' => '所有字体子集生成方法都失败了'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => '字体子集生成异常: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 检查字体格式是否支持
     */
    public function isFormatSupported(string $format): bool
    {
        $supportedFormats = ['ttf', 'otf', 'woff', 'woff2'];
        return in_array(strtolower($format), $supportedFormats);
    }

    /**
     * 获取支持的格式列表
     */
    public function getSupportedFormats(): array
    {
        return ['ttf', 'otf', 'woff', 'woff2'];
    }

    /**
     * 获取字体信息
     */
    public function getFontInfo(string $fontPath): array
    {
        return $this->ultimateSubsetter->getFontInfo($fontPath);
    }

    /**
     * 验证字体文件
     */
    public function validateFont(string $fontPath): bool
    {
        return $this->ultimateSubsetter->validateFont($fontPath);
    }

    /**
     * 获取字体支持的字符
     */
    public function getSupportedCharacters(string $fontPath): array
    {
        return $this->ultimateSubsetter->getSupportedCharacters($fontPath);
    }
}

