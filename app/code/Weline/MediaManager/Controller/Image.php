<?php

namespace Weline\MediaManager\Controller;

use Weline\Framework\App\Controller\FrontendController;

class Image extends FrontendController
{
    public function getIndex()
    {
        // 原始图片的路径
        $mediaPath = PUB . 'media' . DS;
        $sourcePath = $mediaPath . $this->request->getRule('file');
        if (!file_exists($sourcePath)) {
            $this->redirect(404);
        }
        // 缩略图的宽度
        $width = $this->request->getGet('w') ?: 50;
        // 缩略图的高度
        $height = $this->request->getGet('h') ?: 50;  // 缩略图的高度
        // 缩略图的裁剪方式
        $crop = $this->request->getGet('c') ?: 'o';
        $crop = in_array($crop, ['o', 'k']) ? $crop : 'o';
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
                $this->redirect(404);
            }
        }
        if (file_exists($thumbnailPath)) {
            $length = filesize($thumbnailPath);
            $filemtime = date('D, d M Y H:i:s', filemtime($thumbnailPath));
            $expires = date('D, d M Y H:i:s', filemtime($thumbnailPath));
            header('Content-Type:image/jpeg');
            header("Content-Length:{$length}");
            header("Last-Modified:{$filemtime}");
            header("Expires:{$expires}");
            return file_get_contents($thumbnailPath);
        }
        $this->redirect(404);
        return '';
    }

    private static function generateThumbnail($sourcePath, $thumbnailPath, $width, $height, $crop = 'o'): bool
    {
        // 获取原始图片的信息
        $imageInfo = getimagesize($sourcePath);
        $imageType = $imageInfo[2];
        // 根据图片类型使用适当的GD函数打开原始图片
        switch ($imageType) {
            case IMAGETYPE_JPEG:
                $sourceImage = imagecreatefromjpeg($sourcePath);
                break;
            case IMAGETYPE_PNG:
                $sourceImage = imagecreatefrompng($sourcePath);
                break;
            case IMAGETYPE_GIF:
                $sourceImage = imagecreatefromgif($sourcePath);
                break;
            case IMAGETYPE_WEBP:
                $sourceImage = imagecreatefromwebp($sourcePath);
                break;
            case IMAGETYPE_BMP:
                $sourceImage = imagecreatefrombmp($sourcePath);
                break;
            case IMAGETYPE_WBMP:
                $sourceImage = imagecreatefromwbmp($sourcePath);
                break;
                // 可以根据需要添加对其他图片格式的支持
            default:
                return false; // 不支持的图片格式
        }    // 创建缩略图图像资源
        $thumbnailImage = imagecreatetruecolor($width, $height);    // 将原始图片复制到缩略图图像资源中，并调整大小
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
        $thumbnailSuccess = true;
        try {
            switch ($imageType) {
                case IMAGETYPE_JPEG:
                    imagejpeg($thumbnailImage, $thumbnailPath);
                    break;
                case IMAGETYPE_PNG:
                    imagepng($thumbnailImage, $thumbnailPath);
                    break;
                case IMAGETYPE_GIF:
                    imagegif($thumbnailImage, $thumbnailPath);
                    break;
                case IMAGETYPE_WEBP:
                    imagewebp($thumbnailImage, $thumbnailPath);
                    break;
                case IMAGETYPE_BMP:
                    imagebmp($thumbnailImage, $thumbnailPath);
                    break;
                case IMAGETYPE_WBMP:
                    imagewbmp($thumbnailImage, $thumbnailPath);
                    break;
            }
        } catch (\Exception $e) {
            $thumbnailSuccess = false;
        }
        // 释放图像资源
        imagedestroy($sourceImage);
        imagedestroy($thumbnailImage);
        return $thumbnailSuccess;
    }// 使用示例
}
