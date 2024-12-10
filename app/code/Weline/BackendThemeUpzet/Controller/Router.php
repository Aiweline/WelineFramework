<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/7/15 10:13:58
 */

namespace Weline\BackendThemeUpzet\Controller;

class Router implements \Weline\Framework\Router\RouterInterface
{

    /**
     * @inheritDoc
     */
    public static function process(string &$path, array &$rule)
    {
        $flag = '/upzet';
        if (str_contains(strtolower($path), $flag)) {
            # path处理
            if (str_ends_with($path, '.html')) {
                $path_arr         = explode('/', $path);
                $template         = array_pop($path_arr);
                $rule['template'] = $template;
                $path             = implode('/', $path_arr);
            } else {
                $rule['template'] = 'index.html';
            }
        }
    }
}