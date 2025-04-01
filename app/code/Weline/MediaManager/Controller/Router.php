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
        # 匹配静态资源/static/
        if (str_starts_with(strtolower($path), '/static/')) {
            $path = '/pub' . $path;
            if (is_file(BP . $path)) {
                /**@var Core $core */
                $core = ObjectManager::getInstance(Core::class);
                $core->StaticFile($path, true);
                exit;
            }
        }
        # 匹配媒介资源
        if (str_starts_with(strtolower($path), '/pub/media/')) {
            if (is_file(BP . $path)) {
                /**@var Core $core */
                $core = ObjectManager::getInstance(Core::class);
                $core->StaticFile($path, true);
                exit;
            }
        }
        if (str_starts_with(strtolower($path), '/media/image/')) {
            $file = str_replace('/media/image/', '', $path);
            $rule['file'] = $file;
            $path = '/media/image/index';
        }
    }
}
