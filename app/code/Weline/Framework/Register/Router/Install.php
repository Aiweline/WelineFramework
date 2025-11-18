<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Register\Router;

use Weline\Framework\Console\ConsoleException;
use Weline\Framework\Register\Router\Data\DataInterface;
use Weline\Framework\Router\Handle;

class Install implements DataInterface
{
    /**
     * @DESC         |路由安装
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param array $routerParam
     *
     * @throws \Weline\Framework\Console\ConsoleException
     * @throws \Weline\Framework\App\Exception
     */
    public static function install(array $routerParam)
    {
        if (count(array_intersect_key(self::register_param, $routerParam)) !== count(self::register_param)) {
            $params = '';
            foreach (self::register_param as $key => $item) {
                $params .= $key . ',';
            }

            throw new ConsoleException('路由注册所需参数不完整：' . rtrim($params, ','));
        }
        // 注册
        $moduleHandler = new Handle();
        $moduleHandler->register($routerParam);
    }
}
