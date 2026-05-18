<?php

namespace Weline\Visitor\Controller\Analytics;

use Weline\Framework\App\Controller\FrontendController;

/**
 * 像素数据分析测试控制器
 * 
 * 提供测试页面用于测试数据分析API接口
 */
class Test extends FrontendController
{
    /**
     * 显示测试页面
     * 
     * @return string
     */
    public function index(): string
    {
        return $this->fetch('Weline_Visitor::templates/analytics/test.phtml');
    }
}

