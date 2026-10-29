<?php

namespace Weline\MediaManager\Controller;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Response;
use Weline\Framework\Http\ResponseTerminateException;

/**
 * Media thumbnail endpoint: /media/image/{relative}?w=&h=&c=
 *
 * Generates/caches under pub/media/thumbnail/… and returns raw image bytes.
 * Must use framework Response (not PHP header()+string) so Response::normalize
 * does not wrap the body as text/html and prepend CSP meta.
 */
class Image extends FrontendController
{
    /**
     * 单个边最长像素：w/h 来自 GET，属系统边界输入；不设上限一次请求即可吃光 worker 内存。
     */
    private const MAX_DIMENSION = 2048;

    public function getIndex()
    {
        // 原始图片的路径
        $mediaPath = (\defined('MEDIA_DIR') ? (string)\constant('MEDIA_DIR') : (PUB . 'media' . DS));
        $mediaPath = \rtrim($mediaPath, '/\\') . DS;
        $sourcePath = $mediaPath . $this->request->getRule('file');
        if (!file_exists($sourcePath)) {
            $this->redirect(404);
        }
        // 缩略图的宽度
        $width = self::normalizeDimension($this->request->getGet('w'));
        // 缩略图的高度
        $height = self::normalizeDimension($this->request->getGet('h'));
        // 缩略图的裁剪方式
        $crop = $this->request->getGet('c') ?: 'o';
        $crop = in_array($crop, ['o', 'k'], true) ? $crop : 'o';
        // 缩略图的路径
        $pathInfo = pathinfo($sourcePath);
        $filePrePath = dirname(str_replace($mediaPath, $mediaPath . 'thumbnail' . DS, $sourcePath));
        if (!is_dir($filePrePath)) {
            mkdir($filePrePath, 0777, true);
        }
        // 缩略图的路径
        $thumbnailPath = $filePrePath . DS . $pathInfo['filename'] . "_w_{$width}_h_{$height}_c_{$crop}.{$pathInfo['extension']}";
        if (!file_exists($thumbnailPath)) {
            $thumbnailResult = self::generateThumbnail($sourcePath, $thumbnailPath, $width, $height, $crop);
            if (!$thumbnailResult) {
                // 运行时缺编解码器（如 bundled PHP 的 GD 未编 --with-jpeg）时不得抛 500：
                // 直接回原图字节，浏览器自行缩放，页面不出现破图。
                $thumbnailPath = $sourcePath;
            }
        }
        if (file_exists($thumbnailPath)) {
            $body = (string)file_get_contents($thumbnailPath);
            $mtime = (int)filemtime($thumbnailPath);
            $mime = self::mimeForExtension((string)($pathInfo['extension'] ?? ''));
            $response = Response::fromContent($body, 200, $mime);
            $response->setHeader('Content-Length', (string)strlen($body));
            $response->setHeader('Last-Modified', gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
            $response->setHeader('Expires', gmdate('D, d M Y H:i:s', $mtime + 86400) . ' GMT');
            $response->setHeader('Cache-Control', 'public, max-age=86400');
            $response->setHeader('X-Content-Type-Options', 'nosniff');
            throw new ResponseTerminateException($response);
        }
        $this->redirect(404);
        return '';
    }

    /**
     * w/h 只接受正整数，超过 MAX_DIMENSION 截断，缺省/非法一律回落 fallback。
     */
    private static function normalizeDimension(mixed $raw, int $fallback = 50): int
    {
        $value = (int)$raw;
        if ($value < 1) {
            return $fallback;
        }

        return min($value, self::MAX_DIMENSION);
    }

    private static function mimeForExtension(string $extension): string
    {
        return match (strtolower($extension)) {
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'bmp' => 'image/bmp',
            'svg' => 'image/svg+xml',
            default => 'image/jpeg',
        };
    }

    private static function generateThumbnail($sourcePath, $thumbnailPath, $width, $height, $crop = 'o'): bool
    {
        // 获取原始图片的信息
        $imageInfo = getimagesize($sourcePath);
        if ($imageInfo === false) {
            return false;
        }
        $imageType = $imageInfo[2];
        // 根据图片类型使用适当的 GD 函数打开原始图片
        // function_exists 必须逐类型判断：GD 编进去了不等于有该 codec（缺 libjpeg 时
        // imagecreatefromjpeg 是未定义函数，直接调用会致命错误）。
        switch ($imageType) {
            case IMAGETYPE_JPEG:
                if (!function_exists('imagecreatefromjpeg')) {
                    return false;
                }
                $sourceImage = imagecreatefromjpeg($sourcePath);
                break;
            case IMAGETYPE_PNG:
                if (!function_exists('imagecreatefrompng')) {
                    return false;
                }
                $sourceImage = imagecreatefrompng($sourcePath);
                break;
            case IMAGETYPE_GIF:
                if (!function_exists('imagecreatefromgif')) {
                    return false;
                }
                $sourceImage = imagecreatefromgif($sourcePath);
                break;
            case IMAGETYPE_WEBP:
                if (!function_exists('imagecreatefromwebp')) {
                    return false;
                }
                $sourceImage = imagecreatefromwebp($sourcePath);
                break;
            case IMAGETYPE_AVIF:
                if (!function_exists('imagecreatefromavif')) {
                    return false;
                }
                $sourceImage = imagecreatefromavif($sourcePath);
                break;
            case IMAGETYPE_BMP:
                if (!function_exists('imagecreatefrombmp')) {
                    return false;
                }
                $sourceImage = imagecreatefrombmp($sourcePath);
                break;
            case IMAGETYPE_WBMP:
                if (!function_exists('imagecreatefromwbmp')) {
                    return false;
                }
                $sourceImage = imagecreatefromwbmp($sourcePath);
                break;
                // 可以根据需要添加对其他图片格式的支持
            default:
                return false; // 不支持的图片格式
        }
        if ($sourceImage === false) {
            return false;
        }
        // 创建缩略图图像资源
        $thumbnailImage = imagecreatetruecolor($width, $height);
        // 将原始图片复制到缩略图图像资源中，并调整大小
        $color = imagecolorallocate($thumbnailImage, 255, 255, 255); //2.上色
        imagecolortransparent($thumbnailImage, $color); //3.设置透明色
        imagefill($thumbnailImage, 0, 0, $color);//4.填充透明色
        # 计算
        $sourceWidth = $imageInfo[0];
        $sourceHeight = $imageInfo[1];
        # 保持横纵比例
        $sourceRatio = $sourceWidth / $sourceHeight;
        $thumbnailRatio = $width / $height;
        $dst_x = 0;
        $dst_y = 0;
        if ($sourceRatio > $thumbnailRatio) {
            $newWidth = $width;
            $newHeight = intval($width / $sourceRatio);
            $dst_y = intval(($height - $newHeight) / 2);
        } else {
            $newWidth = intval($height * $sourceRatio);
            $newHeight = $height;
            $dst_x = intval(($width - $newWidth) / 2);
        }
        imagecopyresampled(
            $thumbnailImage,  // 目标图像资源
            $sourceImage,     // 原始图像资源
            $dst_x,                // 目标图像的起始 x 坐标
            $dst_y,                // 目标图像的起始 y 坐标
            0,                // 原始图像的起始 x 坐标
            0,                // 原始图像的起始 y 坐标
            $newWidth,           // 目标图像的宽度
            $newHeight,          // 目标图像的高度
            imagesx($sourceImage), // 原始图像的宽度
            imagesy($sourceImage)  // 原始图像的高度
        );
        // 将缩略图保存到指定路径
        // 编码器同样要逐类型探测：imagejpeg/imagewebp 在缺 codec 时并不存在，
        // Error 不是 Exception，原 try/catch 捕获不到。
        $encoder = match ($imageType) {
            IMAGETYPE_JPEG => 'imagejpeg',
            IMAGETYPE_PNG => 'imagepng',
            IMAGETYPE_GIF => 'imagegif',
            IMAGETYPE_WEBP => 'imagewebp',
            IMAGETYPE_AVIF => 'imageavif',
            IMAGETYPE_BMP => 'imagebmp',
            IMAGETYPE_WBMP => 'imagewbmp',
            default => '',
        };
        $thumbnailSuccess = $encoder !== ''
            && function_exists($encoder)
            && (bool)@$encoder($thumbnailImage, $thumbnailPath);
        // 释放图像资源
        imagedestroy($sourceImage);
        imagedestroy($thumbnailImage);
        return $thumbnailSuccess;
    }
}
