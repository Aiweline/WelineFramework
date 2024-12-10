<?php

namespace Weline\MediaManager\Controller;

use Weline\Framework\Http\Request;
use Weline\Framework\Router\RouterInterface;

class Router implements RouterInterface
{
    /**
     * @inheritDoc
     */
    public static function process(string &$path, array &$rule): void
    {
        if (str_starts_with(strtolower($path), '/media/image/')) {
            $file = str_replace('/media/image/', '', $path);
            $rule['file'] = $file;
            $path = '/media/image/index';
        }
    }
}
