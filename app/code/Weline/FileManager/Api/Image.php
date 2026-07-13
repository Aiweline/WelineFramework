<?php

namespace Weline\FileManager\Api;

class Image
{
    /**
     * 将value值中的图片地址替换为图片预览地址数据
     * @param string $value
     * @param int $width
     * @param int $height
     * @return array
     */
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

    public static function processImagesValuePreviewData(string $value, int $width, int $height): array
    {
        // 确保 value 是字符串类型
        if (is_array($value)) {
            $value = '';
        } else {
            $value = (string)$value;
        }

        $value = self::normalizeMediaPath($value);

        $process = '?w=' . $width . '&h=' . $height;
        $value_items = [];
        if ($value) {
            if (str_contains($value, ',')) {
                $values = array_map(self::normalizeMediaPath(...), explode(',', $value));
                foreach ($values as $val) {
                    $pre_fix = '/media/image/';
                    $ext = strtolower(pathinfo(PUB.'media/' .$val, PATHINFO_EXTENSION));
                    if ($ext === 'svg') {
                        $url = '/pub/media/' . ltrim($val, '/');
                    } else {
                        $url = $val . $process;
                        if (!str_starts_with($url, $pre_fix)) {
                            $url = $pre_fix . $url;
                        }
                    }
                    $value_items[] = [
                        'path' => $val,
                        'name' => basename($val),
                        'url' => $url,
                        'pathInfo' => pathinfo(PUB . DS . 'media' . DS . $val)
                    ];
                }
            } else {
                $pre_fix = '/media/image/';
                $svg_fix = '/pub/media/';
                $ext = strtolower(pathinfo(PUB.'media/' .$value, PATHINFO_EXTENSION));
                if ($ext === 'svg') {
                    $url = $svg_fix . ltrim($value, '/');
                } else {
                    $url = $value . $process;
                    if (!str_starts_with($url, $pre_fix)) {
                        $url = $pre_fix . $url;
                    }
                }
                $value_items[] = [
                    'path' => $value,
                    'name' => basename($value),
                    'url' => $url,
                    'pathInfo' => pathinfo(PUB . DS . 'media' . DS . $value)
                ];
            }
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
        $ext = strtolower(pathinfo(PUB . 'media' . DIRECTORY_SEPARATOR . $relative, PATHINFO_EXTENSION));
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
