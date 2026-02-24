<?php

declare(strict_types=1);

namespace Weline\MediaManager\Helper;

class MimeTypes
{
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
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xls'  => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'ppt'  => ['application/vnd.ms-powerpoint'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        'txt'  => ['text/plain'],
        'csv'  => ['text/csv'],
        'html' => ['text/html'],
        'css'  => ['text/css'],
        'js'   => ['application/javascript', 'text/javascript'],
        'json' => ['application/json'],
        'xml'  => ['application/xml', 'text/xml'],
        'zip'  => ['application/zip'],
        'rar'  => ['application/x-rar-compressed'],
        'gz'   => ['application/gzip'],
        'tar'  => ['application/x-tar'],
        '7z'   => ['application/x-7z-compressed'],
        'mp3'  => ['audio/mpeg'],
        'wav'  => ['audio/wav'],
        'ogg'  => ['audio/ogg'],
        'mp4'  => ['video/mp4'],
        'webm' => ['video/webm'],
        'avi'  => ['video/x-msvideo'],
        'mov'  => ['video/quicktime'],
        'mkv'  => ['video/x-matroska'],
        'flv'  => ['video/x-flv'],
        'wmv'  => ['video/x-ms-wmv'],
        'ttf'  => ['font/ttf'],
        'otf'  => ['font/otf'],
        'woff' => ['font/woff'],
        'woff2'=> ['font/woff2'],
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
        $mimes = ['image', 'text/plain'];
        if ($ext !== '') {
            foreach (\explode(',', $ext) as $e) {
                $mimes = \array_merge($mimes, self::getMimeTypes(\trim($e)));
            }
        }
        return \array_unique($mimes);
    }
}
