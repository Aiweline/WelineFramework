<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Weline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\ThemeFancy\Controller;

use Weline\Framework\Router\RouterInterface;
use function str_contains;

class Router implements RouterInterface
{
    public static function process(&$path, &$rule)
    {
        $flag = '/frontend/Template/Fancy';
        if (str_contains($path, $flag)||str_contains($path, strtolower($flag))) {
            $path_arr      = explode($flag, $path);
            $function_path = array_pop($path_arr);
            if ($function_path = trim($function_path, '/')) {
                $path           = $flag;
                $rule['method'] = $function_path;
            }
        }
    }
}
