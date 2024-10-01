<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\App\Env;

use Weline\Framework\App\Env;
use Weline\Framework\System\File\Io\File;

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
            $file = new File();
            $file->open($modules_file, $file::mode_w_add);
            $text = '<?php return ' . w_var_export([], true) . ';?>';
            $file->write($text);
            $file->close();
        }
        $modules_data  = include $modules_file;
        return is_array($modules_data) ? $modules_data : [];
    }

}
