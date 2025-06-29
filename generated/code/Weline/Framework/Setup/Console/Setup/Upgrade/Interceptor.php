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
namespace Weline\Framework\Setup\Console\Setup\Upgrade;

class Interceptor extends \Weline\Framework\Setup\Console\Setup\Upgrade
{
    // 继承侦听器trait
    use \Weline\Framework\Interception\Interceptor;
    
    
    public function __construct(
        \Weline\Framework\Output\Cli\Printing $printing
    )
    {
        
//        $this->__init();
        parent::__construct($printing);
                    
    }
    
    /**
     * @inheritDoc
     */
    public function execute(
        array $args=array (
),
        array $data=array (
)
    )
    {
        
        $pluginInfo = $this->pluginsManager->getPluginInfo($this->subjectType, 'execute');
        if (!$pluginInfo) {
            return parent::execute($args,
        $data);
        } else {
            return $this->___callPlugins('execute', func_get_args(), $pluginInfo);
        } 
    }
}
