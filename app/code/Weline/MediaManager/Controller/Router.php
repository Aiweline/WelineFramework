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
        if (!($_SERVER['WELINE_IS_MEDIA'] ?? false)) {
            return;
        }
        $pathLower = strtolower(ltrim($path, '/'));
        if (str_starts_with($pathLower, 'media/image/')) {
            $file = preg_replace('#^media/image/#i', '', $pathLower);
            $rule['file'] = urldecode($file);
            $path = '/media/image/index';
        } elseif (str_starts_with($pathLower, 'media/file/')) {
            $file = preg_replace('#^media/file/#i', '', $pathLower);
            $rule['file'] = urldecode($file);
            $path = '/media/file/index';
        }
    }
}
