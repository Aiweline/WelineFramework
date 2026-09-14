<?php

namespace Weline\FileManager\Api;

class Image
{
    /** @var list<string> */
    private const IMAGE_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico', 'avif', 'heic', 'heif', 'svg',
    ];

    /** @var list<string> */
    private const AUDIO_EXTENSIONS = [
        'mp3', 'wav', 'ogg', 'oga', 'm4a', 'aac', 'flac', 'opus', 'wma', 'weba',
    ];

    /**
     * 将存储的路径转为 media 相对路径（供 /media/image/ 使用）
     */
    protected static function normalizeMediaPath(string $value): string
    {
        $value = trim($value);
        foreach (['/pub/media/', 'pub/media/', '/media/'] as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return ltrim(substr($value, strlen($prefix)), '/');
            }
        }
        return ltrim($value, '/');
    }

    public static function extensionOf(string $relativePath): string
    {
        return strtolower((string)pathinfo($relativePath, PATHINFO_EXTENSION));
    }

    public static function isImageExtension(string $ext): bool
    {
        return in_array(strtolower($ext), self::IMAGE_EXTENSIONS, true);
    }

    public static function isAudioExtension(string $ext): bool
    {
        return in_array(strtolower($ext), self::AUDIO_EXTENSIONS, true);
    }

    /**
     * @return 'image'|'audio'|'file'
     */
    public static function previewKindForPath(string $relativePath): string
    {
        $ext = self::extensionOf($relativePath);
        if (self::isAudioExtension($ext)) {
            return 'audio';
        }
        if (self::isImageExtension($ext)) {
            return 'image';
        }

        return 'file';
    }

    /**
     * @return array{path:string,name:string,url:string,kind:string,is_image:bool,pathInfo:array<string,string>}
     */
    private static function buildPreviewItem(string $relativePath, int $width, int $height): array
    {
        $relativePath = self::normalizeMediaPath($relativePath);
        $kind = self::previewKindForPath($relativePath);
        $ext = self::extensionOf($relativePath);
        if ($kind === 'image') {
            if ($ext === 'svg') {
                $url = '/pub/media/' . ltrim($relativePath, '/');
            } else {
                $url = '/media/image/' . ltrim($relativePath, '/') . '?w=' . $width . '&h=' . $height;
            }
        } else {
            // Non-images must not go through the image resize endpoint (broken <img>).
            $url = '/media/' . ltrim($relativePath, '/');
        }

        return [
            'path' => $relativePath,
            'name' => basename($relativePath),
            'url' => $url,
            'kind' => $kind,
            'is_image' => $kind === 'image',
            'pathInfo' => pathinfo(PUB . DS . 'media' . DS . $relativePath),
        ];
    }

    public static function processImagesValuePreviewData(string $value, int $width, int $height): array
    {
        // 确保 value 是字符串类型
        if (is_array($value)) {
            $value = '';
        } else {
            $value = (string)$value;
        }

        $value = self::normalizeMediaPath($value);
        $value_items = [];
        if ($value === '') {
            return $value_items;
        }
        $parts = str_contains($value, ',')
            ? array_map(self::normalizeMediaPath(...), explode(',', $value))
            : [$value];
        foreach ($parts as $part) {
            $part = trim((string)$part);
            if ($part === '') {
                continue;
            }
            $value_items[] = self::buildPreviewItem($part, $width, $height);
        }

        return $value_items;
    }

    /**
     * 将存储的媒体路径转为可访问的 URL（供 img src 使用）
     * 例如：backend/logo/xxx.jpg → /media/image/backend/logo/xxx.jpg?w=70&h=70
     *
     * @param string $path 存储的路径（如 backend/logo/xxx.jpg）
     * @param int|null $w 宽度（可选）
     * @param int|null $h 高度（可选）
     * @return string 以 / 开头的绝对路径 URL
     */
    public static function pathToMediaUrl(string $path, ?int $w = null, ?int $h = null): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        // 已是完整 URL 或 @static 等形式，直接返回
        if (str_starts_with($path, 'http') || str_starts_with($path, '//') || str_starts_with($path, '@')) {
            return $path;
        }
        $relative = self::normalizeMediaPath($path);
        if ($relative === '') {
            return $path;
        }
        $kind = self::previewKindForPath($relative);
        if ($kind !== 'image') {
            return '/media/' . ltrim($relative, '/');
        }
        $ext = self::extensionOf($relative);
        if ($ext === 'svg') {
            return '/pub/media/' . ltrim($relative, '/');
        }
        $url = '/media/image/' . ltrim($relative, '/');
        if ($w !== null && $h !== null) {
            $url .= '?w=' . $w . '&h=' . $h;
        }
        return $url;
    }

    public static function getSize($filesize): string
    {
        if ($filesize >= 1073741824) {
            $filesize = round($filesize / 1073741824 * 100) / 100 . ' GB';
        } elseif ($filesize >= 1048576) {
            $filesize = round($filesize / 1048576 * 100) / 100 . ' MB';
        } elseif ($filesize >= 1024) {
            $filesize = round($filesize / 1024 * 100) / 100 . ' KB';
        } else {
            $filesize = $filesize . ' bit';
        }
        return $filesize;
    }
}
