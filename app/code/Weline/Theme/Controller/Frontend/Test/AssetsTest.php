<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Controller\Frontend\Test;

use Weline\Framework\App\Controller\FrontendController;

/**
 * CSS/JS提取功能测试控制器
 */
class AssetsTest extends FrontendController
{
    /**
     * 布局类型，用于 Theme 模块自动加载对应的布局模板
     * 
     * @var string|null
     */
    protected ?string $layoutType = 'test';
    
    /**
     * 布局选项
     * 
     * @var string|null
     */
    protected ?string $layoutOption = 'assets-test';
    
    /**
     * 测试页面
     */
    public function getIndex()
    {
        // 设置页面标题
        $this->assign('title', __('CSS/JS提取功能测试'));
        
        // 确保布局信息被设置
        $this->setData('layoutType', $this->layoutType);
        $this->setData('layoutOption', $this->layoutOption);
        
        // 渲染测试布局模板
        return $this->fetch('Weline_Theme::theme/frontend/layouts/test/assets-test.phtml');
    }
}

