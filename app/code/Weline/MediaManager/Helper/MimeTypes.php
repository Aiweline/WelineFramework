<?php

declare(strict_types=1);

namespace Weline\MediaManager\Helper;

class MimeTypes
{
    /** Active browser content cannot be safely served from a same-origin public media disk. */
    private static array $activeBrowserExtensions = ['svg', 'html', 'css', 'js', 'xml'];

    private static array $safeUploadExtensions = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'ico', 'bmp', 'tiff', 'tif', 'avif',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'json',
        'zip', 'rar', 'gz', 'tar', '7z', 'mp3', 'wav', 'ogg', 'mp4', 'webm', 'avi',
        'mov', 'mkv', 'flv', 'wmv', 'ttf', 'otf', 'woff', 'woff2', 'eot',
    ];

    private static array $extensionMap = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'svg'  => ['image/svg+xml'],
        'ico'  => ['image/x-icon', 'image/vnd.microsoft.icon'],
        'bmp'  => ['image/bmp'],
        'tiff' => ['image/tiff'],
        'tif'  => ['image/tiff'],
        'avif' => ['image/avif'],
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls'  => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'ppt'  => ['application/vnd.ms-powerpoint'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'txt'  => ['text/plain'],
        'csv'  => ['text/csv', 'application/csv', 'text/plain'],
        'html' => ['text/html'],
        'css'  => ['text/css'],
        'js'   => ['application/javascript', 'text/javascript'],
        'json' => ['application/json', 'text/plain'],
        'xml'  => ['application/xml', 'text/xml'],
        'zip'  => ['application/zip', 'application/x-zip-compressed'],
        'rar'  => ['application/vnd.rar', 'application/x-rar', 'application/x-rar-compressed'],
        'gz'   => ['application/gzip', 'application/x-gzip'],
        'tar'  => ['application/x-tar'],
        '7z'   => ['application/x-7z-compressed'],
        'mp3'  => ['audio/mpeg', 'audio/mp3'],
        'wav'  => ['audio/wav', 'audio/x-wav'],
        'ogg'  => ['audio/ogg', 'application/ogg'],
        'mp4'  => ['video/mp4'],
        'webm' => ['video/webm'],
        'avi'  => ['video/x-msvideo'],
        'mov'  => ['video/quicktime'],
        'mkv'  => ['video/x-matroska', 'video/matroska'],
        'flv'  => ['video/x-flv'],
        'wmv'  => ['video/x-ms-wmv'],
        'ttf'  => ['font/ttf', 'font/sfnt', 'application/font-sfnt', 'application/x-font-ttf'],
        'otf'  => ['font/otf', 'font/sfnt', 'application/vnd.ms-opentype', 'application/x-font-opentype'],
        'woff' => ['font/woff', 'application/font-woff', 'application/x-font-woff'],
        'woff2'=> ['font/woff2', 'application/font-woff2'],
        'eot'  => ['application/vnd.ms-fontobject'],
    ];

    /**
     * @return string[]
     */
    public static function getMimeTypes(string $ext): array
    {
        $ext = \strtolower(\trim($ext, '. '));
        return self::$extensionMap[$ext] ?? [];
    }

    /**
     * @param string $ext 逗号分隔的扩展名
     * @return string[]
     */
    public static function collectMimes(string $ext): array
    {
        $mimes = [];
        foreach (self::collectExtensions($ext) as $extension) {
            $mimes = array_merge($mimes, self::getMimeTypes($extension));
        }
        return array_values(array_unique($mimes));
    }

    /** @return list<string> */
    public static function collectExtensions(string $ext): array
    {
        $ext = strtolower(trim($ext));
        if ($ext === '' || $ext === '*') {
            return self::$safeUploadExtensions;
        }
        $extensions = [];
        foreach (explode(',', $ext) as $extension) {
            $extension = trim($extension, ". \t\n\r\0\x0B");
            if ($extension === '*') {
                return self::$safeUploadExtensions;
            }
            if (
                $extension !== ''
                && isset(self::$extensionMap[$extension])
                && !in_array($extension, self::$activeBrowserExtensions, true)
            ) {
                $extensions[] = $extension;
            }
        }
        return array_values(array_unique($extensions));
    }
}
