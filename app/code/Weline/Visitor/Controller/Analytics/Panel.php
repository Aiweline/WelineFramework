<?php

namespace Weline\Visitor\Controller\Analytics;

use Weline\Framework\App\Controller\FrontendController;

/**
 * 像素数据分析面板控制器
 * 
 * 提供完整的数据分析面板页面
 */
class Panel extends FrontendController
{
    /**
     * 显示数据分析面板
     * 
     * @return string
     */
    public function index(): string
    {
        return $this->fetch('Weline_Visitor::analytics/panel.phtml');
    }
}

