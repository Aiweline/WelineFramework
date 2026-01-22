<?php

declare(strict_types=1);

/*
 * Weline Theme Module
 * 政策页面前端控制器
 * 
 * 统一处理所有政策页面布局
 * URL格式：/theme/policy/{布局}
 * 例如：/theme/policy/cookie, /theme/policy/privacy, /theme/policy/default
 * 如果未指定布局，默认使用 default
 */

namespace Weline\Theme\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;

class Policy extends FrontendController
{
    /**
     * 布局类型
     * 
     * @var string
     */
    protected ?string $layoutType = 'policy';

    /**
     * 显示政策页面（默认布局）
     * 
     * URL: /theme/policy 或 /theme/policy/index
     * 使用默认布局：policy/default.phtml
     */
    public function index()
    {
        $this->renderPolicyLayout('default');
    }
    
    /**
     * 魔术方法：处理动态 action
     * 
     * 支持通过 action 名称指定布局
     * 例如：/theme/policy/cookie -> 使用 policy/cookie.phtml
     * 
     * @param string $method 方法名（即布局名称）
     * @param array $args 参数
     * @return mixed
     */
    public function __call(string $method, array $args)
    {
        // 将方法名作为布局名称
        $layout = $method;
        
        // 验证并渲染布局
        $this->renderPolicyLayout($layout);
    }
    
    /**
     * 渲染政策页面布局
     * 
     * @param string $layout 布局名称
     */
    private function renderPolicyLayout(string $layout): void
    {
        // 验证布局名称，防止路径遍历攻击
        $layout = $this->sanitizeLayoutName($layout);
        
        // 如果布局文件不存在，使用默认布局
        if (!$this->layoutExists($layout)) {
            $layout = 'default';
        }
        
        // 设置页面标题（可以根据布局类型设置不同的标题）
        $titles = [
            'cookie' => 'Cookie 政策',
            'privacy' => '隐私政策',
            'term-condition' => '服务条款',
            'refund' => '退款政策',
            'disclaimer' => '免责声明',
            'default' => '政策页面'
        ];
        
        $title = $titles[$layout] ?? $titles['default'];
        $this->assign('title', $title);
        
        // 设置布局文件路径
        // 布局文件路径：Weline_Theme::theme/frontend/layouts/policy/{布局}.phtml
        $layoutPath = "Weline_Theme::theme/frontend/layouts/policy/{$layout}.phtml";
        
        // 渲染布局
        $this->fetch($layoutPath);
    }
    
    /**
     * 清理和验证布局名称
     * 
     * @param string $layout 布局名称
     * @return string 清理后的布局名称
     */
    private function sanitizeLayoutName(string $layout): string
    {
        // 移除危险字符，只允许字母、数字、连字符和下划线
        $layout = preg_replace('/[^a-zA-Z0-9_-]/', '', $layout);
        
        // 如果清理后为空，返回默认值
        if (empty($layout)) {
            return 'default';
        }
        
        return $layout;
    }
    
    /**
     * 检查布局文件是否存在
     * 
     * @param string $layout 布局名称
     * @return bool
     */
    private function layoutExists(string $layout): bool
    {
        // 定义允许的布局列表（白名单方式更安全）
        $allowedLayouts = [
            'default',
            'cookie',
            'privacy',
            'term-condition',
            'refund',
            'disclaimer'
        ];
        
        return in_array($layout, $allowedLayouts, true);
    }
}
