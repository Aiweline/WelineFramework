<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Plugin;

use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Plugin\Api\Data\InterceptorInterface;
use Weline\Framework\Plugin\Cache\PluginCache;
use Weline\Framework\Plugin\Config\PluginXmlReader;

class PluginsManager
{
    private $plugin_map = [];

    protected array $plugins = [];

    /**
     * @var PluginXmlReader
     */
    private PluginXmlReader $reader;

    /**
     * @var CacheInterface
     */
    private CacheInterface $pluginCache;

    public function __construct(
        PluginXmlReader $reader,
        PluginCache     $pluginCache
    )
    {
        $this->reader      = $reader;
        $this->pluginCache = $pluginCache->create();
    }

    /**
     * @DESC         |扫描定义的插件
     *
     * 参数区：
     *
     * @param bool $cache
     *
     * @return array
     * @throws \Weline\Framework\App\Exception
     * @throws \Weline\Framework\Exception\Core
     */
    public function scanPlugins(bool $cache = true): array
    {
        $cache_key = 'plugins_data';
        // 避免重复加载
        if ($this->plugins) {
            return $this->plugins;
        }
        // 检测插件缓存
        if ($cache && $plugins = $this->pluginCache->get($cache_key)) {
            $this->plugins = $plugins;
            return $this->plugins;
        }
        if (empty($this->plugins)) {
            // 合并相同类的拦截器
            foreach ($this->reader->read() as $module_and_file => $pluginInstances) {
                foreach ($pluginInstances as $key => $instances) {
                    foreach ($instances as $k => $instance) {
                        if (isset($instance['plugins']['disabled']) && 'true' === $instance['plugins']['disabled']) {
                            unset($instances[$k]);
                        }
                        $this->plugins[$instance['class']][] = $instance['plugins'];
                    }
                }
            }
        }
        $types_plugins_info = [];
        // 检查定义所有插件类的方法（方法列表要求：
        //1、 读取所有插件的方法的名字必须在被侦听的类中的方法中存在
        //2、 全局原始类函数，用于创建侦听类使用
        //）
        // 反射所有插件类方法
        foreach ($this->plugins as $type => $type_plugins) {
            // 插件类排序 二维数组根据sort字段排序
            usort($type_plugins, function ($a, $b) {
                return strnatcasecmp($a['sort'], $b['sort']);
            } );            
            $plugin_listen_methods = [];

            try {
                $typeRef = new \ReflectionClass($type);
            } catch (\ReflectionException $e) {
                throw new \Error($e->getMessage(), $e->getCode(), $e);
            }
            if ($typeRef->isFinal()) {
                throw new \Error(__('无法动态代理final类:%1', [$type]));
            }
            // 读取被侦听拦截原始类的方法列表
            $type_methods = $typeRef->getMethods();
            foreach ($type_methods as $key => $method) {
                unset($type_methods[$key]);
                if ($type === $method->class && '__construct' !== $method->name) {
                    $type_methods[$method->name] = $method->name;
                }
            }
            $types_plugins_info[$type]['methods'] = $type_methods;

            foreach ($type_plugins as $type_plugin) {
                try {
                    $typePluginRef = new \ReflectionClass($type_plugin['instance']);
                } catch (\ReflectionException $e) {
                    throw new \Error($e->getMessage(), $e->getCode(), $e);
                }
                if ($typePluginRef->isFinal()) {
                    throw new \Error(__('插件名称：%name,' . PHP_EOL . '无法动态代理final类:%instance' . PHP_EOL . '状态：%disabled' . PHP_EOL . '排序：%sort', $type_plugin));
                }
                $plugin_instance_methods = $typePluginRef->getMethods();
                foreach ($plugin_instance_methods as $key => $plugin_instance_method) {
                    unset($plugin_instance_methods[$key]);
                    /*不可继承*/
                    /*if ('__construct' != $plugin_instance_method->name && $type_plugin['instance'] === $plugin_instance_method->class) {*/
                    /*可继承：插件类保留PHP原有特性*/
                    if ('__construct' !== $plugin_instance_method->name) {
                        // 获取当前类的方法
                        if (trim($type_plugin['instance'], '\\') === $plugin_instance_method->class) {
                            $name = str_replace(
                                [
                                    InterceptorInterface::LISTENER_BEFORE,
                                    InterceptorInterface::LISTENER_AROUND,
                                    InterceptorInterface::LISTENER_AFTER,
                                ],
                                '',
                                $plugin_instance_method->name
                            );

                            // 检测首字母大小写字母匹配的方法是否存在：存在则不新增
                            if (!in_array($name, $plugin_instance_methods, true) || !in_array(lcfirst($name), $plugin_instance_methods, true)) {
                                // 检测首字母大小写字母匹配的方法
                                if (in_array($name, $type_methods, true)) {
                                    $plugin_instance_methods[$name][] = $plugin_instance_method->name;
                                } elseif (in_array(lcfirst($name), $type_methods, true)) {
                                    $plugin_instance_methods[lcfirst($name)][] = $plugin_instance_method->name;
                                }
                            }

                            // 检测首字母大小写字母匹配的方法是否存在：存在则不新增:全局原始类函数，用于创建侦听类使用
                            $origin_in_listen = in_array($name, $type_methods, true);
                            if (!in_array($name, $plugin_listen_methods, true) && $origin_in_listen) {
                                // 检测首字母大小写字母匹配的方法
                                $origin_is_in = false;
                                if (!in_array($name, $plugin_listen_methods, true)) {
                                    $plugin_listen_methods[] = $name;
                                    $origin_is_in            = true;
                                } elseif (!$origin_is_in) {
                                    if (!in_array($name, $plugin_listen_methods, true)) {
                                        $plugin_listen_methods[] = lcfirst($name);
                                        $origin_is_in            = true;
                                    }
                                }
                                // 还不在就创建
                                if (!$origin_is_in) {
                                    $plugin_listen_methods[] = lcfirst($name);
                                }
                            }

                            // 检测首字母大小写字母匹配的方法是否存在：存在则不新增:全局原始类函数，用于创建侦听类使用
                            $lcfirst_name = lcfirst($name);

                            $lcfirst_in_listen = in_array($lcfirst_name, $type_methods, true);
                            if (!in_array($lcfirst_name, $plugin_listen_methods, true) && $lcfirst_in_listen) {
                                // 检测首字母大小写字母匹配的方法
                                $lcfirst_is_in = false;
                                if (!in_array($lcfirst_name, $plugin_listen_methods, true)) {
                                    $plugin_listen_methods[] = $lcfirst_name;
                                    $lcfirst_is_in           = true;
                                } elseif (!$lcfirst_is_in) {
                                    if (!in_array($lcfirst_name, $plugin_listen_methods, true)) {
                                        $plugin_listen_methods[] = $lcfirst_name;
                                        $lcfirst_is_in           = true;
                                    }
                                }
                                // 还不在就创建
                                if (!$lcfirst_is_in) {
                                    $plugin_listen_methods[] = $lcfirst_name;
                                }
                            }
                        }
                    }
                }
                $types_plugins_info[$type]['plugins_methods'][$type_plugin['instance']] = $plugin_instance_methods;
            }
            $types_plugins_info[$type]['listen_methods'] = $plugin_listen_methods;
        }
        // 再检出前置、环绕、后置等方法信息（包含类和方法）
        $before_name = InterceptorInterface::LISTENER_BEFORE;
        $around_name = InterceptorInterface::LISTENER_AROUND;
        $after_name  = InterceptorInterface::LISTENER_AFTER;
        foreach ($types_plugins_info as $type_class => $plugins_info) {
            $plugins_info[$before_name] = [];
            $plugins_info[$around_name] = [];
            $plugins_info[$after_name]  = [];
            foreach ($plugins_info['plugins_methods'] as $plugin_class => $plugin_methods) {
                foreach ($plugin_methods as $type_class_method => $type_class_method_plugin_methods) {
                    foreach ($type_class_method_plugin_methods as $type_class_method_plugin_method) {
                        // 前置
                        if (is_int(strpos($type_class_method_plugin_method, $before_name))) {
                            $plugins_info[$before_name][] = [
                                'instance' => $plugin_class,
                                'method'   => $type_class_method_plugin_method,
                            ];
                        } elseif (is_int(strpos($type_class_method_plugin_method, $around_name))) {
                            // 环绕
                            $plugins_info[$around_name][] = [
                                'instance' => $plugin_class,
                                'method'   => $type_class_method_plugin_method,
                            ];
                        } elseif (is_int(strpos($type_class_method_plugin_method, $after_name))) {
                            $plugins_info[$after_name][] = [
                                'instance' => $plugin_class,
                                'method'   => $type_class_method_plugin_method,
                            ];
                        }
                    }
                }
            }
            $types_plugins_info[$type_class] = $plugins_info;
        }
        // 针对方法的前置，环绕，后置
        foreach ($types_plugins_info as $type => $plugin_data) {
            $method_plugins_methods = [];
            foreach ($plugin_data['listen_methods'] as $listen_method) {
                foreach ($plugin_data['plugins_methods'] as $plugin_class => $plugin_methods) {
                    if ($plugin_methods && isset($plugin_methods[$listen_method])) {
                        foreach ($plugin_methods[$listen_method] as $plugin_method) {
                            $plugin_class_method_data = [
                                'instance' => $plugin_class,
                                'method'   => $plugin_method,
                            ];
                            // 全部数据
                            $method_plugins_methods[$listen_method]['all'][] = $plugin_class_method_data;

                            if (is_int(strpos($plugin_method, $before_name))) {
                                // 前置
                                $method_plugins_methods[$listen_method][$before_name][] = $plugin_class_method_data;
                            } elseif (is_int(strpos($plugin_method, $around_name))) {
                                // 环绕
                                $method_plugins_methods[$listen_method][$around_name][] = $plugin_class_method_data;
                            } elseif (is_int(strpos($plugin_method, $after_name))) {
                                // 后置
                                $method_plugins_methods[$listen_method][$after_name][] = $plugin_class_method_data;
                            }
                        }
                    }
                }
            }
            $plugin_data['methods_plugins'] = $method_plugins_methods;
            $types_plugins_info[$type]      = $plugin_data;
        }
//        p($types_plugins_info['Aiweline\Index\Controller\Index']);
        // 正式环境则缓存
        if ($cache) {
            $this->pluginCache->set($cache_key, $types_plugins_info);
        }
        $this->plugins = $types_plugins_info;
        return $this->plugins;
    }

    /**
     * @DESC         |获取类的插件类列表
     *
     * 参数区：
     *
     * @param string $class
     *
     * @return mixed
     * @throws \Weline\Framework\App\Exception
     * @throws \Weline\Framework\Exception\Core
     */
    public function getClassPluginInstanceList(string $class = ''): mixed
    {
        $plugins = $this->scanPlugins();
        if ($class) {
            return $plugins[$class] ?? [];
        }

        return $plugins;
    }

    /**
     * @DESC         |获取插件信息
     * 读取插件所有信息：插件定义的所有方法的 前置、环绕、后置 拦截信息
     *
     * 参数区：
     *
     * @param string      $type
     * @param string      $method
     * @param string|null $code
     *
     * @return array|mixed
     * @throws \Weline\Framework\App\Exception
     * @throws \Weline\Framework\Exception\Core
     */
    public function getPluginInfo(string $type, string $method, string $code = null): mixed
    {
        // 避免多次读取
        if (isset($this->plugin_map[$type . $method])) {
            return $this->plugin_map[$type . $method];
        }
        $data = [];
        if (isset($this->scanPlugins(false)[$type])) {
            if (isset($this->scanPlugins(false)[$type]['methods_plugins'][$method])) {
                $data = $this->plugin_map[$type . $method] = $this->scanPlugins(false)[$type]['methods_plugins'][$method];
            }
        }
        $this->plugin_map[$type . $method] = $data;

        return $this->plugin_map[$type . $method];
    }

    /**
     * Retrieve next plugins in chain
     *
     * @param string $type
     * @param string $method
     * @param string $code
     *
     * @return array
     */
    public function getNext($type, $method, $code = '__self'): ?array
    {
        if (!isset($this->plugin_map[$type . $method])) {
            $this->_inheritPlugins($type);
        }
        $key = $type . '_' . lcfirst($method) . '_' . $code;

        return $this->_processed[$key] ?? null;
    }

    /**
     * @DESC         |为插件创建 侦听 类
     *
     * 参数区：
     *
     * @param string $class
     * @param bool   $cache
     *
     * @return Proxy\Generator
     * @throws \Weline\Framework\Exception\Core
     * @throws \ReflectionException
     * @throws \Weline\Framework\App\Exception
     */
    public function generatorInterceptor(string $class = '', bool $cache = false): Proxy\Generator
    {
        /**@var \Weline\Framework\Plugin\Proxy\Generator $generator */
        $generator = ObjectManager::getInstance(\Weline\Framework\Plugin\Proxy\Generator::class);
        if ($class) {
            $generator::createInterceptor($class);
        } else {
            foreach ($this->scanPlugins($cache) as $origin_class => $scanPlugin) {
                $generator::createInterceptor($origin_class);
            }
        }

        return $generator;
    }
}
