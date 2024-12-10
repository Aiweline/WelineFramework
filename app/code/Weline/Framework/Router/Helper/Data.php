<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Router\Helper;

use Weline\Framework\App\Env;
use Weline\Framework\System\File\Io\File;

class Data
{
    /**
     * @DESC         |更新模块数据
     *
     * 参数区：
     *
     * @param array $modules
     *
     * @throws \Weline\Framework\App\Exception
     */
    public function updateModules(array &$modules)
    {
        $file = new File();
        $file->open(Env::path_MODULES_FILE, $file::mode_w_add);
        $text = '<?php return ' . var_export($modules, true) . ';';
        $file->write($text);
        $file->close();
    }

    /**
     * @DESC         |更新模块数据
     *
     * 参数区：
     *
     * @param array  $routers
     * @param string $path
     *
     * @throws \Weline\Framework\App\Exception
     */
    public function updatePcRouters(string $path, array &$routers)
    {
        $file = new File();
        $file->open($path, $file::mode_w_add);
        $text = '<?php return ' . var_export($routers, true) . ';';
        $file->write($text);
        $file->close();
    }

    /**
     * @DESC         |更新模块数据
     *
     * 参数区：
     *
     * @param string $path
     * @param array  $api
     *
     * @throws \Weline\Framework\App\Exception
     */
    public function updateApiRouters(string $path, array &$api)
    {
        $routers[$api['router']] = $api['rule'];
        if (is_file($path)) {
            $file_routers = require $path;
            $routers      = array_merge($file_routers, $routers);
        }
        $file = new File();
        $file->open($path, $file::mode_w_add);
        $text = '<?php return ' . var_export($routers, true) . ';';
        $file->write($text);
        $file->close();
    }
}
