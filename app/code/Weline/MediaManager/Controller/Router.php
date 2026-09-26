<?php

namespace Weline\MediaManager\Controller;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\Core;
use Weline\Framework\Router\RouterInterface;

class Router implements RouterInterface
{
    /**
     * @inheritDoc
     */
    public static function process(string &$path, array &$rule): void
    {
        if (!\w_env('is_media', false)) {
            return;
        }
        $pathLower = strtolower(ltrim($path, '/'));
        if (str_starts_with($pathLower, 'media/image/')) {
            $file = preg_replace('#^media/image/#i', '', $pathLower);
            $rule['file'] = self::resolveRasterFallback(urldecode($file));
            $path = '/media/image/index';
        } elseif (str_starts_with($pathLower, 'media/file/')) {
            $file = preg_replace('#^media/file/#i', '', $pathLower);
            $rule['file'] = self::resolveRasterFallback(urldecode($file));
            $path = '/media/file/index';
        }
    }

    /**
     * 批量 webp 迁移后，DB 中可能仍存 .jpg/.png/.gif 路径但磁盘已转为 .webp。
     * 当原始文件不存在时，尝试同名 .webp。
     */
    private static function resolveRasterFallback(string $relative): string
    {
        $abs = PUB . 'media/' . ltrim($relative, '/');
        if (file_exists($abs)) {
            return $relative;
        }
        $info = pathinfo($relative);
        $ext = strtolower((string)($info['extension'] ?? ''));
        $stem = (string)($info['filename'] ?? '');
        if ($stem === '' || !in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true)) {
            return $relative;
        }
        $dir = (string)($info['dirname'] ?? '.');
        $webpRel = ($dir === '.' || $dir === '') ? ($stem . '.webp') : ($dir . '/' . $stem . '.webp');
        $webpAbs = PUB . 'media/' . ltrim($webpRel, '/');
        if (file_exists($webpAbs)) {
            return $webpRel;
        }
        return $relative;
    }
}
