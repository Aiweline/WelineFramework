<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Plugin\Config;

use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Plugin\Cache\PluginCache;
use Weline\Framework\Exception\Core;
use Weline\Framework\System\File\Scanner;
use Weline\Framework\Xml\Parser;

class PluginXmlReader extends \Weline\Framework\Config\Reader\XmlReader
{
    /**
     * @var CacheInterface
     */
    private CacheInterface $pluginCache;

    public function __construct(
        PluginCache $pluginCache,
        Scanner     $scanner,
        Parser      $parser,
                    $path = 'etc'.DS.'plugin.xml'
    )
    {
        parent::__construct($scanner, $parser, $path);
        $this->pluginCache = $pluginCache->create();
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
        foreach ($configs as $module_and_file => $config) {
            $module_plugin_interceptors = [];
            if (
                !isset($config['config']['_attribute']['noNamespaceSchemaLocation']) &&
                ('urn:Weline_Framework::Plugin/etc/xsd/plugin.xsd' !== $config['config']['_attribute']['noNamespaceSchemaLocation'])
            ) {
                throw new Core(__($module_and_file . '拦截器必须设置：noNamespaceSchemaLocation="urn:Weline_Framework::Plugin/etc/xsd/plugin.xsd"'));
            }
            // 多个值
            if (is_integer(array_key_first($config['config']['_value']['plugin']))) {
                foreach ($config['config']['_value']['plugin'] as $plugin) {
                    if (!isset($plugin['_attribute']['name'])) {
                        throw new Core(__($module_and_file . '拦截器Plugin未指定name属性：<plugin name="pluginName">...</plugin>'));
                    }
                    if (!isset($plugin['_attribute']['class'])) {
                        throw new Core(__($module_and_file . '拦截器Plugin未指定class属性：<plugin class="pluginClass">...</plugin>'));
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
                                    throw new Core(__($module_and_file . '拦截器Interceptor没有设置属性：<interceptor name="interceptorName" instance="instanceClass" disabled="false" sort="0"/>'));
                                }
                                if (!isset($item['_attribute']['name'])) {
                                    throw new Core(__($module_and_file . '拦截器Interceptor没有设置name属性：<interceptor name="interceptorName" instance="instanceClass" disabled="false" sort="0"/>'));
                                }
                                if (!isset($item['_attribute']['instance'])) {
                                    throw new Core(__($module_and_file . '拦截器Interceptor没有设置instance属性：<interceptor name="interceptorName" instance="instanceClass" disabled="false" sort="0"/>'));
                                }
                                $module_plugin_interceptors[$plugin['_attribute']['name']][] = ['class' => $plugin['_attribute']['class'], 'plugins' => $item['_attribute']];
                            }
                        } else {
                            if (!isset($plugin['_value']['interceptor']['_attribute'])) {
                                throw new Core(__($module_and_file . '拦截器Interceptor没有设置属性：<interceptor name="interceptorName" instance="instanceClass" disabled="false" sort="0"/>'));
                            }
                            if (!isset($plugin['_value']['interceptor']['_attribute']['name'])) {
                                throw new Core(__($module_and_file . '拦截器Interceptor没有设置name属性：<interceptor name="interceptorName" instance="instanceClass" disabled="false" sort="0"/>'));
                            }
                            if (!isset($plugin['_value']['interceptor']['_attribute']['instance'])) {
                                throw new Core(__($module_and_file . '拦截器Interceptor没有设置instance属性：<interceptor name="interceptorName" instance="instanceClass" disabled="false" sort="0"/>'));
                            }
                            $module_plugin_interceptors[$plugin['_attribute']['name']][] = ['class' => $plugin['_attribute']['class'], 'plugins' => $plugin['_value']['interceptor']['_attribute']];
                        }
                    }
                }
            } else {
                if (!isset($config['config']['_value']['plugin']['_attribute']['name'])) {
                    throw new Core(__($module_and_file . '拦截器Plugin未指定name属性：<plugin name="pluginName">...</plugin>'));
                }
                // interceptor有多个值的情况
                $interceptors = $config['config']['_value']['plugin']['_value']['interceptor'];
                if (!isset($interceptors['_attribute']) && is_array($interceptors)) {
                    foreach ($interceptors as $item) {
                        if (!isset($item['_attribute'])) {
                            throw new Core(__($module_and_file . '拦截器Interceptor没有设置属性：<interceptor name="interceptorName" instance="instanceClass" disabled="false" sort="0"/>'));
                        }
                        if (!isset($item['_attribute']['name'])) {
                            throw new Core(__($module_and_file . '拦截器Interceptor没有设置name属性：<interceptor name="interceptorName" instance="instanceClass" disabled="false" sort="0"/>'));
                        }
                        if (!isset($item['_attribute']['instance'])) {
                            throw new Core(__($module_and_file . '拦截器Interceptor没有设置instance属性：<interceptor name="interceptorName" instance="instanceClass" disabled="false" sort="0"/>'));
                        }
                        $module_plugin_interceptors[$config['config']['_value']['plugin']['_attribute']['name']][] = ['class' => $config['config']['_value']['plugin']['_attribute']['class'], 'plugins' => $item['_attribute']];
                    }
                } else {
                    if (!isset($interceptors['_attribute'])) {
                        throw new Core(__($module_and_file . '拦截器Interceptor没有设置属性：<interceptor name="interceptorName" instance="instanceClass" disabled="false" sort="0"/>'));
                    }
                    if (!isset($interceptors['_attribute']['name'])) {
                        throw new Core(__($module_and_file . '拦截器Interceptor没有设置name属性：<interceptor name="interceptorName" instance="instanceClass" disabled="false" sort="0"/>'));
                    }
                    if (!isset($interceptors['_attribute']['instance'])) {
                        throw new Core(__($module_and_file . '拦截器Interceptor没有设置instance属性：<interceptor name="interceptorName" instance="instanceClass" disabled="false" sort="0"/>'));
                    }
                    $module_plugin_interceptors[$config['config']['_value']['plugin']['_attribute']['name']][] = ['class' => $config['config']['_value']['plugin']['_attribute']['class'], 'plugins' => $interceptors['_attribute']];
                }
            }
            $plugin_interceptors_list[$module_and_file] = $module_plugin_interceptors;
        }
        $this->pluginCache->set('plugin', $plugin_interceptors_list);

        return $plugin_interceptors_list;
    }
}
