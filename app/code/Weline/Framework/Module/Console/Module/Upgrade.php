<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Module\Console\Module;

use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\App\System;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Database\Model\ModelManager;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Module\Handle;
use Weline\Framework\Module\Helper\Data;
use Weline\Framework\Module\Model\Module;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Register\Register;

class Upgrade extends CommandAbstract
{
    /**
     * @var System
     */
    private System $system;

    /**
     * @var Data
     */
    private Data $data;

    public function __construct(
        Printing $printer,
        Data     $data,
        System   $system
    )
    {
        $this->printer = $printer;
        $this->system = $system;
        $this->data = $data;
    }

    /**
     * @DESC         |更新系统
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param array $args
     * @param array $data
     * @return mixed|void
     * @throws Exception
     * @throws \ReflectionException
     */
    public function execute(array $args = [], array $data = [])
    {
        /**@var EventsManager $eventsManager */
        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        $eventsManager->dispatch('Framework_Module::module_upgrade_before');
        $appoint = false;
        $argsModule = $args['module'] ?? [];
        if (is_string($argsModule)) {
            $argsModule = explode(' ', $argsModule);
        }
        if (isset($args['model'])) {
            $appoint = true;
            /**@var ModelManager $modelManager */
            $modelManager = ObjectManager::getInstance(ModelManager::class);
            /**@var Handle $module_handle */
            $module_handle = ObjectManager::getInstance(Handle::class);
            // 安装Setup信息
            $this->printer->note(__('指定安装Setup信息'));
            $modules = $module_handle->getModules();
            foreach ($modules as $module_name => $module) {
                if ($argsModule and !in_array($module_name, $argsModule)) {
                    continue;
                }
                if (is_file($module['base_path'] . '/register.php')) {
                    require $module['base_path'] . '/register.php';
                }
                $module_handle->setupInstall(new Module($module));
            }
            // 注册模型数据库信息
            $this->printer->note(__('指定注册模型数据库信息'));
            foreach ($modules as $module_name => $module) {
                if ($argsModule and !in_array($module_name, $argsModule)) {
                    continue;
                }
                $module_handle->setupInstall(new Module($module));
                $module_handle->setupModel(new Module($module));
            }
        }
        if (isset($args['route'])) {
            // 扫描模型注册代码
            list($origin_vendor_modules, $dependencyModules) = Register::getOriginModulesData();
            $appoint = true;
            // 注册路由信息
            /**@var Handle $module_handle */
            $module_handle = ObjectManager::getInstance(Handle::class);
            $modules = $module_handle->getModules();
            $this->printer->note(__('指定注册路由信息'));
            foreach ($modules as $module_name => $module) {
                if ($argsModule and !in_array($module_name, $argsModule)) {
                    continue;
                }
                // 注册模组
                $this->printer->note(__('1)注册模组'));
                if (is_file($module['base_path'] . '/register.php')) {
                    require $module['base_path'] . '/register.php';
                }
                $module_handle->registerRoute(new Module($module));
            }
        }
        if ($appoint) {
            $this->printer->success(__('委托部分更新已运行！'));
            return;
        }
        $i = 1;
        //        // 删除路由文件
        $this->printer->warning($i . '、路由更新...', '系统');
        $this->printer->warning('清除文件：');
        foreach (Env::router_files_PATH as $path) {
            $this->printer->warning($path);
            if (is_file($path)) {
                $data = $this->system->exec('rm -f ' . $path);
                if ($data) {
                    $this->printer->printList($data);
                }
            }
        }
        $i += 1;
        $this->printer->note($i . '、命令行更新...');
        /**@var \Weline\Framework\Console\Console\Command\Upgrade $commandManagerConsole */
        $commandManagerConsole = ObjectManager::getInstance(\Weline\Framework\Console\Console\Command\Upgrade::class);
        $commandManagerConsole->execute();

        $this->printer->note($i . '、事件清理...');
        /**@var $cacheManagerConsole \Weline\Framework\Cache\Console\Cache\Clear */
        $cacheManagerConsole = ObjectManager::getInstance(\Weline\Framework\Event\Console\Event\Cache\Clear::class);
        $cacheManagerConsole->execute();

        $i += 1;
        $this->printer->note($i . '、插件编译...');
        /**@var $cacheManagerConsole \Weline\Framework\Cache\Console\Cache\Clear */
        $cacheManagerConsole = ObjectManager::getInstance(\Weline\Framework\Plugin\Console\Plugin\Di\Compile::class);
        $cacheManagerConsole->execute();
        $i += 1;
        // 扫描代码
        $this->printer->note($i . '、清理模板缓存', '系统');
        list($origin_vendor_modules, $dependencyModules) = Register::getOriginModulesData();
        foreach ($origin_vendor_modules as $modules) {
            foreach ($modules as $module) {
                $tpl_dir = $module['base_path'] . DS . 'view' . DS . 'tpl';
                if (is_dir($tpl_dir)) {
                    $this->system->exec("rm -rf {$tpl_dir}");
                }
            }
        }
        $i += 1;
        $this->printer->note($i . '、清理缓存...');
        /**@var $cacheManagerConsole \Weline\Framework\Cache\Console\Cache\Flush */
        $cacheManagerConsole = ObjectManager::getInstance(\Weline\Framework\Cache\Console\Cache\Flush::class);
        $cacheManagerConsole->execute();
        $this->system->exec('rm -rf ' . BP . 'var' . DS . 'cache');

        $this->printer->note($i . '、module模块更新...');
        // 注册模块
        $all_modules = [];
        // 扫描模型注册代码
        list($origin_vendor_modules, $dependencyModules) = Register::getOriginModulesData();
        // 注册模组
        $this->printer->note(__('1)注册模组'));
        foreach ($dependencyModules as $module_name => $module) {
            if (is_file($module['register'])) {
                require $module['register'];
            }
        }
        $modules = Env::getInstance()->getModuleList();
        $no_modules = [];
        $diff_base_path_modules = [];
        foreach ($modules as $module) {
            if (!isset($dependencyModules[$module['name']])) {
                $no_modules[] = $module['name'];
            }
            $dependencyModule = $dependencyModules[$module['name']];
            if ($module['base_path'] != $dependencyModule['base_path']) {
                $diff_base_path_modules[] = $module['name'];
            }
        }
        if ($no_modules) {
            $this->system->exec(PHP_BINARY . ' php bin/w cache:clear -f');
            $this->printer->setup(__('发现网站正在进行搬迁，请再次运行php bin/w setup:upgrade命令！如果还有有问题请运行composer update后再次运行。'));
            $this->printer->setup(__('%modules 模块未找到(异常卸载)，如果模块确认需要卸载，请再次执行：php bin/w module:remove %modules', ['modules' => implode(' ', $no_modules)]));
            exit(0);
        }
        if ($diff_base_path_modules) {
            $this->system->exec(PHP_BINARY . ' php bin/w cache:clear -f');
            $this->printer->setup(__('发现网站正在进行搬迁，请再次运行php bin/w setup:upgrade命令！如果还有有问题请运行composer update后再次运行。'));
            $this->printer->setup(__('%modules 模块路径不一致(异常搬迁)，如果模块确认需要卸载，请再次执行：php bin/w module:remove %modules', ['modules' => implode(' ', $diff_base_path_modules)]));
            exit(0);
        }

        $dependencyModuleNames = array_keys($dependencyModules);
        foreach ($modules as $module) {
            if (!in_array($module['name'], $dependencyModuleNames)) {
                $this->printer->error(__('发现严重错误！请检查 %1 模块是否已经被删除，请手动确认并删除 %2 中关于此模块的信息！', [$module['name'], Env::path_MODULES_FILE]));
                $this->printer->note(__('输入以下信息选项，确认操作！'));
                $this->printer->note(__('1) 停止执行。手动确认模块信息并处理。【默认】'));
                $this->printer->note(__('2) 继续执行。（可能会出现不可预知的错误）'));
                $anser = $this->system->input();
                if ($anser == '1' || ($anser != '2')) {
                    $this->printer->setup(__('程序停止运行，请检查问题后继续执行！'));
                    exit(0);
                }
                $this->printer->setup(__('你选择了继续执行，可能会出现不可预知的错误。'));
                $total = 3;
                for ($i = 1; $i <= $total; $i++) {
                    echo __("%1 秒后程序继续执行 %2 ...\r", [$total, $i]);
                    // 模拟处理时间
                    usleep(1000000);
                }
            }
        }
        /**@var Handle $module_handle */
        $module_handle = ObjectManager::getInstance(Handle::class);
        // 安装Setup信息
        $this->printer->note(__('2)安装Setup信息'));
        $modules = $module_handle->getModules();
        foreach ($modules as $module_name => $module) {
            if (isset($module['upgrading']) and $module['upgrading']) {
                $module_handle->setupInstall(new Module($module));
            }
            if (isset($module['installing']) and $module['installing']) {
                $module_handle->setupInstall(new Module($module));
            }
        }
        // 注册模型数据库信息
        $this->printer->note(__('3)注册模型数据库信息'));
        foreach ($modules as $module_name => $module) {
            $module_handle->setupModel(new Module($module));
        }

        // 注册路由信息
        $this->printer->note(__('3)注册路由信息'));
        foreach ($modules as $module_name => $module) {
            $module_handle->registerRoute(new Module($module));
        }
        $this->printer->note('模块更新完毕！');
        $i += 1;
        $this->printer->note($i . '、收集模块信息', '系统');
        # 加载module中的助手函数
        $modules = Env::getInstance()->getActiveModules();
        $function_files_content = '';
        foreach ($modules as $module) {
            $global_file_pattern = $module['base_path'] . 'Global' . DS . '*.php';
            $global_files = glob($global_file_pattern);
            foreach ($global_files as $global_file) {
                # 读取文件内容 去除注释以及每个文件末尾的 '\?\>'结束符
                $function_files_content .= str_replace('?>', '', file_get_contents($global_file)) . PHP_EOL;
            }
        }
        # 写入文件
        $this->printer->warning('写入文件：');
        $this->printer->warning(Env::path_FUNCTIONS_FILE);
        file_put_contents(Env::path_FUNCTIONS_FILE, $function_files_content);

        $i += 1;

        // 清理其他
        /**@var EventsManager $eventsManager */
        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        $eventsManager->dispatch('Framework_Module::module_upgrade');
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '升级模块.' . PHP_EOL . ' 1. --mode[指定升级模式为数据库模型：支持的有model, route] --module Weline_Demo 升级指定模块.';
    }

    /**
     * ----------辅助函数--------------
     */
}
