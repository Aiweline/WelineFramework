<?php

declare(strict_types=1);

namespace Weline\MediaManager\Service;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

/**
 * @DESC | 缩略图生成服务，支持按需生成+缓存
 */
class ThumbnailService
{
    private EventsManager $eventsManager;
    
    private int $thumbWidth = 150;
    private int $thumbHeight = 150;
    private int $thumbQuality = 85;
    
    private array $defaultFormats = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/bmp',
        'image/x-icon',
        'image/vnd.microsoft.icon',
        'image/tiff',
        'image/avif',
    ];
    
    private ?array $supportedFormats = null;
    
    public function __construct()
    {
        $this->eventsManager = ObjectManager::getInstance(EventsManager::class);
    }
    
    /**
     * 获取支持预览的格式列表（含事件扩展）
     */
    public function getSupportedFormats(): array
    {
        if ($this->supportedFormats !== null) {
            return $this->supportedFormats;
        }
        
        $formats = $this->defaultFormats;
        
        $eventData = ['data' => ['formats' => &$formats]];
        $this->eventsManager->dispatch(
            'Weline_MediaManager::integration::supported_preview_formats',
            $eventData
        );
        
        $this->supportedFormats = $formats;
        return $this->supportedFormats;
    }
    
    /**
     * 判断 MIME 类型是否支持预览
     */
    public function isPreviewable(string $mime): bool
    {
        return \in_array($mime, $this->getSupportedFormats(), true);
    }
    
    /**
     * 获取缩略图缓存目录
     */
    public function getThumbCacheDir(): string
    {
        return PUB . 'media' . DS . '.thumbs' . DS;
    }
    
    /**
     * 根据原始文件路径生成缩略图缓存路径
     */
    public function getThumbCachePath(string $originalPath): string
    {
        $relativePath = '';
        $mediaRoot = PUB . 'media' . DS;
        
        if (\str_starts_with($originalPath, $mediaRoot)) {
            $relativePath = \substr($originalPath, \strlen($mediaRoot));
        } else {
            $relativePath = \md5($originalPath);
        }
        
        $pathInfo = \pathinfo($relativePath);
        $dir = $pathInfo['dirname'] ?? '';
        $filename = $pathInfo['filename'] ?? '';
        
        $thumbPath = $this->getThumbCacheDir();
        if ($dir && $dir !== '.') {
            $thumbPath .= \str_replace(['/', '\\'], DS, $dir) . DS;
        }
        $thumbPath .= $filename . '_thumb.webp';
        
        return $thumbPath;
    }
    
    /**
     * 检查缩略图缓存是否有效
     */
    public function isCacheValid(string $originalPath, string $thumbPath): bool
    {
        if (!\is_file($thumbPath)) {
            return false;
        }
        
        $originalMtime = @\filemtime($originalPath);
        $thumbMtime = @\filemtime($thumbPath);
        
        return $thumbMtime !== false && $originalMtime !== false && $thumbMtime >= $originalMtime;
    }
    
    /**
     * 生成缩略图
     *
     * @return string|null 成功返回缩略图路径，失败返回 null
     */
    public function generate(string $originalPath, ?int $width = null, ?int $height = null): ?string
    {
        if (!\is_file($originalPath)) {
            return null;
        }
        
        $mime = $this->detectMime($originalPath);
        if (!$this->isPreviewable($mime)) {
            return null;
        }
        
        $thumbPath = $this->getThumbCachePath($originalPath);
        
        if ($this->isCacheValid($originalPath, $thumbPath)) {
            return $thumbPath;
        }
        
        $width = $width ?? $this->thumbWidth;
        $height = $height ?? $this->thumbHeight;
        
        $thumbDir = \dirname($thumbPath);
        if (!\is_dir($thumbDir)) {
            @\mkdir($thumbDir, 0755, true);
        }
        
        $srcImage = $this->createImageFromFile($originalPath, $mime);
        if ($srcImage === null) {
            return null;
        }
        
        $srcWidth = \imagesx($srcImage);
        $srcHeight = \imagesy($srcImage);
        
        $ratio = \min($width / $srcWidth, $height / $srcHeight);
        if ($ratio >= 1) {
            $newWidth = $srcWidth;
            $newHeight = $srcHeight;
        } else {
            $newWidth = (int) \round($srcWidth * $ratio);
            $newHeight = (int) \round($srcHeight * $ratio);
        }
        
        $dstImage = \imagecreatetruecolor($newWidth, $newHeight);
        if ($dstImage === false) {
            \imagedestroy($srcImage);
            return null;
        }
        
        \imagealphablending($dstImage, false);
        \imagesavealpha($dstImage, true);
        $transparent = \imagecolorallocatealpha($dstImage, 255, 255, 255, 127);
        \imagefill($dstImage, 0, 0, $transparent);
        
        \imagecopyresampled(
            $dstImage,
            $srcImage,
            0, 0, 0, 0,
            $newWidth, $newHeight,
            $srcWidth, $srcHeight
        );
        
        $result = false;
        if (\function_exists('imagewebp')) {
            $result = @\imagewebp($dstImage, $thumbPath, $this->thumbQuality);
        }
        
        if (!$result) {
            $thumbPath = \preg_replace('/\.webp$/i', '.jpg', $thumbPath);
            $result = @\imagejpeg($dstImage, $thumbPath, $this->thumbQuality);
        }
        
        \imagedestroy($srcImage);
        \imagedestroy($dstImage);
        
        return $result ? $thumbPath : null;
    }
    
    /**
     * 获取或生成缩略图
     *
     * @return string|null 缩略图路径，失败返回 null
     */
    public function getOrGenerate(string $originalPath): ?string
    {
        $thumbPath = $this->getThumbCachePath($originalPath);
        
        if ($this->isCacheValid($originalPath, $thumbPath)) {
            return $thumbPath;
        }
        
        return $this->generate($originalPath);
    }
    
    /**
     * 从文件创建 GD 图像资源
     */
    private function createImageFromFile(string $path, string $mime): ?\GdImage
    {
        $image = null;
        
        switch ($mime) {
            case 'image/jpeg':
                $image = @\imagecreatefromjpeg($path);
                break;
            case 'image/png':
                $image = @\imagecreatefrompng($path);
                break;
            case 'image/gif':
                $image = @\imagecreatefromgif($path);
                break;
            case 'image/webp':
                if (\function_exists('imagecreatefromwebp')) {
                    $image = @\imagecreatefromwebp($path);
                }
                break;
            case 'image/bmp':
                if (\function_exists('imagecreatefrombmp')) {
                    $image = @\imagecreatefrombmp($path);
                }
                break;
            case 'image/avif':
                if (\function_exists('imagecreatefromavif')) {
                    $image = @\imagecreatefromavif($path);
                }
                break;
            case 'image/x-icon':
            case 'image/vnd.microsoft.icon':
                $image = $this->createImageFromIco($path);
                break;
        }
        
        return $image instanceof \GdImage ? $image : null;
    }
    
    /**
     * 从 ICO 文件创建 GD 图像资源
     * ICO 文件可能包含多个图像，取最大的一个
     */
    private function createImageFromIco(string $path): ?\GdImage
    {
        $data = @\file_get_contents($path);
        if ($data === false || \strlen($data) < 6) {
            return null;
        }
        
        $header = \unpack('vReserved/vType/vCount', $data);
        if ($header === false || $header['Reserved'] !== 0 || $header['Type'] !== 1 || $header['Count'] < 1) {
            return null;
        }
        
        $count = $header['Count'];
        $entries = [];
        
        for ($i = 0; $i < $count; $i++) {
            $offset = 6 + ($i * 16);
            if (\strlen($data) < $offset + 16) {
                break;
            }
            
            $entry = \unpack(
                'CWidth/CHeight/CColorCount/CReserved/vPlanes/vBitCount/VBytesInRes/VImageOffset',
                \substr($data, $offset, 16)
            );
            
            if ($entry === false) {
                continue;
            }
            
            $width = $entry['Width'] === 0 ? 256 : $entry['Width'];
            $height = $entry['Height'] === 0 ? 256 : $entry['Height'];
            
            $entries[] = [
                'width'  => $width,
                'height' => $height,
                'bits'   => $entry['BitCount'],
                'size'   => $entry['BytesInRes'],
                'offset' => $entry['ImageOffset'],
            ];
        }
        
        if (empty($entries)) {
            return null;
        }
        
        \usort($entries, function ($a, $b) {
            $aPixels = $a['width'] * $a['height'];
            $bPixels = $b['width'] * $b['height'];
            if ($aPixels !== $bPixels) {
                return $bPixels - $aPixels;
            }
            return $b['bits'] - $a['bits'];
        });
        
        $best = $entries[0];
        $imageData = \substr($data, $best['offset'], $best['size']);
        
        if (\strlen($imageData) < 8) {
            return null;
        }
        
        if (\substr($imageData, 0, 8) === "\x89PNG\r\n\x1a\n") {
            $image = @\imagecreatefromstring($imageData);
            return $image instanceof \GdImage ? $image : null;
        }
        
        return $this->createImageFromBmpData($imageData, $best['width'], $best['height']);
    }
    
    /**
     * 从 ICO 内嵌的 BMP 数据创建 GD 图像
     */
    private function createImageFromBmpData(string $data, int $width, int $height): ?\GdImage
    {
        if (\strlen($data) < 40) {
            return null;
        }
        
        $header = \unpack('VSize/lWidth/lHeight/vPlanes/vBitCount/VCompression', $data);
        if ($header === false) {
            return null;
        }
        
        $bitCount = $header['BitCount'];
        $headerSize = $header['Size'];
        
        $realHeight = (int) \abs($header['Height']) / 2;
        
        $image = \imagecreatetruecolor($width, $height);
        if ($image === false) {
            return null;
        }
        
        \imagealphablending($image, false);
        \imagesavealpha($image, true);
        $transparent = \imagecolorallocatealpha($image, 0, 0, 0, 127);
        \imagefill($image, 0, 0, $transparent);
        
        $palette = [];
        $paletteSize = 0;
        
        if ($bitCount <= 8) {
            $paletteSize = 1 << $bitCount;
            $paletteOffset = $headerSize;
            for ($i = 0; $i < $paletteSize; $i++) {
                $offset = $paletteOffset + ($i * 4);
                if ($offset + 4 <= \strlen($data)) {
                    $b = \ord($data[$offset]);
                    $g = \ord($data[$offset + 1]);
                    $r = \ord($data[$offset + 2]);
                    $palette[$i] = ['r' => $r, 'g' => $g, 'b' => $b];
                }
            }
            $pixelOffset = $headerSize + ($paletteSize * 4);
        } else {
            $pixelOffset = $headerSize;
        }
        
        $rowSize = (int) \ceil(($width * $bitCount) / 8);
        $rowPadding = (4 - ($rowSize % 4)) % 4;
        $rowStride = $rowSize + $rowPadding;
        
        $maskOffset = $pixelOffset + ($rowStride * $height);
        $maskRowSize = (int) \ceil($width / 8);
        $maskRowPadding = (4 - ($maskRowSize % 4)) % 4;
        $maskRowStride = $maskRowSize + $maskRowPadding;
        
        for ($y = 0; $y < $height; $y++) {
            $srcY = $height - 1 - $y;
            $rowOffset = $pixelOffset + ($srcY * $rowStride);
            $maskRowOffset = $maskOffset + ($srcY * $maskRowStride);
            
            for ($x = 0; $x < $width; $x++) {
                $alpha = 0;
                
                if ($maskRowOffset + (int) ($x / 8) < \strlen($data)) {
                    $maskByte = \ord($data[$maskRowOffset + (int) ($x / 8)]);
                    $maskBit = ($maskByte >> (7 - ($x % 8))) & 1;
                    if ($maskBit === 1) {
                        $alpha = 127;
                    }
                }
                
                $r = $g = $b = 0;
                
                if ($bitCount === 32) {
                    $pixelPos = $rowOffset + ($x * 4);
                    if ($pixelPos + 4 <= \strlen($data)) {
                        $b = \ord($data[$pixelPos]);
                        $g = \ord($data[$pixelPos + 1]);
                        $r = \ord($data[$pixelPos + 2]);
                        $a = \ord($data[$pixelPos + 3]);
                        $alpha = (int) ((255 - $a) / 2);
                    }
                } elseif ($bitCount === 24) {
                    $pixelPos = $rowOffset + ($x * 3);
                    if ($pixelPos + 3 <= \strlen($data)) {
                        $b = \ord($data[$pixelPos]);
                        $g = \ord($data[$pixelPos + 1]);
                        $r = \ord($data[$pixelPos + 2]);
                    }
                } elseif ($bitCount === 8) {
                    $pixelPos = $rowOffset + $x;
                    if ($pixelPos < \strlen($data)) {
                        $idx = \ord($data[$pixelPos]);
                        if (isset($palette[$idx])) {
                            $r = $palette[$idx]['r'];
                            $g = $palette[$idx]['g'];
                            $b = $palette[$idx]['b'];
                        }
                    }
                } elseif ($bitCount === 4) {
                    $pixelPos = $rowOffset + (int) ($x / 2);
                    if ($pixelPos < \strlen($data)) {
                        $byte = \ord($data[$pixelPos]);
                        $idx = ($x % 2 === 0) ? ($byte >> 4) : ($byte & 0x0F);
                        if (isset($palette[$idx])) {
                            $r = $palette[$idx]['r'];
                            $g = $palette[$idx]['g'];
                            $b = $palette[$idx]['b'];
                        }
                    }
                } elseif ($bitCount === 1) {
                    $pixelPos = $rowOffset + (int) ($x / 8);
                    if ($pixelPos < \strlen($data)) {
                        $byte = \ord($data[$pixelPos]);
                        $idx = ($byte >> (7 - ($x % 8))) & 1;
                        if (isset($palette[$idx])) {
                            $r = $palette[$idx]['r'];
                            $g = $palette[$idx]['g'];
                            $b = $palette[$idx]['b'];
                        }
                    }
                }
                
                $color = \imagecolorallocatealpha($image, $r, $g, $b, $alpha);
                \imagesetpixel($image, $x, $y, $color);
            }
        }
        
        return $image;
    }
    
    /**
     * 检测文件 MIME 类型
     */
    private function detectMime(string $path): string
    {
        if (\function_exists('mime_content_type')) {
            $mime = @\mime_content_type($path);
            if ($mime !== false) {
                return $mime;
            }
        }
        
        $ext = \strtolower(\pathinfo($path, \PATHINFO_EXTENSION));
        $mimeMap = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'bmp'  => 'image/bmp',
            'ico'  => 'image/x-icon',
            'tiff' => 'image/tiff',
            'tif'  => 'image/tiff',
            'avif' => 'image/avif',
            'heic' => 'image/heic',
            'heif' => 'image/heif',
        ];
        
        return $mimeMap[$ext] ?? 'application/octet-stream';
    }
    
    /**
     * 清除指定文件的缩略图缓存
     */
    public function clearCache(string $originalPath): bool
    {
        $thumbPath = $this->getThumbCachePath($originalPath);
        
        if (\is_file($thumbPath)) {
            return @\unlink($thumbPath);
        }
        
        $jpgPath = \preg_replace('/\.webp$/i', '.jpg', $thumbPath);
        if (\is_file($jpgPath)) {
            return @\unlink($jpgPath);
        }
        
        return true;
    }
    
    /**
     * 清除所有缩略图缓存
     */
    public function clearAllCache(): int
    {
        $cacheDir = $this->getThumbCacheDir();
        if (!\is_dir($cacheDir)) {
            return 0;
        }
        
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($cacheDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                if (@\unlink($file->getPathname())) {
                    $count++;
                }
            }
        }
        
        return $count;
    }
}
