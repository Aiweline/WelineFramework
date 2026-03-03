<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Plugin\Config;

use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Exception\Core;
use Weline\Framework\System\File\Scanner;
use Weline\Framework\Xml\Parser;

class PluginXmlReader extends \Weline\Framework\Config\Reader\XmlReader
{
    /**
     * @var CachePoolInterface
     */
    private CachePoolInterface $pluginCache;

    public function __construct(
        Scanner     $scanner,
        Parser      $parser,
                    $path = 'etc'.DS.'plugin.xml'
    )
    {
        parent::__construct($scanner, $parser, $path);
        $this->pluginCache = w_cache('plugin');
    }

    /**
     * @DESC         |读取拦截器配置
     *
     * 开发者模式读取真实配置
     * 非开发者模式有缓存则读取缓存
     * 参数区：
     *
     * @return mixed
     * @throws Core
     */
    public function read(): array
    {
        if ($plugin = $this->pluginCache->get('plugin')) {
            return $plugin;
        }
        $configs = parent::read();
        // 合并掉所有相同名字的拦截器的观察者，方便获取
        $plugin_interceptors_list = [];
        
        $env = \Weline\Framework\App\Env::getInstance();
        
        foreach ($configs as $module_and_file => $config) {
            // 提取模块名并检查模块状态
            $moduleName = explode('::', $module_and_file)[0] ?? '';
            if (empty($moduleName) || !$env->getModuleStatus($moduleName)) {
                // 跳过禁用的模块
                continue;
            }
            
            $module_plugin_interceptors = [];
            if (
                !isset($config['config']['_attribute']['noNamespaceSchemaLocation']) ||
                ('urn:Weline_Framework::Plugin/etc/xsd/plugin.xsd' !== $config['config']['_attribute']['noNamespaceSchemaLocation'])
            ) {
                die(__('%{1} 拦截器必须设置：noNamespaceSchemaLocation="urn:Weline_Framework::Plugin/etc/xsd/plugin.xsd"', [$module_and_file]));
            }
            // 多个值
            if (is_integer(array_key_first($config['config']['_value']['plugin']))) {
                foreach ($config['config']['_value']['plugin'] as $plugin) {
                    if (!isset($plugin['_attribute']['name'])) {
                        throw new Core(__('%{1} 拦截器Plugin未指定name属性：<plugin name="pluginName">...</plugin>', [$module_and_file]));
                    }
                    if (!isset($plugin['_attribute']['class'])) {
                        throw new Core(__('%{1} 拦截器Plugin未指定class属性：<plugin class="pluginClass">...</plugin>', [$module_and_file]));
                    }
                    // 多个值
                    if (is_integer(array_key_first($plugin['_value']))) {
                        foreach ($plugin['_value'] as $item_interceptor) {
                            $module_plugin_interceptors[$plugin['_attribute']['name']][] = $item_interceptor;
                        }
                    } else {
                        // interceptor有多个值的情况
                        if (is_array($plugin['_value']['interceptor'])) {
                            foreach ($plugin['_value']['interceptor'] as $item) {
                                if (!isset($item['_attribute'])) {
                                    throw new Core(__('%{1} 拦截器Interceptor没有设置属性：<interceptor name="interceptorName" instance="instanceClass" disabled="false" sort="0"/>', [$module_and_file]));
                                }
                                if (!isset($item['_attribute']['name'])) {
                                    throw new Core(__('%{1} 拦截器Interceptor没有设置name属性：<interceptor name="interceptorName" instance="instanceClass" disabled="false" sort="0"/>', [$module_and_file]));
                                }
                                if (!isset($item['_attribute']['instance'])) {
                                    throw new Core(__('%{1} 拦截器Interceptor没有设置instance属性：<interceptor name="interceptorName" instance="instanceClass" disabled="false" sort="0"/>', [$module_and_file]));
                                }
                                $pluginData = $item['_attribute'];
                                $pluginData['module'] = $moduleName;
                                $pluginData['module_status'] = true; // 已通过状态检查
                                $module_plugin_interceptors[$plugin['_attribute']['name']][] = ['class' => $plugin['_attribute']['class'], 'plugins' => $pluginData];
                            }
                        } else {
                            if (!isset($plugin['_value']['interceptor']['_attribute'])) {
                                throw new Core(__('%{1} 拦截器Interceptor没有设置属性：<interceptor name="interceptorName" instance="instanceClass" disabled="false" sort="0"/>', [$module_and_file]));
                            }
                            if (!isset($plugin['_value']['interceptor']['_attribute']['name'])) {
                                throw new Core(__('%{1} 拦截器Interceptor没有设置name属性：<interceptor name="interceptorName" instance="instanceClass" disabled="false" sort="0"/>', [$module_and_file]));
                            }
                            if (!isset($plugin['_value']['interceptor']['_attribute']['instance'])) {
                                throw new Core(__('%{1} 拦截器Interceptor没有设置instance属性：<interceptor name="interceptorName" instance="instanceClass" disabled="false" sort="0"/>', [$module_and_file]));
                            }
                            $pluginData = $plugin['_value']['interceptor']['_attribute'];
                            $pluginData['module'] = $moduleName;
                            $pluginData['module_status'] = true; // 已通过状态检查
                            $module_plugin_interceptors[$plugin['_attribute']['name']][] = ['class' => $plugin['_attribute']['class'], 'plugins' => $pluginData];
                        }
                    }
                }
            } else {
                if (!isset($config['config']['_value']['plugin']['_attribute']['name'])) {
                    throw new Core(__('%{1} 拦截器Plugin未指定name属性：<plugin name="pluginName">...</plugin>', [$module_and_file]));
                }
                // interceptor有多个值的情况
                $interceptors = $config['config']['_value']['plugin']['_value']['interceptor'];
                if (!isset($interceptors['_attribute']) && is_array($interceptors)) {
                    foreach ($interceptors as $item) {
                        if (!isset($item['_attribute'])) {
                            throw new Core(__('%{1} 拦截器Interceptor没有设置属性：<interceptor name="interceptorName" instance="instanceClass" disabled="false" sort="0"/>', [$module_and_file]));
                        }
                        if (!isset($item['_attribute']['name'])) {
                            throw new Core(__('%{1} 拦截器Interceptor没有设置name属性：<interceptor name="interceptorName" instance="instanceClass" disabled="false" sort="0"/>', [$module_and_file]));
                        }
                        if (!isset($item['_attribute']['instance'])) {
                            throw new Core(__('%{1} 拦截器Interceptor没有设置instance属性：<interceptor name="interceptorName" instance="instanceClass" disabled="false" sort="0"/>', [$module_and_file]));
                        }
                        $pluginData = $item['_attribute'];
                        $pluginData['module'] = $moduleName;
                        $pluginData['module_status'] = true; // 已通过状态检查
                        $module_plugin_interceptors[$config['config']['_value']['plugin']['_attribute']['name']][] = ['class' => $config['config']['_value']['plugin']['_attribute']['class'], 'plugins' => $pluginData];
                    }
                } else {
                    if (!isset($interceptors['_attribute'])) {
                        throw new Core(__('%{1} 拦截器Interceptor没有设置属性：<interceptor name="interceptorName" instance="instanceClass" disabled="false" sort="0"/>', [$module_and_file]));
                    }
                    if (!isset($interceptors['_attribute']['name'])) {
                        throw new Core(__('%{1} 拦截器Interceptor没有设置name属性：<interceptor name="interceptorName" instance="instanceClass" disabled="false" sort="0"/>', [$module_and_file]));
                    }
                    if (!isset($interceptors['_attribute']['instance'])) {
                        throw new Core(__('%{1} 拦截器Interceptor没有设置instance属性：<interceptor name="interceptorName" instance="instanceClass" disabled="false" sort="0"/>', [$module_and_file]));
                    }
                    $pluginData = $interceptors['_attribute'];
                    $pluginData['module'] = $moduleName;
                    $pluginData['module_status'] = true; // 已通过状态检查
                    $module_plugin_interceptors[$config['config']['_value']['plugin']['_attribute']['name']][] = ['class' => $config['config']['_value']['plugin']['_attribute']['class'], 'plugins' => $pluginData];
                }
            }
            $plugin_interceptors_list[$module_and_file] = $module_plugin_interceptors;
        }
        $this->pluginCache->set('plugin', $plugin_interceptors_list);

        return $plugin_interceptors_list;
    }
    
    /**
     * 读取指定模块的拦截器配置
     *
     * @param array $moduleNames 模块名列表
     * @return array
     * @throws Core
     */
    public function readForModules(array $moduleNames): array
    {
        // 清除缓存以强制重新读取
        $this->pluginCache->delete('plugin');
        
        // 读取所有配置
        $allConfigs = parent::read();
        
        // 过滤只保留指定模块的配置
        $plugin_interceptors_list = [];
        $env = \Weline\Framework\App\Env::getInstance();
        
        foreach ($allConfigs as $module_and_file => $config) {
            $moduleName = explode('::', $module_and_file)[0] ?? '';
            
            // 只处理目标模块
            if (!in_array($moduleName, $moduleNames, true)) {
                continue;
            }
            
            // 检查模块状态
            if (empty($moduleName) || !$env->getModuleStatus($moduleName)) {
                continue;
            }
            
            $module_plugin_interceptors = [];
            if (
                !isset($config['config']['_attribute']['noNamespaceSchemaLocation']) ||
                ('urn:Weline_Framework::Plugin/etc/xsd/plugin.xsd' !== $config['config']['_attribute']['noNamespaceSchemaLocation'])
            ) {
                die(__('%{1} 拦截器必须设置：noNamespaceSchemaLocation="urn:Weline_Framework::Plugin/etc/xsd/plugin.xsd"', [$module_and_file]));
            }
            
            // 复用与 read() 相同的解析逻辑
            if (is_integer(array_key_first($config['config']['_value']['plugin']))) {
                foreach ($config['config']['_value']['plugin'] as $plugin) {
                    if (!isset($plugin['_attribute']['name'])) {
                        throw new Core(__('%{1} 拦截器Plugin未指定name属性', [$module_and_file]));
                    }
                    if (!isset($plugin['_attribute']['class'])) {
                        throw new Core(__('%{1} 拦截器Plugin未指定class属性', [$module_and_file]));
                    }
                    if (is_integer(array_key_first($plugin['_value']))) {
                        foreach ($plugin['_value'] as $item_interceptor) {
                            $module_plugin_interceptors[$plugin['_attribute']['name']][] = $item_interceptor;
                        }
                    } else {
                        if (is_array($plugin['_value']['interceptor'])) {
                            foreach ($plugin['_value']['interceptor'] as $item) {
                                if (!isset($item['_attribute'])) {
                                    throw new Core(__('%{1} 拦截器Interceptor没有设置属性', [$module_and_file]));
                                }
                                if (!isset($item['_attribute']['name'])) {
                                    throw new Core(__('%{1} 拦截器Interceptor没有设置name属性', [$module_and_file]));
                                }
                                if (!isset($item['_attribute']['instance'])) {
                                    throw new Core(__('%{1} 拦截器Interceptor没有设置instance属性', [$module_and_file]));
                                }
                                $pluginData = $item['_attribute'];
                                $pluginData['module'] = $moduleName;
                                $pluginData['module_status'] = true;
                                $module_plugin_interceptors[$plugin['_attribute']['name']][] = ['class' => $plugin['_attribute']['class'], 'plugins' => $pluginData];
                            }
                        } else {
                            if (!isset($plugin['_value']['interceptor']['_attribute'])) {
                                throw new Core(__('%{1} 拦截器Interceptor没有设置属性', [$module_and_file]));
                            }
                            if (!isset($plugin['_value']['interceptor']['_attribute']['name'])) {
                                throw new Core(__('%{1} 拦截器Interceptor没有设置name属性', [$module_and_file]));
                            }
                            if (!isset($plugin['_value']['interceptor']['_attribute']['instance'])) {
                                throw new Core(__('%{1} 拦截器Interceptor没有设置instance属性', [$module_and_file]));
                            }
                            $pluginData = $plugin['_value']['interceptor']['_attribute'];
                            $pluginData['module'] = $moduleName;
                            $pluginData['module_status'] = true;
                            $module_plugin_interceptors[$plugin['_attribute']['name']][] = ['class' => $plugin['_attribute']['class'], 'plugins' => $pluginData];
                        }
                    }
                }
            } else {
                if (!isset($config['config']['_value']['plugin']['_attribute']['name'])) {
                    throw new Core(__('%{1} 拦截器Plugin未指定name属性', [$module_and_file]));
                }
                $interceptors = $config['config']['_value']['plugin']['_value']['interceptor'];
                if (!isset($interceptors['_attribute']) && is_array($interceptors)) {
                    foreach ($interceptors as $item) {
                        if (!isset($item['_attribute'])) {
                            throw new Core(__('%{1} 拦截器Interceptor没有设置属性', [$module_and_file]));
                        }
                        if (!isset($item['_attribute']['name'])) {
                            throw new Core(__('%{1} 拦截器Interceptor没有设置name属性', [$module_and_file]));
                        }
                        if (!isset($item['_attribute']['instance'])) {
                            throw new Core(__('%{1} 拦截器Interceptor没有设置instance属性', [$module_and_file]));
                        }
                        $pluginData = $item['_attribute'];
                        $pluginData['module'] = $moduleName;
                        $pluginData['module_status'] = true;
                        $module_plugin_interceptors[$config['config']['_value']['plugin']['_attribute']['name']][] = ['class' => $config['config']['_value']['plugin']['_attribute']['class'], 'plugins' => $pluginData];
                    }
                } else {
                    if (!isset($interceptors['_attribute'])) {
                        throw new Core(__('%{1} 拦截器Interceptor没有设置属性', [$module_and_file]));
                    }
                    if (!isset($interceptors['_attribute']['name'])) {
                        throw new Core(__('%{1} 拦截器Interceptor没有设置name属性', [$module_and_file]));
                    }
                    if (!isset($interceptors['_attribute']['instance'])) {
                        throw new Core(__('%{1} 拦截器Interceptor没有设置instance属性', [$module_and_file]));
                    }
                    $pluginData = $interceptors['_attribute'];
                    $pluginData['module'] = $moduleName;
                    $pluginData['module_status'] = true;
                    $module_plugin_interceptors[$config['config']['_value']['plugin']['_attribute']['name']][] = ['class' => $config['config']['_value']['plugin']['_attribute']['class'], 'plugins' => $pluginData];
                }
            }
            $plugin_interceptors_list[$module_and_file] = $module_plugin_interceptors;
        }

        return $plugin_interceptors_list;
    }
}
