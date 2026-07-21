<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Plugin\Config;

use Weline\Framework\App\Env;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Exception\Core;
use Weline\Framework\Module\Service\ModuleScanService;
use Weline\Framework\Registry\Service\RegistryProgress;
use Weline\Framework\System\File\Scanner;
use Weline\Framework\Xml\Parser;

class PluginXmlReader extends \Weline\Framework\Config\Reader\XmlReader
{
    /**
     * @var CachePoolInterface
     */
    private CachePoolInterface $pluginCache;
    private ModuleScanService $moduleScanService;

    private const RELATIVE_PATH = 'etc' . DIRECTORY_SEPARATOR . 'plugin.xml';

    public function __construct(
        Scanner     $scanner,
        Parser      $parser,
                    $path = 'etc'.DS.'plugin.xml',
        $moduleScanService = null
    )
    {
        parent::__construct($scanner, $parser, $path);
        $this->pluginCache = w_cache('plugin');
        $this->moduleScanService = $moduleScanService instanceof ModuleScanService
            ? $moduleScanService
            : new ModuleScanService($scanner);
    }

    /**
     * 获取 plugin.xml 文件列表：仅激活模块，用 base_path + etc/plugin.xml 直接定位，不扫描目录。
     *
     * @param \Closure|null $callback 保留签名兼容，此处未使用
     * @return array<string, string> 模块名 => 文件绝对路径
     */
    public function getFileList(null|\Closure $callback = null): array
    {
        $result = [];
        $modules = Env::getInstance()->getActiveModules();
        $order = ['app' => 0, 'framework' => 1, 'system' => 2, 'composer' => 3];
        uasort($modules, static fn($a, $b) => ($order[$a['position'] ?? 'composer'] ?? 4) <=> ($order[$b['position'] ?? 'composer'] ?? 4));
        $totalModules = count($modules);
        $moduleIndex = 0;
        foreach ($modules as $module) {
            $moduleIndex++;
            $name = $module['name'] ?? '';
            $basePath = rtrim($module['base_path'] ?? '', '/\\');
            if ($name === '' || $basePath === '') {
                continue;
            }
            RegistryProgress::module('Plugin XML locate module', $moduleIndex, $totalModules, (string)$name, 'check etc/plugin.xml');
            $filePath = $this->moduleScanService->resolveFile($basePath, self::RELATIVE_PATH);
            if ($filePath !== null) {
                $result[$name] = $filePath;
                RegistryProgress::module('Plugin XML locate module', $moduleIndex, $totalModules, (string)$name, 'found');
            } else {
                RegistryProgress::module('Plugin XML locate module', $moduleIndex, $totalModules, (string)$name, 'missing');
            }
        }
        return $callback ? $callback($result) : $result;
    }

    /**
     * 读取拦截器配置：仅激活模块，base_path 直接定位，逐文件解析合并，降低内存占用。
     *
     * @throws Core
     */
    public function read(): array
    {
        if ($plugin = $this->pluginCache->getCustom('plugin')) {
            return $plugin;
        }
        $plugin_interceptors_list = [];
        $env = \Weline\Framework\App\Env::getInstance();
        $fileList = $this->getFileList();
        RegistryProgress::count('Plugin XML parse', count($fileList), 'plugin.xml files');
        $fileIndex = 0;
        $totalFiles = count($fileList);
        foreach ($fileList as $moduleName => $filePath) {
            $fileIndex++;
            if (empty($moduleName) || !$env->getModuleStatus($moduleName)) {
                continue;
            }
            RegistryProgress::module('Plugin XML parse module', $fileIndex, $totalFiles, (string)$moduleName, 'start');
            $config = $this->parser->parseFile($filePath);
            $module_and_file = $moduleName . '::' . $filePath;
            $module_plugin_interceptors = $this->processOnePluginConfig($config, $moduleName, $module_and_file);
            $plugin_interceptors_list[$module_and_file] = $module_plugin_interceptors;
            unset($config);
            RegistryProgress::module(
                'Plugin XML parse module',
                $fileIndex,
                $totalFiles,
                (string)$moduleName,
                'done plugins=' . count($module_plugin_interceptors)
            );
            unset($module_plugin_interceptors);
        }
        $this->pluginCache->setCustom('plugin', $plugin_interceptors_list);
        return $plugin_interceptors_list;
    }

    /**
     * 处理单个 plugin.xml 解析结果，返回该文件对应的拦截器数组。
     *
     * @throws Core
     */
    private function processOnePluginConfig(array $config, string $moduleName, string $module_and_file): array
    {
        if (
            !isset($config['config']['_attribute']['noNamespaceSchemaLocation'])
            || 'urn:Weline_Framework::Plugin/etc/xsd/plugin.xsd' !== $config['config']['_attribute']['noNamespaceSchemaLocation']
        ) {
            die(__('%{1} 拦截器必须设置：noNamespaceSchemaLocation="urn:Weline_Framework::Plugin/etc/xsd/plugin.xsd"', [$module_and_file]));
        }
        $module_plugin_interceptors = [];
        $pluginNodes = $config['config']['_value']['plugin'] ?? null;
        if ($pluginNodes === null) {
            return $module_plugin_interceptors;
        }
        $firstKey = array_key_first($pluginNodes);
        $pluginList = ($firstKey !== null && is_int($firstKey)) ? $pluginNodes : [$pluginNodes];
        foreach ($pluginList as $plugin) {
            if (!isset($plugin['_attribute']['name'])) {
                throw new Core(__('%{1} 拦截器Plugin未指定name属性：<plugin name="pluginName">...</plugin>', [$module_and_file]));
            }
            if (!isset($plugin['_attribute']['class'])) {
                throw new Core(__('%{1} 拦截器Plugin未指定class属性：<plugin class="pluginClass">...</plugin>', [$module_and_file]));
            }
            $pluginName = $plugin['_attribute']['name'];
            $pluginClass = $plugin['_attribute']['class'];
            $value = $plugin['_value'] ?? [];
            $valueFirstKey = array_key_first($value);
            if ($valueFirstKey !== null && is_int($valueFirstKey)) {
                foreach ($value as $item_interceptor) {
                    $module_plugin_interceptors[$pluginName][] = $item_interceptor;
                }
            } else {
                $this->collectInterceptors($value['interceptor'] ?? null, $pluginName, $pluginClass, $moduleName, $module_plugin_interceptors, $module_and_file);
            }
        }
        return $module_plugin_interceptors;
    }

    /**
     * 将 interceptor 节点（单个或多个）合并到 $module_plugin_interceptors[$pluginName]。
     *
     * @throws Core
     */
    private function collectInterceptors(
        $interceptors,
        string $pluginName,
        string $pluginClass,
        string $moduleName,
        array &$module_plugin_interceptors,
        string $module_and_file
    ): void {
        if ($interceptors === null) {
            return;
        }
        if (!is_array($interceptors)) {
            return;
        }
        $firstKey = array_key_first($interceptors);
        if ($firstKey !== null && is_int($firstKey)) {
            $list = $interceptors;
        } else {
            $list = isset($interceptors['_attribute']) ? [$interceptors] : [];
        }
        foreach ($list as $item) {
            if (!isset($item['_attribute']['name'], $item['_attribute']['instance'])) {
                throw new Core(__('%{1} 拦截器Interceptor没有设置name/instance属性：<interceptor name="..." instance="..."/>', [$module_and_file]));
            }
            $pluginData = $item['_attribute'];
            $pluginData['module'] = $moduleName;
            $pluginData['module_status'] = true;
            $module_plugin_interceptors[$pluginName][] = ['class' => $pluginClass, 'plugins' => $pluginData];
        }
    }
    
    /**
     * 读取指定模块的拦截器配置：仅从 getFileList 中取对应模块文件逐文件解析，不加载全部配置。
     *
     * @param array $moduleNames 模块名列表
     * @return array
     * @throws Core
     */
    public function readForModules(array $moduleNames): array
    {
        $plugin_interceptors_list = [];
        $env = \Weline\Framework\App\Env::getInstance();
        $fileList = $this->getFileList();
        RegistryProgress::count('Plugin XML incremental parse', count($fileList), 'known plugin.xml files');
        $fileIndex = 0;
        $totalFiles = count($fileList);
        foreach ($fileList as $moduleName => $filePath) {
            $fileIndex++;
            if (!in_array($moduleName, $moduleNames, true) || empty($moduleName) || !$env->getModuleStatus($moduleName)) {
                continue;
            }
            RegistryProgress::module('Plugin XML parse module', $fileIndex, $totalFiles, (string)$moduleName, 'start');
            $config = $this->parser->parseFile($filePath);
            $module_and_file = $moduleName . '::' . $filePath;
            $plugin_interceptors_list[$module_and_file] = $this->processOnePluginConfig($config, $moduleName, $module_and_file);
            unset($config);
            RegistryProgress::module(
                'Plugin XML parse module',
                $fileIndex,
                $totalFiles,
                (string)$moduleName,
                'done plugins=' . count($plugin_interceptors_list[$module_and_file])
            );
        }
        return $plugin_interceptors_list;
    }
}
