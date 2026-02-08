<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Env\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Env\Api\EnvRequirementsCollectorInterface;
use Weline\Framework\Env\Api\Data\EnvRequirements;
use Weline\Framework\System\Helper\InstallData;

/**
 * 环境需求收集器实现
 * 
 * @DESC 从 Composer、InstallData、模块 env/requirements.php 收集环境需求
 */
class EnvRequirementsCollector implements EnvRequirementsCollectorInterface
{
    private InstallData $installData;

    public function __construct()
    {
        $this->installData = new InstallData();
    }

    /**
     * @inheritDoc
     */
    public function collect(bool $includeDisabled = false): EnvRequirements
    {
        $requirements = new EnvRequirements();

        // 1. 从 InstallData 收集（框架默认需求）
        $requirements->merge($this->collectFromInstallData());

        // 2. 从 Composer 收集
        $requirements->merge($this->collectFromComposer());

        // 3. 从所有已启用模块收集
        $requirements->merge($this->collectFromAllModules());

        return $requirements;
    }

    /**
     * @inheritDoc
     */
    public function collectFromComposer(): EnvRequirements
    {
        $requirements = new EnvRequirements();

        // 收集项目根 composer.json
        $rootComposer = BP . 'composer.json';
        if (is_file($rootComposer)) {
            $this->parseComposerJson($rootComposer, $requirements, 'project_root');
        }

        // 收集所有已启用模块的 composer.json
        $modules = Env::getInstance()->getModuleList();
        foreach ($modules as $moduleName => $module) {
            $modulePath = $module['base_path'] ?? '';
            if (empty($modulePath)) {
                continue;
            }

            $composerFile = $modulePath . 'composer.json';
            if (is_file($composerFile)) {
                $this->parseComposerJson($composerFile, $requirements, $moduleName);
            }
        }

        return $requirements;
    }

    /**
     * 解析 composer.json 文件
     */
    private function parseComposerJson(string $file, EnvRequirements $requirements, string $source): void
    {
        $content = file_get_contents($file);
        if ($content === false) {
            return;
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return;
        }

        $require = $data['require'] ?? [];
        $sourceData = [];

        foreach ($require as $package => $version) {
            // PHP 版本约束
            if ($package === 'php') {
                $requirements->setPhpVersion($version);
                $sourceData['php'] = $version;
                continue;
            }

            // 扩展约束 (ext-xxx)
            if (str_starts_with($package, 'ext-')) {
                $extension = substr($package, 4);
                $requirements->addExtension($extension);
                $sourceData['extensions'][] = $extension;
            }
        }

        if (!empty($sourceData)) {
            $requirements->addSource('composer:' . $source, $sourceData);
        }
    }

    /**
     * @inheritDoc
     */
    public function collectFromInstallData(): EnvRequirements
    {
        $requirements = new EnvRequirements();

        $envData = $this->installData->getData('env');
        if (!is_array($envData)) {
            return $requirements;
        }

        // 函数
        if (isset($envData['functions']) && is_array($envData['functions'])) {
            $requirements->addFunctions($envData['functions']);
        }

        // 模块（扩展）
        if (isset($envData['modules']) && is_array($envData['modules'])) {
            $requirements->addExtensions($envData['modules']);
        }

        $requirements->addSource('install_data', $envData);

        return $requirements;
    }

    /**
     * @inheritDoc
     */
    public function collectFromModule(string $moduleName, string $modulePath): EnvRequirements
    {
        $requirements = new EnvRequirements();

        $requirementsFile = $modulePath . 'env' . DIRECTORY_SEPARATOR . 'requirements.php';
        if (!is_file($requirementsFile)) {
            return $requirements;
        }

        $data = include $requirementsFile;
        if (!is_array($data)) {
            return $requirements;
        }

        // PHP 版本
        if (isset($data['php'])) {
            $requirements->setPhpVersion($data['php']);
        }

        // 扩展
        if (isset($data['extensions']) && is_array($data['extensions'])) {
            $requirements->addExtensions($data['extensions']);
        }

        // 函数
        if (isset($data['functions']) && is_array($data['functions'])) {
            $requirements->addFunctions($data['functions']);
        }

        // 复杂依赖项 items
        if (isset($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as $item) {
                // 为每个 item 添加模块信息
                $item['module'] = $moduleName;
                $item['module_path'] = $modulePath;
                $requirements->addItem($item);
            }
        }

        // 推荐扩展
        if (isset($data['recommended_extensions']) && is_array($data['recommended_extensions'])) {
            $requirements->addRecommendedExtensions($data['recommended_extensions']);
        }

        // 推荐函数
        if (isset($data['recommended_functions']) && is_array($data['recommended_functions'])) {
            $requirements->addRecommendedFunctions($data['recommended_functions']);
        }

        // 推荐复杂依赖项
        if (isset($data['recommended_items']) && is_array($data['recommended_items'])) {
            foreach ($data['recommended_items'] as $item) {
                $item['module'] = $moduleName;
                $item['module_path'] = $modulePath;
                $requirements->addRecommendedItem($item);
            }
        }

        $requirements->addSource('module:' . $moduleName, $data);

        return $requirements;
    }

    /**
     * @inheritDoc
     */
    public function collectFromAllModules(): EnvRequirements
    {
        $requirements = new EnvRequirements();

        $modules = Env::getInstance()->getModuleList();
        foreach ($modules as $moduleName => $module) {
            $modulePath = $module['base_path'] ?? '';
            if (empty($modulePath)) {
                continue;
            }

            $moduleRequirements = $this->collectFromModule($moduleName, $modulePath);
            $requirements->merge($moduleRequirements);
        }

        return $requirements;
    }
}
