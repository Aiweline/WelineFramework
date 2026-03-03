<?php

namespace WelineTools\FontSubLetter\Service;

use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;
use WelineTools\FontSubLetter\Model\FontRecord;
use WelineTools\FontSubLetter\Model\CharMap;

class FontProcessor
{
    private const SUPPORTED_FORMATS = ['ttf', 'otf', 'woff', 'woff2'];
    private const MAX_FILE_SIZE = 104857600; // 100MB

    /**
     * 处理字体文件上传
     */
    public function processUpload(array $fileData, int $userId = 0): FontRecord
    {
        // 验证文件
        $this->validateFile($fileData);
        
        // 生成目标路径
        $uploadPath = $this->getUploadPath();
        $filename = $this->generateUniqueFilename($fileData['name']);
        $targetPath = $uploadPath . '/' . $filename;
        $fullTargetPath = BP . '/pub/' . $targetPath;

        // 确保目录存在
        $targetDir = dirname($fullTargetPath);
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        // 移动上传的文件到目标位置
        if (!move_uploaded_file($fileData['tmp_name'], $fullTargetPath)) {
            throw new Exception(__('文件上传失败，无法移动到目标位置'));
        }

        // 创建记录
        $record = ObjectManager::getInstance(FontRecord::class);
        $record->setData([
            'user_id' => $userId,
            'original_filename' => $fileData['name'],
            'original_path' => $targetPath, // 保存相对路径
            'font_format' => strtolower(pathinfo($fileData['name'], PATHINFO_EXTENSION)),
            'file_size' => $fileData['size'],
            'status' => FontRecord::STATUS_UPLOADED,
            'created_at' => time(),
            'updated_at' => time()
        ]);

        $record->save();

        return $record;
    }
    
    /**
     * 处理字体文件上传（从原始数据）
     */
    public function processUploadFromData(array $fileData, int $userId = 0): FontRecord
    {
        // 验证文件
        $this->validateFileData($fileData);
        
        // 生成目标路径
        $uploadPath = $this->getUploadPath();
        $filename = $this->generateUniqueFilename($fileData['name']);
        $targetPath = $uploadPath . '/' . $filename;
        $fullTargetPath = BP . '/pub/' . $targetPath;

        // 确保目录存在
        $targetDir = dirname($fullTargetPath);
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        // 直接写入文件内容
        if (file_put_contents($fullTargetPath, $fileData['data']) === false) {
            throw new Exception(__('文件写入失败'));
        }

        // 创建记录
        $record = ObjectManager::getInstance(FontRecord::class);
        $record->setData([
            'user_id' => $userId,
            'original_filename' => $fileData['name'],
            'original_path' => $targetPath, // 保存相对路径
            'font_format' => strtolower(pathinfo($fileData['name'], PATHINFO_EXTENSION)),
            'file_size' => $fileData['size'],
            'status' => FontRecord::STATUS_UPLOADED,
            'created_at' => time(),
            'updated_at' => time()
        ]);

        $record->save();

        return $record;
    }

    /**
     * 提取字体中的字符
     */
    public function extractCharacters(FontRecord $record): array
    {
        try {
            $record->setData('status', FontRecord::STATUS_PROCESSING);
            $record->save();

            $fontPath = $record->getData('original_path');
            $fullFontPath = BP . '/pub/' . $fontPath;
            if (!file_exists($fullFontPath)) {
                throw new \Exception('字体文件不存在: ' . $fullFontPath);
            }

            // 使用php-font-lib加载和解析字体 (基于README)
            $font = \FontLib\Font::load($fullFontPath);
            if (!$font) {
                throw new \Exception('无法加载字体文件');
            }
            $font->parse();

            // 获取Unicode字符映射
            $charMap = $font->getUnicodeCharMap();
            if (!$charMap) {
                throw new \Exception('无法获取字符映射');
            }

            $characters = [];
            foreach (array_keys($charMap) as $code) {
                if ($code > 0 && $code <= 0x10FFFF) { // 有效Unicode范围
                    $value = mb_chr($code, 'UTF-8');
                    if ($value && mb_check_encoding($value, 'UTF-8')) {
                        $characters[] = [
                            'code' => $code,
                            'value' => $value,
                            'unicode' => 'U+' . strtoupper(dechex($code))
                        ];
                    }
                }
            }

            // 排序字符
            usort($characters, function($a, $b) {
                return $a['code'] <=> $b['code'];
            });

            // 保存提取的字符
            $record->setData('extracted_chars', json_encode($characters));
            $record->setData('status', FontRecord::STATUS_COMPLETED);
            $record->save();

            return $characters;

        } catch (\Exception $e) {
            $record->setData('status', FontRecord::STATUS_FAILED);
            $record->setData('error_message', $e->getMessage());
            $record->save();
            throw $e;
        }
    }

    /**
     * 检查字符是否为有效的UTF-8字符
     */
    private function isValidUtf8Char(string $char): bool
    {
        // 检查是否为有效的UTF-8字符
        if (!mb_check_encoding($char, 'UTF-8')) {
            return false;
        }
        
        // 检查字符是否可以正确编码为JSON
        $testArray = ['char' => $char];
        $jsonTest = json_encode($testArray);
        if ($jsonTest === false) {
            return false;
        }
        
        return true;
    }

    /**
     * 获取完整的字符集（去重）
     */
    private function getCompleteCharacterSet(): string
    {
        $allChars = $this->getUserSpecifiedCharacters() . $this->getExtendedCharacterSet();
        
        // 去重
        $uniqueChars = '';
        $seen = [];
        
        for ($i = 0; $i < strlen($allChars); $i++) {
            $char = $allChars[$i];
            if (!isset($seen[$char])) {
                $uniqueChars .= $char;
                $seen[$char] = true;
            }
        }
        
        return $uniqueChars;
    }

    /**
     * 获取扩展字符集
     */
    private function getExtendedCharacterSet(): string
    {
        // 基本英文字母
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $chars .= 'abcdefghijklmnopqrstuvwxyz';
        
        // 数字
        $chars .= '0123456789';
        
        // 基本标点符号
        $chars .= '.,;:!?';
        
        // 引号和括号
        $chars .= '"\'()[]{}';
        
        // 数学符号
        $chars .= '+-=*/<>';
        
        // 特殊符号
        $chars .= '@#$%^&*_|\\/~`';
        
        // 空格
        $chars .= ' ';
        
        // 版权和注册商标符号
        $chars .= '©®';
        
        // 德语特殊字符
        $chars .= 'ÄÖÜäöüß';
        
        // 西班牙语特殊字符
        $chars .= 'ÁÉÍÓÚÜÑáéíóúüñ¡¿';
        
        // 更多标点符号
        $chars .= '…—–—';
        
        // 货币符号
        $chars .= '€$£¥';
        
        // 其他常用符号
        $chars .= '§¶†‡•';
        
        return $chars;
    }

    /**
     * 获取用户指定的字符集
     */
    private function getUserSpecifiedCharacters(): string
    {
        // 根据用户提供的字符列表
        $chars = '';
        
        // 英文字母
        $chars .= 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $chars .= 'abcdefghijklmnopqrstuvwxyz';
        
        // 德语特殊字符
        $chars .= 'ÄÖÜäöüß';
        
        // 西班牙语特殊字符
        $chars .= 'ÁÉÍÓÚÜÑáéíóúüñ¡¿';
        
        // 数字
        $chars .= '0123456789';
        
        // 标点符号
        $chars .= '.,;:!?"\'()[]{}<>@#$%^&*-_+=/|\\~`©®';
        
        return $chars;
    }

    /**
     * 获取基本字符集
     */
    private function getBasicCharacters(): array
    {
        $characters = [];
        $basicChars = $this->getCompleteCharacterSet();
        
        for ($i = 0; $i < strlen($basicChars); $i++) {
            $char = $basicChars[$i];
            
            // 检查字符是否为有效的UTF-8字符
            if (!$this->isValidUtf8Char($char)) {
                continue; // 跳过无效字符
            }
            
            $characters[] = [
                'code' => ord($char),
                'value' => $char,
                'unicode' => 'U+' . strtoupper(dechex(ord($char)))
            ];
        }

        return $characters;
    }

    /**
     * 生成子集字体
     */
    public function generateSubsetFont(FontRecord $record, array $selectedChars): string
    {
        try {
            $record->setData('status', FontRecord::STATUS_PROCESSING);
            $record->save();

            // 生成输出文件名
            $originalName = pathinfo($record->getData('original_filename'), PATHINFO_FILENAME);
            $outputName = $originalName . '_subset.' . $record->getData('font_format');
            $outputPath = $this->getUploadPath() . '/' . $outputName;

            // 使用新的子集生成方法
            $this->createSubsetFont($record->getData('original_path'), $outputPath, $selectedChars);
            
            // 获取文件大小信息
            $originalSize = filesize(BP . '/pub/' . $record->getData('original_path'));
            $subsetSize = filesize(BP . '/pub/' . $outputPath);
            $compressionRatio = $originalSize > 0 ? (($originalSize - $subsetSize) / $originalSize) * 100 : 0;

            // 更新记录
            $record->setData('processed_filename', $outputName);
            $record->setData('processed_path', $outputPath);
            $record->setData('custom_chars', json_encode($selectedChars));
            $record->setData('subset_info', json_encode([
                'success' => true,
                'original_size' => $originalSize,
                'subset_size' => $subsetSize,
                'compression_ratio' => $compressionRatio,
                'characters_count' => count($selectedChars)
            ]));
            $record->setData('size_info', json_encode([
                'original_size' => $originalSize,
                'subset_size' => $subsetSize,
                'compression_ratio' => $compressionRatio,
                'characters_count' => count($selectedChars)
            ]));
            $record->setData('status', FontRecord::STATUS_COMPLETED);
            $record->save();

            // 记录压缩信息
            $this->logCompressionInfo([
                'original_size' => $originalSize,
                'subset_size' => $subsetSize,
                'compression_ratio' => $compressionRatio,
                'characters_count' => count($selectedChars)
            ]);

            return $outputPath;

        } catch (\Exception $e) {
            $record->setData('status', FontRecord::STATUS_FAILED);
            $record->setData('error_message', $e->getMessage());
            $record->save();
            throw $e;
        }
    }

    /**
     * 创建简单的子集字体文件（临时方案）
     */
    private function createSimpleSubsetFont(string $inputPath, string $outputPath, array $selectedChars): void
    {
        // 确保输入文件存在
        $fullInputPath = BP . '/pub/' . $inputPath;
        if (!file_exists($fullInputPath)) {
            throw new Exception(__('原始字体文件不存在'));
        }

        // 确保输出目录存在
        $outputDir = dirname(BP . '/pub/' . $outputPath);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        // 按照README示例使用php-font-lib创建子集
        $font = \FontLib\Font::load($fullInputPath);
        if (!$font) {
            throw new Exception(__('无法加载字体文件'));
        }
        $font->parse();

        // 将字符代码转换为UTF-8字符串
        $subsetString = '';
        foreach ($selectedChars as $code) {
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
        $fullOutputPath = BP . '/pub/' . $outputPath;
        touch($fullOutputPath);
        $font->open($fullOutputPath, \FontLib\BinaryStream::modeReadWrite);
        $font->encode(['OS/2']);
        $font->close();

        // 记录信息
        w_log_info('字体子集生成完成: ' . $fullOutputPath);
    }

    /**
     * 创建子集字体文件
     */
    private function createSubsetFont(string $inputPath, string $outputPath, array $selectedChars): void
    {
        // 确保输入文件存在
        $fullInputPath = BP . '/pub/' . $inputPath;
        if (!file_exists($fullInputPath)) {
            throw new Exception(__('原始字体文件不存在'));
        }

        // 确保输出目录存在
        $outputDir = dirname(BP . '/pub/' . $outputPath);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        // 按照README示例使用php-font-lib创建子集
        $font = \FontLib\Font::load($fullInputPath);
        if (!$font) {
            throw new Exception(__('无法加载字体文件'));
        }
        $font->parse();

        // 将字符代码转换为UTF-8字符串
        $subsetString = '';
        foreach ($selectedChars as $code) {
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
        $fullOutputPath = BP . '/pub/' . $outputPath;
        touch($fullOutputPath);
        $font->open($fullOutputPath, \FontLib\BinaryStream::modeReadWrite);
        $font->encode(['OS/2']);
        $font->close();

        // 记录信息
        w_log_info('字体子集生成完成: ' . $fullOutputPath);
    }

    /**
     * 验证上传的文件
     */
    private function validateFile(array $fileData): void
    {
        if ($fileData['error'] !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => '文件大小超过了php.ini中upload_max_filesize的限制 (当前限制: ' . ini_get('upload_max_filesize') . ')',
                UPLOAD_ERR_FORM_SIZE => '文件大小超过了HTML表单中MAX_FILE_SIZE的限制',
                UPLOAD_ERR_PARTIAL => '文件只有部分被上传',
                UPLOAD_ERR_NO_FILE => '没有文件被上传',
                UPLOAD_ERR_NO_TMP_DIR => '找不到临时文件夹',
                UPLOAD_ERR_CANT_WRITE => '文件写入失败',
                UPLOAD_ERR_EXTENSION => '文件上传被扩展程序阻止'
            ];
            
            $errorMsg = $errorMessages[$fileData['error']] ?? '未知上传错误 (错误代码: ' . $fileData['error'] . ')';
            
            // 如果是文件大小限制问题，提供解决方案
            if ($fileData['error'] === UPLOAD_ERR_INI_SIZE) {
                $errorMsg .= '。请将php.ini中的upload_max_filesize设置为10M或更大。';
            }
            
            throw new Exception(__('文件上传失败: %1', $errorMsg));
        }

        // 检查文件大小是否超过我们的限制
        if ($fileData['size'] > self::MAX_FILE_SIZE) {
            throw new Exception(__('文件大小超过限制 (最大: %1 MB)', number_format(self::MAX_FILE_SIZE / 1024 / 1024, 2)));
        }

        $extension = strtolower(pathinfo($fileData['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, self::SUPPORTED_FORMATS)) {
            throw new Exception(__('不支持的字体格式: %1 (支持的格式: %2)', $extension, implode(', ', self::SUPPORTED_FORMATS)));
        }
    }
    
    /**
     * 验证文件数据（从原始数据）
     */
    private function validateFileData(array $fileData): void
    {
        // 记录详细的文件信息用于调试
        w_log_info('文件数据验证: ' . json_encode([
            'name' => $fileData['name'],
            'size' => $fileData['size'],
            'has_data' => isset($fileData['data'])
        ]));
        
        // 检查必要字段
        if (empty($fileData['name'])) {
            throw new Exception(__('文件名不能为空'));
        }
        
        if (!isset($fileData['data']) || empty($fileData['data'])) {
            throw new Exception(__('文件内容不能为空'));
        }
        
        // 检查文件大小是否超过我们的限制
        if ($fileData['size'] > self::MAX_FILE_SIZE) {
            throw new Exception(__('文件大小超过限制 (最大: %1 MB)', number_format(self::MAX_FILE_SIZE / 1024 / 1024, 2)));
        }

        $extension = strtolower(pathinfo($fileData['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, self::SUPPORTED_FORMATS)) {
            throw new Exception(__('不支持的字体格式: %1 (支持的格式: %2)', $extension, implode(', ', self::SUPPORTED_FORMATS)));
        }
    }

    /**
     * 生成唯一文件名
     */
    private function generateUniqueFilename(string $originalName): string
    {
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $basename = pathinfo($originalName, PATHINFO_FILENAME);
        
        // 生成唯一文件名：原文件名_时间戳.扩展名
        $timestamp = time();
        $random = mt_rand(1000, 9999);
        
        return $basename . '_' . $timestamp . '_' . $random . '.' . $extension;
    }

    /**
     * 记录压缩信息
     */
    private function logCompressionInfo(array $result): void
    {
        $charactersCount = $result['characters_count'] ?? 0;
        $logMessage = sprintf(
            '字体子集生成完成 - 原始大小: %s KB, 子集大小: %s KB, 压缩率: %.2f%%, 字符数: %d',
            number_format($result['original_size'] / 1024, 2),
            number_format($result['subset_size'] / 1024, 2),
            $result['compression_ratio'],
            $charactersCount
        );
        
        // 这里可以记录到日志文件或数据库
        w_log_info($logMessage);
    }

    /**
     * 获取上传路径
     */
    private function getUploadPath(): string
    {
        $path = 'media/fonts/' . date('Y/m/d');
        $fullPath = BP . '/pub/' . $path;
        
        if (!is_dir($fullPath)) {
            mkdir($fullPath, 0755, true);
        }
        
        return $path;
    }
}
