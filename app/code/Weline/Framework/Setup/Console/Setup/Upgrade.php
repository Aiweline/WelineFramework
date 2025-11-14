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
use Weline\Framework\Event\EventsManager;
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
        # 检查系统是否已安装
        $is_installed = $this->checkSystemInstalled();
        
        # 如果未安装，提供安装选项
        if (!$is_installed) {
            $this->handleSystemNotInstalled();
            return;
        }
        
        # 系统已安装，在升级开始前启用维护模式
        try {
            # 启用维护模式
            Env::getInstance()->setConfig('maintenance', true);
            $this->printing->note(__('系统已设置为维护模式，开始执行升级...'));
            
            # 执行正常的升级流程
            /**@var \Weline\Framework\Module\Console\Module\Upgrade $moduleUpdate */
            $moduleUpdate = ObjectManager::getInstance(\Weline\Framework\Module\Console\Module\Upgrade::class);
            $moduleUpdate->execute($args, $data);
            
            # 触发系统升级后事件
            /**@var EventsManager $eventsManager */
            $eventsManager = ObjectManager::getInstance(EventsManager::class);
            $eventsManager->dispatch('Framework_Setup::upgrade_after');
            
            $this->printing->success(__('系统升级完成！'));
        } catch (\Exception $e) {
            $this->printing->error(__('系统升级过程中发生错误：%{1}', [$e->getMessage()]));
            throw $e;
        } finally {
            # 升级完成后自动关闭维护模式
            try {
                $result = Env::getInstance()->setConfig('maintenance', false);
                if ($result) {
                    $this->printing->note(__('维护模式已关闭。'));
                } else {
                    $this->printing->warning(__('关闭维护模式失败，配置可能未保存。请手动运行 php bin/w maintenance:disable 关闭维护模式。'));
                }
            } catch (\Exception $e) {
                # 如果关闭维护模式失败，输出警告但不影响主流程
                $this->printing->warning(__('关闭维护模式时发生错误：%{1}。请手动运行 php bin/w maintenance:disable 关闭维护模式。', [$e->getMessage()]));
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '框架代码刷新。';
    }

    public function help(): array|string
    {
        // 基于tip的默认help实现
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'setup:upgrade',
            $this->tip(),
            [
                '--model' => '仅升级数据库模型',
                '--route' => '仅升级路由',
                '-m, --module=<模块名>' => '升级指定模块（例如：Weline_Ai）',
                '-h, --help' => '显示帮助信息',
            ],
            [],
            [
                '完整系统升级' => 'php bin/w setup:upgrade',
                '仅升级路由' => 'php bin/w setup:upgrade --route',
                '仅升级数据库模型' => 'php bin/w setup:upgrade --model',
                '升级指定模块' => 'php bin/w setup:upgrade -m Weline_Ai',
                '升级指定模块的路由' => 'php bin/w setup:upgrade --route -m Weline_Ai',
            ]
        );
    }

    /**
     * @inheritDoc
     */
    public function aliases(): array
    {
        return [];
    }

    /**
     * 检查系统是否已安装
     * @return bool
     */
    private function checkSystemInstalled(): bool
    {
        // 检查 install.lock 文件是否存在
        if (!file_exists(BP . 'setup/install.lock')) {
            return false;
        }
        
        // 检查 env.php 文件是否存在
        $env_file = APP_PATH . 'etc/env.php';
        if (!file_exists($env_file)) {
            return false;
        }
        
        // 检查 env.php 文件是否为空
        $env_content = file_get_contents($env_file);
        if (empty(trim($env_content))) {
            return false;
        }
        
        return true;
    }

    /**
     * 处理系统未安装的情况
     */
    private function handleSystemNotInstalled(): void
    {
        $this->printing->warning(__('检测到系统尚未安装！'), __('警告'));
        
        // 检查是否在交互式环境中
        $isInteractive = $this->isInteractive();
        
        if (!$isInteractive) {
            // 非交互式环境，自动使用默认开发环境快速安装
            $this->printing->note(__('检测到非交互式环境，自动使用默认开发环境快速安装模式...'));
            $this->printing->warning(__('此模式将使用默认的 SQLite 数据库，仅适合开发测试使用。'));
            $this->printing->warning(__('生产环境强烈建议使用命令行安装并配置 MySQL 数据库！'));
            $this->printing->note(__('开始使用开发环境快速安装...'));
            $this->executeDevelopmentInstall();
            return;
        }
        
        // 交互式环境，显示选项
        $this->printing->note(__('请选择安装方式：'));
        $this->printing->note(__('1. 使用命令行安装（推荐生产环境）'));
        $this->printing->note(__('2. 使用默认开发环境快速安装（仅建议开发者使用，生产环境慎用）'));
        $this->printing->setup(__('请输入选项 (1/2)：'));
        
        // 获取用户输入
        $system = ObjectManager::getInstance(\Weline\Framework\App\System::class);
        $input = trim($system->input() ?? '');
        
        if ($input === '1') {
            // 显示 system:install 命令的帮助信息
            $this->showSystemInstallHelp();
        } elseif ($input === '2' || empty($input)) {
            // 如果输入为空，默认选择选项2
            if (empty($input)) {
                $this->printing->note(__('未输入选项，使用默认开发环境快速安装模式...'));
            } else {
                $this->printing->warning(__('您选择了开发环境快速安装模式。'));
            }
            // 确认是否继续使用开发环境安装
            $this->printing->warning(__('此模式将使用默认的 SQLite 数据库，仅适合开发测试使用。'));
            $this->printing->warning(__('生产环境强烈建议使用命令行安装并配置 MySQL 数据库！'));
            $this->printing->setup(__('是否继续？(y/n)：'));
            
            $confirm = strtolower(trim($system->input() ?? ''));
            if ($confirm === 'y' || $confirm === 'yes' || empty($confirm)) {
                // 如果确认输入为空，默认继续
                if (empty($confirm)) {
                    $this->printing->note(__('未输入确认，默认继续安装...'));
                }
                $this->printing->note(__('开始使用开发环境快速安装...'));
                // 继续执行原来的安装逻辑
                $this->executeDevelopmentInstall();
            } else {
                $this->printing->note(__('安装已取消。'));
                exit(0);
            }
        } else {
            $this->printing->error(__('无效的选项！请重新运行命令。'));
            exit(1);
        }
    }
    
    /**
     * 检查是否在交互式环境中
     * @return bool
     */
    private function isInteractive(): bool
    {
        // 检查 STDIN 是否是终端（TTY）
        if (function_exists('stream_isatty') && defined('STDIN')) {
            return stream_isatty(STDIN);
        }
        
        // 备用检查：尝试读取 STDIN 是否可用
        if (defined('STDIN') && is_resource(STDIN)) {
            // 检查 STDIN 是否可读
            $read = [STDIN];
            $write = [];
            $except = [];
            $result = @stream_select($read, $write, $except, 0);
            return $result !== false;
        }
        
        // 如果无法确定，假设是非交互式环境
        return false;
    }

    /**
     * 显示 system:install 命令的帮助信息
     */
    private function showSystemInstallHelp(): void
    {
        $this->printing->note(__(''));
        $this->printing->note(__('═══════════════════════════════════════════════════════════════'));
        $this->printing->setup(__('正在显示 system:install 命令帮助信息...'));
        $this->printing->note(__('═══════════════════════════════════════════════════════════════'));
        $this->printing->note(__(''));
        
        // 直接调用 system:install 命令的帮助方法
        try {
            $installCommand = ObjectManager::getInstance(\Weline\Framework\System\Console\System\Install::class);
            if (method_exists($installCommand, 'help')) {
                // 直接输出帮助信息，颜色代码会被保留
                $helpContent = $installCommand->help();
                // 直接打印到标准输出，保留 ANSI 颜色代码
                fwrite(STDOUT, $helpContent);
                fwrite(STDOUT, "\n");
            } else {
                $this->printing->error(__('无法加载 system:install 命令帮助信息'));
            }
        } catch (\Exception $e) {
            $this->printing->error(__('加载帮助信息失败：') . $e->getMessage());
        }
        
        exit(0);
    }

    /**
     * 执行开发环境快速安装
     */
    private function executeDevelopmentInstall(): void
    {
        # 使用默认配置生成
        $default_sample_db = Env::get('db') ?? [];
        Env::set('sample_db', $default_sample_db);
        
        $sandbox_db = Env::get('sandbox_db') ?? [];
        if (empty($sandbox_db)) {
            $sandbox_db = [
                'master' => [
                    'type' => 'sqlite',
                    'path' => APP_PATH . 'etc/db.sqlite',
                    'prefix' => 'w_',
                    'charset' => 'utf8mb4',
                    'collate' => 'utf8mb4_general_ci',
                ],
                'slaves' => [],
                'mysql_sample_db' => [
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
                ],
            ];
        }
        
        if (isset($sandbox_db['master'])) {
            $sandbox_db['master']['path'] = APP_PATH . 'etc/db.sqlite';
        }
        $sandbox_db['slaves'] = [];
        
        Env::set('db', $sandbox_db);
        
        $admin = Env::get('admin');
        if (empty($admin)) {
            Env::set('admin', Text::random_string(32));
        }
        
        $api_admin = Env::get('api_admin');
        if (empty($api_admin)) {
            Env::set('api_admin', Text::random_string(32));
        }
        
        /**@var \Weline\Framework\Module\Console\Module\Upgrade $moduleUpdate */
        $moduleUpdate = ObjectManager::getInstance(\Weline\Framework\Module\Console\Module\Upgrade::class);
        $moduleUpdate->execute([], []);
        
        # 触发系统升级后事件
        /**@var EventsManager $eventsManager */
        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        $eventsManager->dispatch('Framework_Setup::upgrade_after');
        
        $this->printing->success(__('系统识别到您初次安装！已为您初始化安装参数。'), __('安装'));
        $this->printing->success(__('您的后台入口地址密钥：%{1} ', Env::get('admin')), __('安装'));
        $this->printing->success(__('您的API后台入口地址密钥：%{1}', Env::get('api_admin')), __('安装'));
        $this->printing->success(__('使用server:start命令指定的地址访问网站，默认使用http://127.0.0.1:9981，例如:'), __('安装'));
        $this->printing->note(__('访问后台：%{1}/admin/login', 'http://127.0.0.1:9981/' . Env::get('api_admin')), __('安装'));
        $this->printing->note(__('访问后台API：%{1}', 'http://127.0.0.1:9981/' . Env::get('api_admin')), __('安装'));
        $this->printing->warning(__('默认使用sqlite作为开发数据库，若要修改数据库，请转到 %{1} 下的env.php按照数组键sample_db中的配置样本，修改db键即可。', APP_ETC_PATH), __('安装'));
        $this->printing->setup(__('由于您属于第一次安装，您可以使用命令行：php bin/w setup:upgrade , 然后使用：php bin/w server:start 快速开启本地开发服务器。'), __('安装'));
        
        # 设置环境用户
        Env::set('user', Env::user());
        
        # 设置安装文件
        file_put_contents(BP . 'setup/install.lock', date('Y-m-d H:i:s'));
    }
}
