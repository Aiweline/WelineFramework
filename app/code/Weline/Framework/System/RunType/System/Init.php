<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\System\RunType\System;

use Weline\Framework\App\Exception;
use Weline\Framework\System\File\Io\File;
use Weline\Framework\App\Env;

class Init
{
    public function run(array $params)
    {
        // 支持新旧参数名：backend/admin, rest_backend/api_admin
        $hasBackend = isset($params['backend']) || isset($params['admin']);
        $hasRestBackend = isset($params['rest_backend']) || isset($params['api_admin']);
        if (!$hasBackend || !$hasRestBackend) {
            throw new Exception('参数不完整！需要 backend(或admin) 和 rest_backend(或api_admin) 参数');
        }
        $env_instance = \Weline\Framework\App\Env::getInstance();
        if (!is_file($env_instance::path_ENV_FILE)) {
            throw new Exception('不存在的环境！');
        }
        $env = require $env_instance::path_ENV_FILE;
        if (empty($env)) {
            throw new Exception('环境为空！');
        }
        # 获取运行用户
        $current_user = Env::user();
        $env['user'] = $current_user;
        // 使用新的 area_routes 分组结构
        $env['area_routes'] = [
            'backend' => [
                'prefix' => $params['admin'] ?? $params['backend'] ?? '',
                'description' => '后台管理',
            ],
            'rest_frontend' => [
                'prefix' => $params['api'] ?? $params['rest_frontend'] ?? 'api',
                'description' => '前端 REST API',
            ],
            'rest_backend' => [
                'prefix' => $params['api_admin'] ?? $params['rest_backend'] ?? '',
                'description' => '后台 REST API',
            ],
        ];
        $env['debug_key'] = uniqid('', true);
        $file = new File();
        $file->open($env_instance::path_ENV_FILE, $file::mode_w);
        $text = '<?php return ' . var_export($env, true) . ';';
        $file->write($text);
        $file->close();

        return ['data' => [
            'backend' => $env['area_routes']['backend']['prefix'],
            'rest_backend' => $env['area_routes']['rest_backend']['prefix'],
        ], 'hasErr' => false, 'msg' => '-------  配置环境初始化...  -------'];
    }
}
