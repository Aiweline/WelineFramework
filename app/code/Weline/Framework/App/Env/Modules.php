<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\App\Env;

use Weline\Framework\App\Env;

class Modules
{
    private array $modules = [];
    private array $active_modules = [];
    private array $disable_modules = [];

    /**
     * @DESC         |获取已经安装的模块列表
     *
     * 参数区：
     *
     * @return array
     */
    public function getList()
    {
        $modules_file = Env::path_MODULES_FILE;
        if (!is_file($modules_file)) {
            (new \Weline\Framework\Compilation\CompiledPhpArrayWriter())->write($modules_file, []);
        }
        $modules_data = \Weline\Framework\Compilation\CompiledPhpRegistry::load($modules_file);
        return is_array($modules_data) ? $modules_data : [];
    }

}
