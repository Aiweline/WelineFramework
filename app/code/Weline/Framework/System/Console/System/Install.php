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
    private const DATABASE_OPTION_KEYS = [
        'type' => true,
        'path' => true,
        'hostname' => true,
        'hostport' => true,
        'database' => true,
        'username' => true,
        'password' => true,
        'prefix' => true,
        'charset' => true,
        'collate' => true,
        'persistent' => true,
        'pool_size' => true,
        'timeout' => true,
    ];

    private const POSTGRESQL_DEFAULTS = [
        'type' => 'pgsql',
        'hostname' => '127.0.0.1',
        'hostport' => '5432',
        'database' => 'weline',
        'username' => 'weline',
        'password' => 'weline',
        'prefix' => 'w_',
        'charset' => 'utf8',
        'collate' => 'utf8_general_ci',
    ];

    private const SQLITE_SANDBOX_DEFAULTS = [
        'type' => 'sqlite',
        'path' => APP_PATH . 'etc/sandbox_db.sqlite',
        'prefix' => 'w_',
        'charset' => 'utf8mb4',
        'collate' => 'utf8mb4_general_ci',
    ];

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
            'env' => [
                'functions' => ['exec', 'putenv'],
                'modules' => ['PDO', 'exif', 'fileinfo', 'xsl'],
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
            $this->printer->note(
                __('未显式配置主数据库，使用默认 PostgreSQL 开发连接（127.0.0.1:5432 / weline）。'),
                __('系统')
            );
        }
        if (!isset($args_config['sandbox_db'])) {
            $this->printer->note(__('沙盒数据库未配置，使用隔离开发专用 SQLite。'), __('系统'));
        }
        $db_config = array_intersect_key($args_config['db'] ?? [], self::DATABASE_OPTION_KEYS);
        $dbType = strtolower(trim((string)($db_config['type'] ?? self::POSTGRESQL_DEFAULTS['type'])));
        $db_config = array_replace(
            $dbType === 'sqlite'
                ? array_replace(self::SQLITE_SANDBOX_DEFAULTS, ['path' => APP_PATH . 'etc/db.sqlite'])
                : self::POSTGRESQL_DEFAULTS,
            $db_config,
            ['type' => $dbType],
        );
        if (strtolower($db_config['type']) !== 'sqlite') {
            foreach ($db_keys as $db_key => $v) {
                if (!isset($db_config[$db_key])) {
                    $this->printer->error(__('数据库') . $db_key . __('配置不能为空！示例：bin/w system:install --db-') . $db_key . '=demo', __('系统'));
                    exit();
                }
            }
        }
        foreach ($db_config as $key => $item) {
            echo $this->printer->colorize(str_pad($key, 8, ' ', STR_PAD_LEFT), $this->printer::WARNING) . '=>' . $this->printer->colorize($item, $this->printer::NOTE) . "\r\n";
        }
        $sandbox_db_config = array_intersect_key($args_config['sandbox_db'] ?? [], self::DATABASE_OPTION_KEYS);
        $sandboxType = strtolower(trim((string)($sandbox_db_config['type'] ?? self::SQLITE_SANDBOX_DEFAULTS['type'])));
        $sandbox_db_config = array_replace(
            $sandboxType === 'sqlite'
                ? self::SQLITE_SANDBOX_DEFAULTS
                : array_replace(self::POSTGRESQL_DEFAULTS, [
                    'database' => 'sandbox_weline',
                    'username' => 'sandbox_weline',
                    'password' => 'sandbox_weline',
                ]),
            $sandbox_db_config,
            ['type' => $sandboxType],
        );
        if (strtolower($sandbox_db_config['type']) !== 'sqlite') {
            foreach ($db_keys as $db_key => $v) {
                if (!isset($sandbox_db_config[$db_key])) {
                    $this->printer->error('数据库' . $db_key . '配置不能为空！示例：bin/w system:install --sandbox_db-' . $db_key . '=demo', '系统');
                    exit();
                }
            }
        }
        foreach ($db_config as $key => $item) {
            echo $this->printer->colorize(str_pad($key, 8, ' ', STR_PAD_LEFT), $this->printer::WARNING) . '=>' . $this->printer->colorize($item, $this->printer::NOTE) . "\r\n";
        }
        $this->printer->success('参数检测通过！', 'OK');
        $this->printer->note('第三步：配置安装...', '系统');
        $this->runner->installDb(['db' => $db_config, 'sandbox_db' => $sandbox_db_config]);
        $this->printer->note('第四步：数据安装...', '系统');
//        $this->runner->systemInstall();
        // 使用新的参数名
        $initData['backend'] = 'admin_' . uniqid();
        $initData['rest_backend'] = 'api_' . uniqid();
        $this->runner->systemInit($initData);
        $this->printer->note('第五步：系统命令更新...', '系统');
        $this->runner->systemCommands();
        $this->printer->success('初始化数据完成！', 'OK');
        $this->printer->note('-------------------------------------------------------');
        // 生成安装锁文件
        if (!is_file($install_file)) {
            $this->printer->note('生成安装锁文件...');
            $file = new \Weline\Framework\System\File\Io\File();
            $file->open($install_file, $file::mode_w);
            $file->close();
        }
        // Unix/Linux 下确保 bin/w、bin/m 可执行，便于直接执行 bin/w cron:task:run 等
        if (DIRECTORY_SEPARATOR !== '\\') {
            $binW = BP . 'bin' . DIRECTORY_SEPARATOR . 'w';
            $binM = BP . 'bin' . DIRECTORY_SEPARATOR . 'm';
            if (is_file($binW)) {
                @chmod($binW, 0755);
            }
            if (is_file($binM)) {
                @chmod($binM, 0755);
            }
        }
        $this->printer->success(str_pad('后台入口: ', 20, ' ', STR_PAD_LEFT) . $initData['backend']);
        $this->printer->success(str_pad('REST后台入口: ', 20, ' ', STR_PAD_LEFT) . $initData['rest_backend']);
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

    public function help(): array|string
    {
        // 检测操作系统
        $isWindows = (DIRECTORY_SEPARATOR === '\\');
        
        // 根据操作系统准备不同的示例
        if ($isWindows) {
            // Windows PowerShell 格式 - 使用反引号或单行
            $examples = [
                '使用默认 PostgreSQL 开发连接安装' => 'php bin/w system:install',
                '显式使用 SQLite 安装（仅隔离开发）' => 'php bin/w system:install --db-type=sqlite',
                '使用自定义 PostgreSQL 数据库安装' =>
                    'php bin/w system:install `' . "\n" .
                    '  --db-type=pgsql `' . "\n" .
                    '  --db-hostname=127.0.0.1 `' . "\n" .
                    '  --db-hostport=5432 `' . "\n" .
                    '  --db-database=weline `' . "\n" .
                    '  --db-username=weline `' . "\n" .
                    '  --db-password=your_password `' . "\n" .
                    '  --db-prefix=w_ `' . "\n" .
                    '  --db-charset=utf8 `' . "\n" .
                    '  --db-collate=utf8_general_ci',
            ];
        } else {
            // Unix/Linux/Mac 格式 - 使用反斜杠
            $examples = [
                '使用默认 PostgreSQL 开发连接安装' => 'php bin/w system:install',
                '显式使用 SQLite 安装（仅隔离开发）' => 'php bin/w system:install --db-type=sqlite',
                '使用自定义 PostgreSQL 数据库安装' =>
                    'php bin/w system:install \\' . "\n" .
                    '  --db-type=pgsql \\' . "\n" .
                    '  --db-hostname=127.0.0.1 \\' . "\n" .
                    '  --db-hostport=5432 \\' . "\n" .
                    '  --db-database=weline \\' . "\n" .
                    '  --db-username=weline \\' . "\n" .
                    '  --db-password=your_password \\' . "\n" .
                    '  --db-prefix=w_ \\' . "\n" .
                    '  --db-charset=utf8 \\' . "\n" .
                    '  --db-collate=utf8_general_ci',
            ];
        }
        
        // 基于tip的默认help实现
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'system:install',
            $this->tip(),
            [
                '--db-type' => '数据库类型（pgsql/mysql/sqlite，默认：pgsql；sqlite 仅隔离开发）',
                '--db-hostname' => '数据库主机地址（默认：127.0.0.1）',
                '--db-hostport' => '数据库端口（PostgreSQL 默认：5432）',
                '--db-database' => '数据库名称（默认：weline）',
                '--db-username' => '数据库用户名（默认：weline）',
                '--db-password' => '数据库密码（默认开发值：weline）',
                '--db-prefix' => '表前缀（默认：w_）',
                '--db-charset' => '字符集（PostgreSQL 默认：utf8）',
                '--db-collate' => '排序规则（PostgreSQL 默认：utf8_general_ci）',
                '--sandbox_db-*' => '沙盒数据库配置（可选，参数同上）',
                '-h, --help' => '显示帮助信息',
            ],
            [
                '注意事项：',
                '  1. PostgreSQL 是默认开发与生产数据库',
                '  2. SQLite 仅用于显式沙盒或隔离开发，不作为正式验收数据库',
                '  3. 安装前请确保数据库已创建',
                '  4. 安装完成后会生成随机的后台入口密钥',
                '  5. 安装成功后使用 php bin/w server:start 启动服务',
                $isWindows ? '  6. Windows PowerShell 使用反引号 (`) 连接多行命令' : '  6. Unix/Linux 使用反斜杠 (\\) 连接多行命令',
            ],
            $examples
        );
    }
}
/*
php bin/w system:install  --db-type=pgsql  --db-hostname=127.0.0.1  --db-database=weline  --db-username=weline  --db-password=weline --db-charset=utf8 --db-collate=utf8_general_ci
*/
