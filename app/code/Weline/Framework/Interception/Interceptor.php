<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Interception;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Plugin\Api\Data\InterceptorInterface;
use Weline\Framework\Plugin\PluginsManager;

trait Interceptor
{
    private string $subjectType;

    private PluginsManager $pluginsManager;

    public function __init(): void
    {
        if (method_exists(get_parent_class($this), '__init')) {
            parent::__init();
        }
        $this->pluginsManager = ObjectManager::getInstance(PluginsManager::class);
        $this->subjectType    = get_parent_class($this);
    }

    /**
     * @return string
     */
    public function getSubjectType(): string
    {
        return $this->subjectType;
    }

    /**
     * 设置
     *
     * @param string $subjectType
     */
    public function setSubjectType(string $subjectType): void
    {
        $this->subjectType = $subjectType;
    }

    /**
     * @return PluginsManager
     */
    public function getPluginsManager(): PluginsManager
    {
        return $this->pluginsManager;
    }

    /**
     * 设置
     *
     * @param PluginsManager $pluginsManager
     */
    public function setPluginsManager(PluginsManager $pluginsManager): void
    {
        $this->pluginsManager = $pluginsManager;
    }

    /**
     * 调用给定的方法所有的插件
     *
     * @param string $method
     * @param array  $arguments
     * @param array  $pluginInfo
     *
     * @return mixed|null
     * @throws \ReflectionException
     * @throws \Weline\Framework\App\Exception
     */
    protected function ___callPlugins(string $method, array $arguments, array $pluginInfo): mixed
    {
        //闭包调用
        $subject          = $this;
        $result           = null;
        $origin_arguments = $arguments;

        $next   = function (...$arguments) use (
            $method,
            &$pluginInfo,
            $subject,
            &$next
        ) {
            $capMethod         = ucfirst($method);
            $currentPluginInfo = $pluginInfo;
            $result            = null;
            if (isset($currentPluginInfo[InterceptorInterface::LISTENER_BEFORE])) {
                // 调用前置拦截器
                foreach ($currentPluginInfo[InterceptorInterface::LISTENER_BEFORE] as $key => $code) {
                    $pluginInstance = ObjectManager::getInstance($code['instance']);
                    $pluginMethod   = 'before' . $capMethod;
                    unset($currentPluginInfo[InterceptorInterface::LISTENER_BEFORE][$key]);
                    // 如果没有before了就清空，以免多次执行
                    if (count($currentPluginInfo[InterceptorInterface::LISTENER_BEFORE]) === 0) {
                        unset($currentPluginInfo[InterceptorInterface::LISTENER_BEFORE]);
                    }
                    $pluginInfo   = $currentPluginInfo;
                    $beforeResult = $pluginInstance->$pluginMethod($this, ...array_values($arguments));
                    if ($beforeResult !== null) {
                        $arguments = (array)$beforeResult;
                    }
                }
            }

            if (isset($currentPluginInfo[InterceptorInterface::LISTENER_AROUND])) {
                // 调用环绕拦截器
                $code = array_shift($currentPluginInfo[InterceptorInterface::LISTENER_AROUND]);
                // 如果没有around了就清空，以免多次执行
                if (count($currentPluginInfo[InterceptorInterface::LISTENER_AROUND]) === 0) {
                    unset($currentPluginInfo[InterceptorInterface::LISTENER_AROUND]);
                }
                $pluginInfo     = $currentPluginInfo;
                $pluginInstance = ObjectManager::getInstance($code['instance']);
                $pluginMethod   = 'around' . $capMethod;
                $result         = $pluginInstance->$pluginMethod($subject, $next, ...array_values($arguments));
            } else {
                // 调用原始方法
                $result = $subject->___callParentMethod($method, $arguments);
            }
            if (isset($currentPluginInfo[InterceptorInterface::LISTENER_AFTER])) {
                // 调用后置拦截器
                foreach ($currentPluginInfo[InterceptorInterface::LISTENER_AFTER] as $key => $code) {
                    unset($currentPluginInfo[InterceptorInterface::LISTENER_AFTER][$key]);
                    // 如果没有after了就清空，以免多次执行
                    if (count($currentPluginInfo[InterceptorInterface::LISTENER_AFTER]) === 0) {
                        unset($currentPluginInfo[InterceptorInterface::LISTENER_AFTER]);
                    }
                    $pluginInstance = ObjectManager::getInstance($code['instance']);
                    $pluginMethod   = 'after' . $capMethod;
                    $pluginInfo     = $currentPluginInfo;
                    $result         = $pluginInstance->$pluginMethod($subject, $result, ...array_values($arguments));
                }
            }

            return $result;
        };
        $result = $next(...$arguments);
        $next   = null;

        return $result;
    }

    public function ___callParentMethod(string $method, array $arguments)
    {
        return parent::$method(...$arguments);
    }
}
