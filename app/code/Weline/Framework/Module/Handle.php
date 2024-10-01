<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Module;

use Weline\Framework\Database\Model\ModelManager;
use Weline\Framework\Module\Dependency\Checker;
use Weline\Framework\Module\Model\Module;
use Weline\Framework\Register\RegisterInterface;
use Weline\Framework\System\File\Compress;
use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\App\System;
use Weline\Framework\Helper\HandleInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Module\Helper\Data;
use Weline\Framework\Module\Must\DataInterface;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Setup\Helper\Data as SetupHelper;
use Weline\Framework\Setup\Data\Setup as SetupData;
use Weline\Framework\Setup\Data\Context as SetupContext;

class Handle implements HandleInterface, RegisterInterface
{
    public const api_DIR = 'Api';// api特殊目录，注册api路由

    public const pc_DIR = 'Controller';// pc特殊目录，注册pc路由

    private Printing $printer;

    private array $modules;
    private array $old_modules;

    /**
     * @var Data
     */
    private Data $helper;

    /**
     * @var System
     */
    private System $system;

    /**
     * @var SetupData
     */
    private SetupData $setup_data;

    /**
     * @var SetupHelper
     */
    private SetupHelper $setup_helper;

    /**
     * @var SetupContext
     */
    private SetupContext $setup_context;

    /**
     * @var Compress
     */
    private Compress $compress;

    /**
     * Handle 初始函数...
     *
     * @param Data $helper
     * @param Printing $printer
     * @param System $system
     * @param SetupHelper $setup_helper
     * @param SetupData $setup_data
     * @param Compress $compress
     */
    public function __construct(
        Data        $helper,
        Printing    $printer,
        System      $system,
        Compress    $compress,
        SetupHelper $setup_helper,
        SetupData   $setup_data,
    )
    {
        $this->modules      = Env::getInstance()->getModuleList();
        $this->old_modules  = $this->modules;
        $this->helper       = $helper;
        $this->system       = $system;
        $this->printer      = $printer;
        $this->compress     = $compress;
        $this->setup_helper = $setup_helper;
        $this->setup_data   = $setup_data;
    }

    /**
     * @DESC         |移除应用
     *
     * 参数区：
     *
     * @param string $module - >getName()
     *
     * @throws \Weline\Framework\App\Exception
     */
    public function remove($module)
    {
        $this->printer->note(__('1、正在执行卸载脚本...'));
        $remove_script = $this->setup_helper->getSetupClass($module, \Weline\Framework\Setup\Data\DataInterface::type_REMOVE);
        if ($remove_script) {
            $remove_object = ObjectManager::getInstance($remove_script);

            $version       = $this->modules[$module]['version'] ?? '1.0.0';
            $setup_context = new \Weline\Framework\Setup\Data\Context($module, $version);
            $this->setup_data->setModuleContext($setup_context);
            $this->printer->note($remove_object->setup($this->setup_data, $setup_context));
        } else {
            $this->printer->warning('模块卸载脚本不存在，已跳过卸载脚本！', '卸载');
        }
        $this->printer->note('2、备份应用程序...');
        $module_path = $this->modules[$module]['base_path'] . DS;

        $zip = $this->compress->compression("{$module_path}", APP_CODE_PATH . $module, APP_CODE_PATH);

        $this->printer->note($zip);
        $this->printer->note('3、卸载应用代码...');

        $this->printer->note($module_path);
        $this->system->exec("rm $module_path -rf");
        $back_path = dirname($module_path);
        if ($this->system->getDirectoryObject()->is_empty(dirname($back_path))) {
            $this->system->exec("rm $back_path -rf");
        }
        $this->printer->success($module . __('模块已卸载完成！'));
    }

    /**
     * @DESC         |注册模块
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param string $type
     * @param string $module_name
     * @param array|string $param
     * @param string $version
     * @param string $description
     *
     * @return Module
     * @throws Exception
     * @throws \ReflectionException
     */
    public function register(string $type, string $module_name, array|string $param, string $version = '', string $description = '', array $dependencies = []): mixed
    {
        // 检测依赖

        foreach ($dependencies as $dependency) {
            if (!Checker::hasDependency($dependency)) {
                throw new Exception(__('%1 模组所需依赖尚 %2 未安装！请先安装模块后继续执行，或者删除此依赖。', [$module_name, $dependency]));
            }
        }

        if (DEV) {
            $this->printer->error($module_name . '：处理...', '开发');
        }
        if (!isset($param['base_path'])) {
            throw new Exception(__('尚未设置基础路径！%1', 'base_path'));
        }
        if (!isset($param['dir_path'])) {
            throw new Exception(__('尚未设置模组目录！%1', 'dir_path'));
        }
        if (!isset($param['module_name'])) {
            throw new Exception(__('尚未设置模组名！%1', 'module_name'));
        }
        # 检测位置
        $base_path = $param['base_path'] ?? $param;
        $position  = 'app';
        if (str_contains($base_path, VENDOR_PATH)) {
            $position = 'composer';
        }
        if (str_contains($base_path, VENDOR_PATH . DS . 'weline' . DS . 'framework' . DS) || str_contains($base_path, APP_CODE_PATH . DS . 'Weline' . DS . 'Framework' . DS)) {
            $position = 'framework';
        }
        if (str_contains($base_path, APP_CODE_PATH . 'Weline' . DS)) {
            $position = 'system';
        }
        if (str_contains($base_path, VENDOR_PATH . 'weline' . DS)) {
            $position = 'system';
        }

        // 模块路径
        // 模型管理器
        /**@var ModelManager $modelManager */
        $modelManager = ObjectManager::getInstance(ModelManager::class);
        // 检测配置文件完整
        $router   = '';
        $filepath = $param['base_path'] . DS . DataInterface::file_etc_Env;
        $env      = [];
        if (is_file($filepath)) {
            $env = (array)require $filepath;
            if (!isset($env['router'])) {
                // 如果文件不存在则读取模块名字作为router
                $env = $this->getEnv($module_name, $env);
            }
        }
        if (empty($env)) {
            $env = $this->getEnv($module_name, $env);
        }                                          // 如果文件不存在则读取模块名字作为router
        $router = strtolower($env['router'] ?: '');
        # 模块数据
        $module = new Module();
        $module->setStatus(true)
            ->setPosition($position)
            ->setName($module_name)
            ->setRouter($router)
            ->setNamespacePath(str_replace('_', '\\', $param['module_name']))
            ->setBasePath($param['base_path'])
            ->setPath($param['dir_path'])
            ->setVersion($version ?: '1.0.0')
            ->setDescription($description ?: '')
            ->setDependencies($dependencies);
        // 已经存在模块则更新
        if ($this->helper->isInstalled($this->modules, $module->getName())) {
            if ($this->helper->isDisabled($this->modules, $module->getName())) {
                $module->setStatus(false);
                $this->printer->warning(str_pad($module->getName(), 45) . __('已禁用！'));
            } else {
                $old_version = $this->modules[$module->getName()]['version']??'1.0.0';
                $module['upgrading'] = $this->helper->isUpgrade($old_version, $module->getVersion());
                $this->setupModel($module);
            }
        } else {
            $this->printer->setup("扩展{$module->getName()}安装中...");
            // 全新安装
            $module->setStatus(true);
            $module['installing']              = true;
            $this->modules[$module->getName()] = $module->getData();
            $this->printer->success(str_pad($module->getName(), 45) . __('已安装！'));
        }
        $this->modules[$module->getName()] = $module->getData();
        // 更新模块
        $this->helper->updateModules($this->modules);
        return $module;
    }

    /**
     * @DESC          # 方法描述
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/3/28 13:56
     * 参数区：
     *
     * @param Module $module
     * @param array $env
     *
     * @return array
     */
    public function getEnv($module_name, array $env): array
    {
        $env['router'] = strtolower($module_name);
        if (DEV) {
            $this->printer->note($module_name . '：模块没有设定路由别名，因此沿用模块名称作为路由入口！', '开发');
            $this->printer->warning('{http://demo.com/' . $module_name . '}', '示例');
            $this->printer->warning('设置路由别名请到：模块目录下的etc/env.php,修改return ["router"=>"' . $module_name . '"];', '提示');
        }
        return $env;
    }

    /**
     * @DESC          # 升级模型
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/1/8 0:05
     * 参数区：
     */
    public function setupModel(Module $module): Module
    {
        $setup_context = ObjectManager::make(SetupContext::class, [
            'module_name' => $module->getName(),
            'module_version' => $module->getVersion(),
            'module_description' => $module->getDescription()
        ], '__construct');
        $modelManager  = $this->getModuleManager();
        // 已经存在模块则更新
        if ($this->helper->isInstalled($this->old_modules, $module->getName())) {
            if ($this->helper->isDisabled($this->modules, $module->getName())) {
                $module->setStatus(false);
                $this->printer->warning(str_pad($module->getName(), 45) . __('已禁用！'));
            } else {
                // 是否更新模块：是则加载模块下的Setup模块下的文件进行更新
                $old_version = $this->old_modules[$module->getName()]['version']??'1.0.0';
                if ($this->helper->isUpgrade($old_version, $module->getVersion())) {
                    $this->printer->note(__('扩展 %1 升级中...', $module->getName()));
                    $this->printer->setup(__('升级 %1 到 %2', [$old_version, $module->getVersion()]));

                    # 升级模块的模型
                    $modelManager->update($module, $setup_context, 'upgrade');
                }
                # 升级模块的模型
                if (DEV) {
                    $this->printer->setup($module->getName() . '：模型升级...', '开发');
                    $modelManager->update($module, $setup_context, 'setup');
                    $this->printer->setup($module->getName() . '：模型升级完成...', '开发');
                }
                $this->printer->success(str_pad($module->getName(), 45) . __('已更新！'));
            }
        } else {
            $this->printer->setup("扩展{$module->getName()}安装中...");
            $this->printer->note('模型安装install...');
            # 模型安装install
            $modelManager->update($module, $setup_context, 'install');
            // 全新安装
            $module->setStatus(true);
            # 执行模型setup
            if (DEV) {
                $modelManager->update($module, $setup_context, 'setup');
            }
            $this->printer->success(str_pad($module->getName(), 45) . __('已安装！'));
        }
        return $module;
    }

    /**
     * @DESC          # 安装脚本
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/1/8 0:05
     * 参数区：
     */
    public function setupInstall(Module $module): Module
    {
        $setup_context   = ObjectManager::make(SetupContext::class, [
            'module_name' => $module->getName(),
            'module_version' => $module->getVersion(),
            'module_description' => $module->getDescription()
        ], '__construct');
        $setup_dir       = $module->getBasePath() . \Weline\Framework\Setup\Data\DataInterface::dir;
        $setup_namespace = $module->getNamespacePath() . '\\' . ucfirst(\Weline\Framework\Setup\Data\DataInterface::dir) . '\\';
        if (is_dir($setup_dir) && DEV) {
            $this->printer->setup($setup_dir . '：升级目录...', '开发');
        }
        # 检测是否禁用
        if ($this->helper->isDisabled($this->modules, $module->getName())) {
            $this->printer->warning(str_pad($module->getName(), 45) . __('已禁用！'));
            return $module;
        }
        # 更新
        if (isset($module['upgrading']) and $module['upgrading']) {
            // 是否更新模块：是则加载模块下的Setup模块下的文件进行更新
            $old_version = $this->old_modules[$module->getName()]['version']??'1.0.0';
            if ($this->helper->isUpgrade($old_version, $module->getVersion())) {
                $this->printer->note(__('扩展 %1 升级中...', $module->getName()));
                $this->printer->setup(__('升级 %1 到 %2', [$old_version, $module->getVersion()]));

                foreach (\Weline\Framework\Setup\Data\DataInterface::upgrade_FILES as $upgrade_FILE) {
                    $setup_file = $setup_dir . DS . $upgrade_FILE . '.php';
                    if (file_exists($setup_file)) {
                        $setup = ObjectManager::getInstance($setup_namespace . $upgrade_FILE);
                        $this->setup_data->setModuleContext($this->setup_context);
                        $result = $setup->setup($this->setup_data, $this->setup_context);
                        $this->printer->note("{$result}");
                    }
                }
            }
            $module->unsetData('upgrading');
            $this->modules[$module->getName()] = $module->getData();
            $this->printer->success(str_pad($module->getName(), 45) . __('已更新！'));
        }
        # 安装
        if (isset($module['installing']) and $module['installing']) {
            $this->printer->setup("扩展{$module->getName()}安装中...");
            $module->setStatus(true);
            // 安装模块：加载模块下的Setup模块下的安装文件进行安装
            foreach (\Weline\Framework\Setup\Data\DataInterface::install_FILES as $install_FILE) {
                $setup_file = $setup_dir . DS . $install_FILE . '.php';
                if (file_exists($setup_file)) {
                    $setup = ObjectManager::getInstance($setup_namespace . $install_FILE);
                    $this->setup_data->setModuleContext($setup_context);
                    $setup->setup($this->setup_data, $setup_context);
                }
            }
            $this->printer->success(str_pad($module->getName(), 45) . __('已安装！'));
            $module->unsetData('installing');
        }
//        // 更新模块
        $this->helper->updateModules($this->modules); #FIXME 解决重复刷新modules文件导致更新不成功问题
        return $module;
    }

    /**
     * @DESC          # 注册路由
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/1/8 0:08
     * 参数区：
     */
    public function registerRoute(Module $module): Module
    {
        // 已经存在模块则更新
        if ($this->helper->isDisabled($this->modules, $module->getName())) {
            $module->setStatus(false);
            $this->printer->warning(str_pad($module->getName(), 45) . __('已禁用！'));
        } else {
            // 更新路由
            if (DEV) {
                $this->printer->setup($module->getName() . '：更新路由...', '开发');
            }
            $this->helper->registerModuleRouter($this->modules, $module->getBasePath(), $module->getName(), $module->getRouter());
            if (DEV) {
                $this->printer->setup($module->getName() . '：更新路由完成...', '开发');
            }
            $this->printer->success(str_pad($module->getName(), 45) . __('已更新！'), __('路由更新：'));
        }
        return $module;
    }

    /**
     * @DESC          # 获取模型管理器
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/1/8 0:19
     * 参数区：
     * @return \Weline\Framework\Database\Model\ModelManager
     * @throws \ReflectionException
     * @throws \Weline\Framework\App\Exception
     */
    private function getModuleManager(): ModelManager
    {
        return ObjectManager::getInstance(ModelManager::class);
    }

    /**
     * @return array
     */
    public function getModules(): array
    {
        return $this->modules;
    }
}
