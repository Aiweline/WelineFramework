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
        $eventsManager->dispatch('Weline_Framework_Module::module_upgrade_before');
        $appoint = false;
        // 支持 --module 和 -m 两种写法，以及位置参数
        $argsModule = $args['module'] ?? $args['m'] ?? [];
        if (is_string($argsModule)) {
            $argsModule = explode(' ', $argsModule);
        }
        
        // 如果没有通过 --module 或 -m 指定模块，检查位置参数
        if (empty($argsModule)) {
            // 检查是否有位置参数（非选项参数）
            $positionalArgs = [];
            foreach ($args as $key => $value) {
                // 如果是数字键且不是选项参数，则认为是位置参数
                // 排除命令本身（通常是第一个位置参数）
                if (is_numeric($key) && !str_starts_with($value, '-') && $key > 0) {
                    $positionalArgs[] = $value;
                }
            }
            if (!empty($positionalArgs)) {
                $argsModule = $positionalArgs;
            }
        }
        
        // 如果指定了模块，显示提示信息
        if ($argsModule) {
            $this->printer->setup(__('指定模块升级模式：仅升级 %{1}', [implode(', ', $argsModule)]));
        }
        
        // 检查是否指定了部分更新模式
        $doModel = isset($args['model']);
        $doRoute = isset($args['route']);
        $appoint = $doModel || $doRoute;
        
        if ($doModel) {
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
        
        if ($doRoute) {
            // 扫描模型注册代码
            list($origin_vendor_modules, $dependencyModules) = Register::getOriginModulesData();
            // 注册路由信息
            /**@var Handle $module_handle */
            $module_handle = ObjectManager::getInstance(Handle::class);
            $modules = $module_handle->getModules();
            $this->printer->note(__('指定注册路由信息'));
            
            // 启用批量写入模式，提高性能
            /**@var \Weline\Framework\Router\Helper\Data $routerHelper */
            $routerHelper = ObjectManager::getInstance(\Weline\Framework\Router\Helper\Data::class);
            $routerHelper->enableBatchMode();
            
            // 如果指定了模块，先清除指定模块的旧路由
            if ($argsModule) {
                $this->printer->note(__('清除指定模块的旧路由...'));
                foreach (Env::router_files_PATH as $routerFilePath) {
                    try {
                        $routerHelper->clearModuleRouters($routerFilePath, $argsModule);
                    } catch (\Exception $e) {
                        $this->printer->warning(__('清除路由文件 %{1} 时出错：%{2}', [$routerFilePath, $e->getMessage()]));
                    }
                }
            }
            
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
            
            // 所有模块路由注册完成后，一次性写入所有路由文件
            $this->printer->note(__('正在写入路由文件...'));
            $routerHelper->flushBatchRouters();
            $this->printer->success(__('路由文件写入完成！'));
        }
        
        if ($appoint) {
            $this->printer->success(__('委托部分更新已运行！'));
            return;
        }
        
        $i = 1;
        // 如果没有指定模块，执行全局清理操作
        if (!$argsModule) {
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
            /**@var \Weline\Framework\Event\Console\Event\Cache\Clear $cacheManagerConsole */
            $cacheManagerConsole = ObjectManager::getInstance(\Weline\Framework\Event\Console\Event\Cache\Clear::class);
            $cacheManagerConsole->execute();

            $i += 1;
            $this->printer->note($i . '、插件编译...');
            /**@var \Weline\Framework\Plugin\Console\Plugin\Di\Compile $cacheManagerConsole */
            $cacheManagerConsole = ObjectManager::getInstance(\Weline\Framework\Plugin\Console\Plugin\Di\Compile::class);
            $cacheManagerConsole->execute();
            $i += 1;
        } else {
            $this->printer->note('指定模块升级，跳过全局清理操作...');
        }
        
        // 扫描代码
        $this->printer->note($i . '、清理模板缓存', '系统');
        list($origin_vendor_modules, $dependencyModules) = Register::getOriginModulesData();
        foreach ($origin_vendor_modules as $modules) {
            foreach ($modules as $module) {
                if ($argsModule and !in_array($module['name'], $argsModule)) {
                    continue;
                }
                $tpl_dir = $module['base_path'] . DS . 'view' . DS . 'tpl';
                if (is_dir($tpl_dir)) {
                    $this->system->exec("rm -rf {$tpl_dir}");
                }
            }
        }
        
        if (!$argsModule) {
            $i += 1;
            $this->printer->note($i . '、清理缓存...');
            /**@var \Weline\Framework\Cache\Console\Cache\Flush $cacheManagerConsole */
            $cacheManagerConsole = ObjectManager::getInstance(\Weline\Framework\Cache\Console\Cache\Flush::class);
            $cacheManagerConsole->execute();
            $this->system->exec('rm -rf ' . BP . 'var' . DS . 'cache');
        } elseif ($argsModule) {
            // 指定模块时，清理指定模块的缓存
            $i += 1;
            $this->printer->note($i . '、清理指定模块缓存...');
            foreach ($argsModule as $moduleName) {
                $this->printer->note(__('清理模块 %{1} 的缓存...', [$moduleName]));
                // 清理模块特定的缓存目录
                $moduleCacheDirs = [
                    BP . 'var' . DS . 'cache' . DS . strtolower(str_replace('_', DS, $moduleName)),
                    BP . 'generated' . DS . 'code' . DS . str_replace('_', '\\', $moduleName),
                    BP . 'generated' . DS . 'metadata' . DS . str_replace('_', '\\', $moduleName),
                ];
                foreach ($moduleCacheDirs as $cacheDir) {
                    if (is_dir($cacheDir)) {
                        $this->system->exec('rm -rf ' . $cacheDir);
                        $this->printer->success(__('已清理：%{1}', [$cacheDir]));
                    }
                }
            }
            // 清理模板缓存（已在前面处理，这里只是确保）
            $this->printer->note(__('指定模块缓存清理完成'));
        }

        $this->printer->note($i . '、module模块更新...');
        // 注册模块
        $all_modules = [];
        // 扫描模型注册代码
        list($origin_vendor_modules, $dependencyModules) = Register::getOriginModulesData();
        // 注册模组
        $this->printer->note(__('1)注册模组'));
        foreach ($dependencyModules as $module_name => $module) {
            if ($argsModule and !in_array($module_name, $argsModule)) {
                continue;
            }
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
            $dependencyModule = $dependencyModules[$module['name']]??[];
            $moduleBasePath = $module['base_path'] ?? '';
            $dependencyBasePath = $dependencyModule['base_path'] ?? '';
            if ($moduleBasePath != $dependencyBasePath) {
                $diff_base_path_modules[] = $module['name'];
            }
        }
        if ($no_modules) {
            $this->system->exec(PHP_BINARY . ' bin/w cache:clear -f');
            $this->printer->setup(__('发现网站正在进行搬迁，请再次运行php bin/w setup:upgrade命令！如果还有有问题请运行composer update后再次运行。'));
            $this->printer->setup(__('%{modules} 模块未找到(异常卸载)，如果模块确认需要卸载，请再次执行：php bin/w module:remove %{modules}', ['modules' => implode(' ', $no_modules)]));
            // 抛出异常而不是exit，让外层的try-catch-finally可以正确处理并释放锁
            throw new Exception(__('模块检查失败：%{modules} 模块未找到(异常卸载)，请先执行 php bin/w module:remove %{modules} 卸载这些模块', ['modules' => implode(' ', $no_modules)]));
        }
        if ($diff_base_path_modules) {
            $this->system->exec(PHP_BINARY . ' bin/w cache:clear -f');
            $this->printer->setup(__('发现网站正在进行搬迁，请再次运行php bin/w setup:upgrade命令！如果还有有问题请运行composer update后再次运行。'));
            $this->printer->setup(__('%{modules} 模块路径不一致(异常搬迁)，如果模块确认需要卸载，请再次执行：php bin/w module:remove %{modules}', ['modules' => implode(' ', $diff_base_path_modules)]));
            // 抛出异常而不是exit，让外层的try-catch-finally可以正确处理并释放锁
            throw new Exception(__('模块检查失败：%{modules} 模块路径不一致(异常搬迁)，请先执行 php bin/w module:remove %{modules} 卸载这些模块', ['modules' => implode(' ', $diff_base_path_modules)]));
        }

        $dependencyModuleNames = array_keys($dependencyModules);
        foreach ($modules as $module) {
            if (!in_array($module['name'], $dependencyModuleNames)) {
                $this->printer->error(__('发现严重错误！请检查 %{1} 模块是否已经被删除，请手动确认并删除 %{2} 中关于此模块的信息！', [$module['name'], Env::path_MODULES_FILE]));
                $this->printer->note(__('输入以下信息选项，确认操作！'));
                $this->printer->note(__('1) 停止执行。手动确认模块信息并处理。【默认】'));
                $this->printer->note(__('2) 继续执行。（可能会出现不可预知的错误）'));
                $anser = $this->system->input();
                if ($anser == '1' || ($anser != '2')) {
                    $this->printer->setup(__('程序停止运行，请检查问题后继续执行！'));
                    // 抛出异常而不是exit，让外层的try-catch-finally可以正确处理并释放锁
                    throw new Exception(__('用户选择停止执行：模块 %{1} 已被删除，请手动确认并删除 %{2} 中关于此模块的信息', [$module['name'], Env::path_MODULES_FILE]));
                }
                $this->printer->setup(__('你选择了继续执行，可能会出现不可预知的错误。'));
                $total = 3;
                for ($i = 1; $i <= $total; $i++) {
                    echo __("%{1} 秒后程序继续执行 %{2} ...\r", [$total, $i]);
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
            if ($argsModule and !in_array($module_name, $argsModule)) {
                continue;
            }
            if (isset($module['upgrading']) and $module['upgrading']) {
                try {
                    $module_handle->setupUpgrade(new Module($module));
                } catch (Exception $exception) {
                    $this->printer->error(__('模块 %{1} 升级失败：%{2}', [$module_name, $exception->getMessage()]));
                    $this->printer->error(__('错误详情：%{1}', [$exception->getTraceAsString()]));
                    // 抛出异常而不是die，让外层的try-catch-finally可以正确处理
                    throw new Exception(__('模块 %{1} 升级失败：%{2}', [$module_name, $exception->getMessage()]), 0, $exception);
                }
            }
            if (isset($module['installing']) and $module['installing']) {
                try {
                    $module_handle->setupInstall(new Module($module));
                } catch (Exception $exception) {
                    $this->printer->error(__('模块 %{1} 安装失败：%{2}', [$module_name, $exception->getMessage()]));
                    $this->printer->error(__('错误详情：%{1}', [$exception->getTraceAsString()]));
                    // 抛出异常而不是die，让外层的try-catch-finally可以正确处理
                    throw new Exception(__('模块 %{1} 安装失败：%{2}', [$module_name, $exception->getMessage()]), 0, $exception);
                }
            }
        }
        // 注册模型数据库信息
        $this->printer->note(__('3)注册模型数据库信息'));
        foreach ($modules as $module_name => $module) {
            if ($argsModule and !in_array($module_name, $argsModule)) {
                continue;
            }
            try {
                $module_handle->setupModel(new Module($module));
            } catch (Exception $exception) {
                $this->printer->error(__('模块 %{1} 模型注册失败：%{2}', [$module_name, $exception->getMessage()]));
                if (DEV) {
                    $this->printer->error(__('错误详情：%{1}', [$exception->getTraceAsString()]));
                }
                // 抛出异常而不是die，让外层的try-catch-finally可以正确处理
                throw new Exception(__('模块 %{1} 模型注册失败：%{2}', [$module_name, $exception->getMessage()]), 0, $exception);
            }
        }

        // 注册路由信息
        $this->printer->note(__('3)注册路由信息'));
        
        // 启用批量写入模式，提高性能
        /**@var \Weline\Framework\Router\Helper\Data $routerHelper */
        $routerHelper = ObjectManager::getInstance(\Weline\Framework\Router\Helper\Data::class);
        $routerHelper->enableBatchMode();
        
        // 如果指定了模块，先清除指定模块的旧路由
        if ($argsModule) {
            $this->printer->note(__('清除指定模块的旧路由...'));
            foreach (Env::router_files_PATH as $routerFilePath) {
                try {
                    $routerHelper->clearModuleRouters($routerFilePath, $argsModule);
                } catch (\Exception $e) {
                    $this->printer->warning(__('清除路由文件 %{1} 时出错：%{2}', [$routerFilePath, $e->getMessage()]));
                }
            }
        }
        
        // 注册所有模块的路由（批量模式下会缓存，不立即写入）
        foreach ($modules as $module_name => $module) {
            if ($argsModule and !in_array($module_name, $argsModule)) {
                continue;
            }
            try {
                $module_handle->registerRoute(new Module($module));
            } catch (Exception $exception) {
                $this->printer->error(__('模块 %{1} 路由注册失败：%{2}', [$module_name, $exception->getMessage()]));
                if (DEV) {
                    $this->printer->error(__('错误详情：%{1}', [$exception->getTraceAsString()]));
                }
                // 抛出异常而不是die，让外层的try-catch-finally可以正确处理
                throw new Exception(__('模块 %{1} 路由注册失败：%{2}', [$module_name, $exception->getMessage()]), 0, $exception);
            }
        }
        
        // 所有模块路由注册完成后，一次性写入所有路由文件
        try {
            $this->printer->note(__('正在写入路由文件...'));
            $routerHelper->flushBatchRouters();
            $this->printer->success(__('路由文件写入完成！'));
        } catch (Exception $exception) {
            $this->printer->error(__('路由文件写入失败：%{1}', [$exception->getMessage()]));
            if (DEV) {
                $this->printer->error(__('错误详情：%{1}', [$exception->getTraceAsString()]));
            }
            // 抛出异常而不是die，让外层的try-catch-finally可以正确处理
            throw new Exception(__('路由文件写入失败：%{1}', [$exception->getMessage()]), 0, $exception);
        }
        if ($argsModule) {
            $this->printer->note(__('指定模块 %{1} 更新完毕！', [implode(', ', $argsModule)]));
        } else {
            $this->printer->note('模块更新完毕！');
        }
        $i += 1;
        $this->printer->note($i . '、收集模块信息', '系统');
        # 加载module中的助手函数
        $modules = Env::getInstance()->getActiveModules();
        $function_files_content = '';
        
        // 文件头部：必须包含 <?php 和 declare(strict_types=1);
        $file_header = "<?php" . PHP_EOL . "declare(strict_types=1);" . PHP_EOL;
        
        // 如果指定了模块，先读取现有文件内容（保留其他模块的函数）
        $existing_content = '';
        if ($argsModule && is_file(Env::path_FUNCTIONS_FILE)) {
            $existing_content = file_get_contents(Env::path_FUNCTIONS_FILE);
            // 移除文件头部（如果存在），保留实际内容
            $existing_content = preg_replace('/^<\?php\s*declare\(strict_types=1\);\s*/i', '', $existing_content);
            // 尝试移除指定模块的旧函数（通过注释标记识别）
            // 注意：这是一个简化的实现，假设函数文件中有模块标记注释
            foreach ($argsModule as $moduleName) {
                // 移除以 "// Module: $moduleName" 开头的块直到下一个 "// Module:" 或文件结束
                $pattern = '/\/\/\s*Module:\s*' . preg_quote($moduleName, '/') . '.*?(?=\/\/\s*Module:|$)/s';
                $existing_content = preg_replace($pattern, '', $existing_content);
            }
            // 清理多余的空行
            $existing_content = preg_replace('/\n{3,}/', "\n\n", $existing_content);
            $existing_content = trim($existing_content);
        }
        
        foreach ($modules as $module) {
            if ($argsModule and !in_array($module['name'], $argsModule)) {
                continue;
            }
            $global_file_pattern = $module['base_path'] . 'Global' . DS . '*.php';
            $global_files = glob($global_file_pattern);
            if (!empty($global_files)) {
                // 添加模块标记注释（放在 declare 之后）
                $function_files_content .= PHP_EOL . '// Module: ' . $module['name'] . PHP_EOL;
                foreach ($global_files as $global_file) {
                    # 读取文件内容 去除注释以及每个文件末尾的 '\?\>'结束符
                    $file_content = file_get_contents($global_file);
                    // 移除文件中的 <?php 和 declare 语句（如果存在），因为已经在文件头部统一处理
                    $file_content = preg_replace('/^<\?php\s*/i', '', $file_content);
                    $file_content = preg_replace('/declare\(strict_types=1\);\s*/i', '', $file_content);
                    $file_content = str_replace('?>', '', $file_content);
                    $function_files_content .= trim($file_content) . PHP_EOL;
                }
            }
        }
        
        // 写入文件
        if ($argsModule && $function_files_content) {
            # 合并现有内容和新内容，确保文件头部正确
            $final_content = $file_header;
            if ($existing_content) {
                $final_content .= $existing_content . PHP_EOL;
            }
            $final_content .= trim($function_files_content);
            $this->printer->warning('写入文件：');
            $this->printer->warning(Env::path_FUNCTIONS_FILE);
            file_put_contents(Env::path_FUNCTIONS_FILE, $final_content);
        } elseif (!$argsModule) {
            # 写入文件（完整升级，覆盖所有内容），确保文件头部正确
            $final_content = $file_header;
            if ($function_files_content) {
                $final_content .= trim($function_files_content);
            }
            $this->printer->warning('写入文件：');
            $this->printer->warning(Env::path_FUNCTIONS_FILE);
            file_put_contents(Env::path_FUNCTIONS_FILE, $final_content);
        }

        $i += 1;

        // 清理其他
        /**@var EventsManager $eventsManager */
        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        $eventsManager->dispatch('Weline_Framework_Module::module_upgrade');
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '升级模块.' . PHP_EOL . ' 1. --mode[指定升级模式为数据库模型：支持的有model, route] --module Weline_Demo 升级指定模块.';
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'module:upgrade',
            '升级模块系统，包括数据库模型、路由等',
            [
                '--model' => '仅升级数据库模型',
                '--route' => '仅升级路由',
                '-m, --module=<模块名>' => '升级指定模块（例如：Weline_Demo）',
                '-h, --help' => '显示帮助信息',
            ],
            [],
            [
                '升级所有模块' => 'php bin/w module:upgrade',
                '仅升级数据库模型' => 'php bin/w module:upgrade --model',
                '仅升级路由' => 'php bin/w module:upgrade --route',
                '升级指定模块（位置参数）' => 'php bin/w module:upgrade Weline_Demo',
                '升级指定模块（长选项）' => 'php bin/w module:upgrade --module Weline_Demo',
                '升级指定模块（短选项）' => 'php bin/w module:upgrade -m Weline_Demo',
                '升级指定模块的模型' => 'php bin/w module:upgrade --model -m Weline_Demo',
            ],
            'php bin/w module:upgrade [--model|--route] [-m|--module=<模块名>]'
        );
    }

    /**
     * ----------辅助函数--------------
     */
}
