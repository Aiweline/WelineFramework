<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Installer\RunType\System;

use Weline\Framework\App\Exception;
use Weline\Framework\System\File\Io\File;

class Init
{
    public function run(array $params)
    {
        if (!isset($params['admin']) || !isset($params['api_admin'])) {
            throw new Exception('参数不完整！');
        }
        $env_instance = \Weline\Framework\App\Env::getInstance();
        if (!is_file($env_instance::path_ENV_FILE)) {
            throw new Exception('不存在的环境！');
        }
        $env = require $env_instance::path_ENV_FILE;
        if (empty($env)) {
            throw new Exception('环境为空！');
        }
        $env['admin']     = $params['admin'];
        $env['api_admin'] = $params['api_admin'];
        $env['debug_key'] = uniqid('',true);
        $file             = new File();
        $file->open($env_instance::path_ENV_FILE, $file::mode_w);
        $text = '<?php return ' . var_export($env, true) . ';';
        $file->write($text);
        $file->close();

        return ['data' => [
            'admin'     => $params['admin'],
            'api_admin' => $params['api_admin'],
        ], 'hasErr'    => false, 'msg' => '-------  配置环境初始化...  -------'];
    }
}
