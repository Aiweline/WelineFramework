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
namespace Weline\Framework\Http\Request;

class Interceptor extends \Weline\Framework\Http\Request
{
    // 继承侦听器trait
    use \Weline\Framework\Interception\Interceptor;
    
    
    public function getUrlPath(
        string $url=''
    ): string
    {
        
        $pluginInfo = $this->pluginsManager->getPluginInfo($this->subjectType, 'getUrlPath');
        if (!$pluginInfo) {
            return parent::getUrlPath($url);
        } else {
            return $this->___callPlugins('getUrlPath', func_get_args(), $pluginInfo);
        } 
    }
}
