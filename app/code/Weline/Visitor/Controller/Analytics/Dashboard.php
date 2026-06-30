<?php

namespace Weline\Visitor\Controller\Analytics;

use Weline\Framework\App\Controller\FrontendController;

/**
 * 像素数据大屏展示控制器
 * 
 * 提供大屏展示页面用于实时数据可视化
 */
class Dashboard extends FrontendController
{
    /**
     * 显示大屏展示页面
     * 
     * @return string
     */
    public function index(): string
    {
        return $this->fetch('Weline_Visitor::templates/analytics/dashboard.phtml');
    }
}

