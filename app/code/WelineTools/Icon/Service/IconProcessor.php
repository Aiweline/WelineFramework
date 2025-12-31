<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace WelineTools\Icon\Service;

use Exception;

/**
 * 图标处理服务类
 * 提供图标格式转换、压缩等功能
 */
class IconProcessor
{
    /**
     * 支持的输入格式
     */
    public const SUPPORTED_INPUT_FORMATS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'bmp', 'ico'];

    /**
     * 支持的输出格式
     */
    public const SUPPORTED_OUTPUT_FORMATS = ['ico', 'png', 'jpg', 'jpeg', 'webp', 'gif'];

    /**
     * 默认ICO尺寸
     */
    public const DEFAULT_ICO_SIZES = [16, 32, 48, 64, 128, 256];

    /**
     * 转换图标格式
     *
     * @param string $sourcePath 源文件路径
     * @param string $targetPath 目标文件路径
     * @param string $targetFormat 目标格式
     * @param int|null $width 目标宽度（可选）
     * @param int|null $height 目标高度（可选）
     * @param int $quality 质量（1-100，仅对JPEG/WebP有效）
     * @return array 处理结果
     * @throws Exception
     */
    public function convert(
        string $sourcePath,
        string $targetPath,
        string $targetFormat,
        ?int $width = null,
        ?int $height = null,
        int $quality = 90
    ): array {
        if (!file_exists($sourcePath)) {
            throw new Exception('源文件不存在');
        }

        $sourceExt = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        $targetFormat = strtolower($targetFormat);

        // 验证格式
        if (!in_array($sourceExt, self::SUPPORTED_INPUT_FORMATS)) {
            throw new Exception("不支持的源格式: {$sourceExt}");
        }

        if (!in_array($targetFormat, self::SUPPORTED_OUTPUT_FORMATS)) {
            throw new Exception("不支持的目标格式: {$targetFormat}");
        }

        // 确保目标目录存在
        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0775, true);
        }

        // 检查源文件格式，某些格式需要Imagick支持
        $gdUnsupportedFormats = ['svg'];
        
        // ICO格式需要Imagick支持（除非是转换为其他格式）
        if ($sourceExt === 'ico' && !extension_loaded('imagick')) {
            // 如果目标格式也是ICO，必须要有Imagick
            if ($targetFormat === 'ico') {
                throw new Exception("ICO 格式转换需要 Imagick 扩展支持，GD库无法处理此格式");
            }
            // 如果目标格式不是ICO，可以尝试使用GD处理（但需要先转换）
            // 这种情况下，应该在上层调用时处理
        }
        
        if (in_array($sourceExt, $gdUnsupportedFormats) && !extension_loaded('imagick')) {
            throw new Exception("{$sourceExt} 格式需要 Imagick 扩展支持，GD库无法处理此格式");
        }

        // 使用Imagick处理（如果可用）
        if (extension_loaded('imagick')) {
            try {
                return $this->convertWithImagick($sourcePath, $targetPath, $targetFormat, $width, $height, $quality);
            } catch (\Exception $e) {
                // 如果Imagick失败，尝试使用GD（如果源格式支持）
                if (!in_array($sourceExt, $gdUnsupportedFormats) && extension_loaded('gd')) {
                    return $this->convertWithGD($sourcePath, $targetPath, $targetFormat, $width, $height, $quality);
                }
                throw $e;
            }
        }

        // 使用GD库处理
        if (extension_loaded('gd')) {
            return $this->convertWithGD($sourcePath, $targetPath, $targetFormat, $width, $height, $quality);
        }

        throw new Exception('需要安装 Imagick 或 GD 扩展');
    }

    /**
     * 使用Imagick转换
     */
    private function convertWithImagick(
        string $sourcePath,
        string $targetPath,
        string $targetFormat,
        ?int $width,
        ?int $height,
        int $quality
    ): array {
        try {
            $image = new \Imagick($sourcePath);

            // 如果是ICO格式，需要特殊处理
            if ($targetFormat === 'ico') {
                return $this->createIcoWithImagick($image, $targetPath);
            }

            // 调整尺寸
            if ($width || $height) {
                $image->resizeImage(
                    $width ?: $image->getImageWidth(),
                    $height ?: $image->getImageHeight(),
                    \Imagick::FILTER_LANCZOS,
                    1,
                    true
                );
            }

            // 设置格式和质量
            $image->setImageFormat($targetFormat);
            if (in_array($targetFormat, ['jpg', 'jpeg', 'webp'])) {
                $image->setImageCompressionQuality($quality);
            }

            // 保存
            $image->writeImage($targetPath);
            $image->clear();
            $image->destroy();

            return [
                'success' => true,
                'path' => $targetPath,
                'size' => filesize($targetPath),
                'format' => $targetFormat
            ];
        } catch (\Exception $e) {
            throw new Exception("Imagick转换失败: " . $e->getMessage());
        }
    }

    /**
     * 使用GD库转换
     */
    private function convertWithGD(
        string $sourcePath,
        string $targetPath,
        string $targetFormat,
        ?int $width,
        ?int $height,
        int $quality
    ): array {
        try {
            // 创建源图像资源（如果失败会抛出异常）
            $sourceImage = $this->createImageFromFile($sourcePath);

            $sourceWidth = imagesx($sourceImage);
            $sourceHeight = imagesy($sourceImage);

            // 计算目标尺寸
            $targetWidth = $width ?: $sourceWidth;
            $targetHeight = $height ?: $sourceHeight;

            // 创建目标图像
            $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);

            // 保持透明度
            if ($targetFormat === 'png' || $targetFormat === 'gif') {
                imagealphablending($targetImage, false);
                imagesavealpha($targetImage, true);
                $transparent = imagecolorallocatealpha($targetImage, 0, 0, 0, 127);
                imagefill($targetImage, 0, 0, $transparent);
            }

            // 调整尺寸
            imagecopyresampled(
                $targetImage,
                $sourceImage,
                0, 0, 0, 0,
                $targetWidth,
                $targetHeight,
                $sourceWidth,
                $sourceHeight
            );

            // 保存图像
            $this->saveImageWithGD($targetImage, $targetPath, $targetFormat, $quality);

            // 清理资源
            imagedestroy($sourceImage);
            imagedestroy($targetImage);

            return [
                'success' => true,
                'path' => $targetPath,
                'size' => filesize($targetPath),
                'format' => $targetFormat
            ];
        } catch (\Exception $e) {
            throw new Exception("GD转换失败: " . $e->getMessage());
        }
    }

    /**
     * 从文件创建图像资源
     * @throws \Exception
     */
    private function createImageFromFile(string $filePath)
    {
        if (!file_exists($filePath)) {
            throw new \Exception("文件不存在: {$filePath}");
        }
        
        if (!is_readable($filePath)) {
            throw new \Exception("文件无法读取: {$filePath}");
        }
        
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mime = mime_content_type($filePath);

        // ICO格式需要使用Imagick处理
        if ($ext === 'ico') {
            if (extension_loaded('imagick')) {
                try {
                    // 使用Imagick读取ICO文件，然后转换为GD可用的格式
                    $imagick = new \Imagick($filePath);
                    // 获取第一个图像（ICO可能包含多个尺寸）
                    $imagick->setIteratorIndex(0);
                    // 转换为PNG格式的临时文件
                    $tempPng = sys_get_temp_dir() . '/' . uniqid('ico_', true) . '.png';
                    $imagick->setImageFormat('png');
                    $imagick->writeImage($tempPng);
                    $imagick->clear();
                    $imagick->destroy();
                    // 从临时PNG文件创建GD资源
                    $image = @imagecreatefrompng($tempPng);
                    // 删除临时文件
                    @unlink($tempPng);
                    if ($image === false) {
                        throw new \Exception("ICO 文件转换失败，无法创建图像资源");
                    }
                    return $image;
                } catch (\ImagickException $e) {
                    throw new \Exception("ICO 文件读取失败: " . $e->getMessage());
                }
            } else {
                // 如果没有Imagick，ICO格式无法处理
                throw new \Exception("ICO 格式需要 Imagick 扩展支持，GD库无法处理 ICO 文件");
            }
        }

        switch ($ext) {
            case 'png':
                $image = @imagecreatefrompng($filePath);
                if ($image === false) {
                    $error = error_get_last();
                    throw new \Exception("PNG 文件读取失败: " . ($error['message'] ?? '未知错误'));
                }
                return $image;
            case 'jpg':
            case 'jpeg':
                $image = @imagecreatefromjpeg($filePath);
                if ($image === false) {
                    $error = error_get_last();
                    throw new \Exception("JPEG 文件读取失败: " . ($error['message'] ?? '未知错误'));
                }
                return $image;
            case 'gif':
                $image = @imagecreatefromgif($filePath);
                if ($image === false) {
                    $error = error_get_last();
                    throw new \Exception("GIF 文件读取失败: " . ($error['message'] ?? '未知错误'));
                }
                return $image;
            case 'webp':
                if (function_exists('imagecreatefromwebp')) {
                    $image = @imagecreatefromwebp($filePath);
                    if ($image === false) {
                        $error = error_get_last();
                        throw new \Exception("WebP 文件读取失败: " . ($error['message'] ?? '未知错误'));
                    }
                    return $image;
                }
                break;
            case 'bmp':
                if (function_exists('imagecreatefrombmp')) {
                    $image = @imagecreatefrombmp($filePath);
                    if ($image === false) {
                        $error = error_get_last();
                        throw new \Exception("BMP 文件读取失败: " . ($error['message'] ?? '未知错误'));
                    }
                    return $image;
                }
                break;
            case 'svg':
                // SVG格式GD库无法处理
                throw new \Exception("SVG 格式需要 Imagick 扩展支持，GD库无法处理 SVG 文件");
        }

        // 如果到这里，说明格式不支持
        throw new \Exception("不支持的图像格式: {$ext}。GD库支持的格式: PNG, JPG, JPEG, GIF, WebP, BMP");
    }

    /**
     * 使用GD保存图像
     */
    private function saveImageWithGD($image, string $path, string $format, int $quality): void
    {
        switch ($format) {
            case 'png':
                imagepng($image, $path, 9);
                break;
            case 'jpg':
            case 'jpeg':
                imagejpeg($image, $path, $quality);
                break;
            case 'gif':
                imagegif($image, $path);
                break;
            case 'webp':
                if (function_exists('imagewebp')) {
                    imagewebp($image, $path, $quality);
                } else {
                    throw new Exception('WebP格式需要GD库支持');
                }
                break;
            case 'ico':
                // ICO格式需要特殊处理，这里先转换为PNG
                imagepng($image, $path, 9);
                // 如果可能，使用Imagick转换为ICO
                if (extension_loaded('imagick')) {
                    $this->convertPngToIco($path, $path);
                }
                break;
        }
    }

    /**
     * 使用Imagick创建ICO文件（多尺寸）
     */
    private function createIcoWithImagick(\Imagick $sourceImage, string $targetPath): array
    {
        $ico = new \Imagick();
        $ico->setImageFormat('ico');

        // 创建多个尺寸的图标
        foreach (self::DEFAULT_ICO_SIZES as $size) {
            $resized = clone $sourceImage;
            $resized->resizeImage($size, $size, \Imagick::FILTER_LANCZOS, 1);
            $ico->addImage($resized);
            $resized->clear();
            $resized->destroy();
        }

        $ico->writeImages($targetPath, true);
        $ico->clear();
        $ico->destroy();

        return [
            'success' => true,
            'path' => $targetPath,
            'size' => filesize($targetPath),
            'format' => 'ico',
            'sizes' => self::DEFAULT_ICO_SIZES
        ];
    }

    /**
     * 将PNG转换为ICO
     */
    private function convertPngToIco(string $pngPath, string $icoPath): void
    {
        $image = new \Imagick($pngPath);
        $this->createIcoWithImagick($image, $icoPath);
        $image->clear();
        $image->destroy();
    }

    /**
     * 压缩图片
     *
     * @param string $sourcePath 源文件路径
     * @param string $targetPath 目标文件路径
     * @param int $quality 压缩质量（1-100）
     * @return array
     * @throws Exception
     */
    public function compress(string $sourcePath, string $targetPath, int $quality = 80): array
    {
        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        
        // 如果源文件是ICO格式且没有Imagick，先转换为PNG再压缩
        if ($ext === 'ico' && !extension_loaded('imagick')) {
            // 先将ICO转换为PNG
            $tempPng = sys_get_temp_dir() . '/' . uniqid('ico_compress_', true) . '.png';
            try {
                // 使用GD库无法直接读取ICO，需要提示用户
                throw new Exception('ICO 格式压缩需要 Imagick 扩展支持。请安装 Imagick 扩展，或先将 ICO 文件转换为 PNG 格式后再压缩。');
            } catch (\Exception $e) {
                throw $e;
            }
        }
        
        // 如果源文件是SVG格式且没有Imagick，无法压缩
        if ($ext === 'svg' && !extension_loaded('imagick')) {
            throw new Exception('SVG 格式压缩需要 Imagick 扩展支持。请安装 Imagick 扩展，或先将 SVG 文件转换为其他格式后再压缩。');
        }
        
        return $this->convert($sourcePath, $targetPath, $ext, null, null, $quality);
    }

    /**
     * 调整图片尺寸
     *
     * @param string $sourcePath 源文件路径
     * @param string $targetPath 目标文件路径
     * @param int $width 宽度
     * @param int $height 高度
     * @return array
     * @throws Exception
     */
    public function resize(string $sourcePath, string $targetPath, int $width, int $height): array
    {
        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        return $this->convert($sourcePath, $targetPath, $ext, $width, $height);
    }
}

