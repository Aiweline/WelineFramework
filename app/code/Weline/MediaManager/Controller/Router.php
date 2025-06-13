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
        if($_SERVER['WELINE_IS_MEDIA']){
            if (str_starts_with(strtolower($path), '/media/image/')) {
                $file = str_replace('/media/image/', '', $path);
                $rule['file'] = urldecode($file);
                $path = '/media/image/index';
            }
            if (str_starts_with(strtolower($path), '/media/file/')) {
                $file = str_replace('/media/file/', '', $path);
                $rule['file'] = urldecode($file);
                $path = '/media/file/index';
            }
        }
    }
}
