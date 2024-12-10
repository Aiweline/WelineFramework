<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/11/26 15:02:25
 */

namespace Weline\Framework\Setup\Console\Setup;


use Weline\Framework\App\Env;
use Weline\Framework\Console\Console\Server\Server;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\System\Text;

class Upgrade implements \Weline\Framework\Console\CommandInterface
{

    function __construct(
        private Printing $printing
    )
    {

    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        # 如果未安装: setup/install.lock 不存在
        $install = false;
        if (!file_exists(BP . 'setup/install.lock')) {
            $install = true;
            # 如果配置中没有db
            $default_sample_db = Env::get('db') ?? [];
            Env::set('sample_db', $default_sample_db);
            # 使用默认配置生成
            $sandbox_db = Env::get('sandbox_db') ?? [];
            if ($sandbox_db) {
                $sandbox_db['master']['path'] = APP_PATH . 'etc/db.sqlite';
                $sandbox_db['slaves'] = [];
                $sandbox_db['mysql_sample_db'] = [
                    'tip' => __('演示如何配置mysql数据库的配置信息样例，mysql_sample_db可以删除，不影响系统，仅作为配置参考。'),
                    'hostname' => 'demo',
                    'database' => 'demo',
                    'username' => 'demo',
                    'password' => 'demo',
                    'type' => 'mysql',
                    'hostport' => '3306',
                    'prefix' => 'm_',
                    'charset' => 'utf8mb4',
                    'collate' => 'utf8mb4_general_ci',
                ];
            }
            Env::set('db', $sandbox_db);
            $admin = Env::get('admin');
            if (empty($admin)) {
                Env::set('admin', Text::random_string(32));
            }
            $api_admin = Env::get('api_admin');
            if (empty($api_admin)) {
                Env::set('api_admin', Text::random_string(32));
            }
        }
        /**@var \Weline\Framework\Module\Console\Module\Upgrade $moduleUpdate */
        $moduleUpdate = ObjectManager::getInstance(\Weline\Framework\Module\Console\Module\Upgrade::class);
        $moduleUpdate->execute($args, $data);

        if ($install) {
            $this->printing->success(__('系统识别到您初次安装！已为您初始化安装参数。'), __('安装'));
            $this->printing->success(__('您的后台入口地址密钥：%1 ', Env::get('admin')), __('安装'));
            $this->printing->success(__('您的API后台入口地址密钥：%1', Env::get('api_admin')), __('安装'));
            $this->printing->success(__('使用server:start命令指定的地址访问网站，默认使用http://127.0.0.1:9981，例如:'), __('安装'));
            $this->printing->note(__('访问后台：%1/admin/login', 'http://127.0.0.1:9981/' . Env::get('api_admin')), __('安装'));
            $this->printing->note(__('访问后台API：%1', 'http://127.0.0.1:9981/' . Env::get('api_admin')), __('安装'));
            $this->printing->warning(__('默认使用sqlite作为开发数据库，若要修改数据库，请转到 %1 下的env.php按照数组键sample_db中的配置样本，修改db键即可。', APP_ETC_PATH), __('安装'));
            $this->printing->setup(__('由于您属于第一次安装，您可以使用命令行：php bin/w setup:upgrade , 然后使用：php bin/w server:start 快速开启本地开发服务器。'), __('安装'));
            # 设置安装文件
            file_put_contents(BP . 'setup/install.lock', date('Y-m-d H:i:s'));
        }
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '框架代码刷新。';
    }
}
