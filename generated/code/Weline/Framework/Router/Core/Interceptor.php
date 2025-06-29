<?php
/**
 * 文件信息 Weline框架自动侦听拦截类，请勿随意修改，以免造成系统异常
 * 作者：WelineFramework                       【Aiweline/邹万才】
 * 网名：WelineFramework框架                    【秋枫雁飞(Aiweline)】
 * 网站：www.aiweline.com/bbs.aiweline.com
 * 工具：WelineFramework框架
 * 日期：2025-06-28
 * 时间：20:06:30
 * 描述：此文件源码由WelineFramework框架自动侦听拦截类，请勿随意修改源码，以免造成系统异常！
 */
namespace Weline\Framework\Router\Core;

class Interceptor extends \Weline\Framework\Router\Core
{
    // 继承侦听器trait
    use \Weline\Framework\Interception\Interceptor;
    
    /**
     * @DESC         |路由处理
     *
     * 参数区：
     *
     * @throws Exception
     * @throws \ReflectionException
     */
    public function start(
        
    )
    {
        
        $pluginInfo = $this->pluginsManager->getPluginInfo($this->subjectType, 'start');
        if (!$pluginInfo) {
            return parent::start();
        } else {
            return $this->___callPlugins('start', func_get_args(), $pluginInfo);
        } 
    }
    
    /**
     * @throws \ReflectionException
     * @throws Exception
     * @throws \Exception
     */
    public function route(
        
    )
    {
        
        $pluginInfo = $this->pluginsManager->getPluginInfo($this->subjectType, 'route');
        if (!$pluginInfo) {
            return parent::route();
        } else {
            return $this->___callPlugins('route', func_get_args(), $pluginInfo);
        } 
    }
}
