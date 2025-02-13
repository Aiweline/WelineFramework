<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\System\Console\System;

use Weline\Framework\App\System;
use Weline\Framework\Database\Setup\DataInterface;
use Weline\Framework\System\Runner;

class Install extends \Weline\Framework\Console\CommandAbstract
{
    /**
     * @var Runner
     */
    private Runner $runner;

    /**
     * @var System
     */
    private System $system;

    public function __construct(
        Runner $runner,
        System $system
    )
    {
        $this->runner = $runner;
        $this->system = $system;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $install_file = BP . 'setup/install.lock';
        if (is_file($install_file)) {
            $this->printer->warning('M框架已安装！重新安装将清空系统数据。', '警告');
            $this->printer->setup('是否继续（y/n）？');

            // 判断系统
            $input = $this->system->input();
            if (strtolower(chop($input)) !== 'y') {
                $this->printer->setup('操作已取消！', '提示');
                exit();
            }
        }
        $data = [
            'env'      => [
                'functions' => ['exec', 'putenv'],
                'modules'   => ['PDO', 'exif', 'fileinfo', 'xsl'],
            ],
            'commands' => [
                'bin/w command:upgrade',
                'bin/w deploy:mode:set dev',
                'bin/w setup:upgrade',
            ]
        ];
        // 环境检测
        $this->printer->note('第一步：环境检测...', '系统');
        $checkResult = $this->runner->checkEnv();
        if ($checkResult['hasErr']) {
            $this->printer->error('检测失败！', '系统');
            exit();
        }
        // 参数检测
        $this->printer->note('第二步：参数检测...', '系统');
        $args_config = [];
        foreach ($args as $arg) {
            // 数据库配置
            if (is_int(strpos($arg, '--db-'))) {
                $kv_arr = explode('=', str_replace('--db-', '', $arg));
                if (count($kv_arr) !== 2) {
                    $this->printer->error('错误的参数格式：' . $arg);
                    exit();
                }
                $args_config['db'][$kv_arr[0]] = $kv_arr[1];
            }
            // 数据库配置
            if (is_int(strpos($arg, '--sandbox_db-'))) {
                $kv_arr = explode('=', str_replace('--sandbox_db-', '', $arg));
                if (count($kv_arr) !== 2) {
                    $this->printer->error('错误的参数格式：' . $arg);
                    exit();
                }
                $args_config['sandbox_db'][$kv_arr[0]] = $kv_arr[1];
            }
        }
        array_shift($args);
        $db_keys = DataInterface::db_keys;
        if (!isset($args_config['db'])) {
            $this->printer->error('数据库配置为空！示例：bin/w system:install --db-type=mysql', '系统');
            foreach ($db_keys as $key => $item) {
                $this->printer->warning('--db-' . $key . '=' . ($item ? $item : 'null'), '数据库');
            }
            exit();
        }
        if (!isset($args_config['sandbox_db'])) {
            $this->printer->error('沙盒数据库配置为空！示例：bin/w system:install --sandbox_db-type=mysql', '系统');
            foreach ($db_keys as $key => $item) {
                $this->printer->warning('--sandbox_db-' . $key . '=' . ($item ? $item : 'null'), '沙盒数据库');
            }
            exit();
        }
        $db_config = $args_config['db'] ?? [];
        $db_config = array_intersect_key($db_config, $db_keys);
            $db_config['type'] ?? $db_config['type'] = 'mysql';
            $db_config['hostport'] ?? $db_config['hostport'] = '3306';
            $db_config['prefix'] ?? $db_config['prefix'] = 'w_';
            $db_config['charset'] ?? $db_config['charset'] = 'utf8mb4';
            $db_config['collate'] ?? $db_config['collate'] = 'utf8mb4_general_ci';
        foreach ($db_keys as $db_key => $v) {
            if (!isset($db_config[$db_key])) {
                $this->printer->error('数据库' . $db_key . '配置不能为空！示例：bin/w system:install --db-' . $db_key . '=demo', '系统');
                exit();
            }
        }
        foreach ($db_config as $key => $item) {
            echo $this->printer->colorize(str_pad($key, 8, ' ', STR_PAD_LEFT), $this->printer::WARNING) . '=>' . $this->printer->colorize($item, $this->printer::NOTE) . "\r\n";
        }
        $sandbox_db_config = $args_config['sandbox_db'] ?? [];
        $sandbox_db_config = array_intersect_key($sandbox_db_config, $db_keys);
            $sandbox_db_config['type'] ?? $sandbox_db_config['type'] = 'mysql';
            $sandbox_db_config['hostport'] ?? $sandbox_db_config['hostport'] = '3306';
            $sandbox_db_config['prefix'] ?? $sandbox_db_config['prefix'] = 'w_';
            $sandbox_db_config['charset'] ?? $sandbox_db_config['charset'] = 'utf8mb4';
            $sandbox_db_config['collate'] ?? $sandbox_db_config['collate'] = 'utf8mb4_general_ci';
        foreach ($db_keys as $db_key => $v) {
            if (!isset($sandbox_db_config[$db_key])) {
                $this->printer->error('数据库' . $db_key . '配置不能为空！示例：bin/w system:install --sandbox_db-' . $db_key . '=demo', '系统');
                exit();
            }
        }
        foreach ($db_config as $key => $item) {
            echo $this->printer->colorize(str_pad($key, 8, ' ', STR_PAD_LEFT), $this->printer::WARNING) . '=>' . $this->printer->colorize($item, $this->printer::NOTE) . "\r\n";
        }
        $this->printer->success('参数检测通过！', 'OK');
        $this->printer->note('第三步：配置安装...', '系统');
        $this->runner->installDb(['db' => $db_config,'sandbox_db' => $sandbox_db_config]);
        $this->printer->note('第四步：数据安装...', '系统');
//        $this->runner->systemInstall();
        $this->printer->note('第五步：系统命令更新...', '系统');
        $this->runner->systemCommands();
        $this->printer->note('第六步：系统初始化...', '系统');
        $initData['admin']     = 'admin_' . uniqid();
        $initData['api_admin'] = 'api_' . uniqid();
        $this->runner->systemInit($initData);
        $this->printer->success('初始化数据完成！', 'OK');
        $this->printer->note('-------------------------------------------------------');
        // 生成安装锁文件
        if (!is_file($install_file)) {
            $this->printer->note('生成安装锁文件...');
            $file = new \Weline\Framework\System\File\Io\File();
            $file->open($install_file, $file::mode_w);
            $file->close();
        }
        $this->printer->success(str_pad('admin后台入口: ', 20, ' ', STR_PAD_LEFT) . $initData['admin']);
        $this->printer->success(str_pad('Api后台入口: ', 20, ' ', STR_PAD_LEFT) . $initData['api_admin']);
        $this->printer->note('-------------------------------------------------------');
        $this->printer->success('恭喜你！系统安装完成！');
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '框架安装';
    }
}
/*
php bin/w system:install  --db-type=mysql  --db-hostname=127.0.0.1  --db-database=weline  --db-username=weline  --db-password=weline --db-charset=utf8 --db-collate=utf8_general_ci
*/
