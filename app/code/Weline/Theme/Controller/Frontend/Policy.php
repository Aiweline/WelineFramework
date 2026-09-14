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
        if ((string)$this->request->getParam('theme_public_route', '') !== '') {
            return $this->renderPublicThemeLayout();
        }

        return $this->renderPolicyLayout('default');
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
        return $this->renderPolicyLayout($layout);
    }

    private function renderPublicThemeLayout(): string
    {
        $layoutType = $this->sanitizeLayoutName((string)$this->request->getParam('layout_type', 'default'));
        $layoutOption = $this->sanitizeLayoutName((string)$this->request->getParam('layout_option', 'default'));

        if (!$this->publicLayoutExists($layoutType, $layoutOption)) {
            $layoutType = 'default';
            $layoutOption = 'default';
        }

        $this->layoutType = $layoutType . '.' . $layoutOption;
        $this->request->setGet('layout_type', $layoutType);
        $this->request->setGet('page_type', $layoutType);
        $this->request->setGet('layout_option', $layoutOption);

        // Controller metadata is translated before Template::fetchHtml() can
        // register the source module, so bind Theme to this request explicitly.
        $this->request->addModule('Weline_Theme');

        $title = trim((string)$this->request->getParam('theme_page_title', ''));
        if ($title !== '') {
            $this->assign('title', \Weline\Theme\Helper\WidgetI18n::label($title));
        }
        $this->assignThemeShellSeo(
            $title !== '' ? \Weline\Theme\Helper\WidgetI18n::label($title) : '',
            (string)$layoutType,
        );

        return $this->renderThroughThemeLayout();
    }
    
    /**
     * 渲染政策页面布局
     * 
     * @param string $layout 布局名称
     */
    private function renderPolicyLayout(string $layout): string
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
        $this->assignThemeShellSeo((string)__($title), 'policy');
        
        $this->layoutType = 'policy.' . $layout;
        $this->request->setGet('page_type', 'policy');
        $this->request->setGet('layout_type', 'policy');
        $this->request->setGet('layout_option', $layout);

        return $this->renderThroughThemeLayout();
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

    private function renderThroughThemeLayout(): string
    {
        return (string)$this->fetch('Weline_Theme::templates/frontend/theme-preview/content.phtml');
    }

    /**
     * Publish Theme shell SEO facts so head survives layout unsetData().
     */
    private function assignThemeShellSeo(string $title, string $pageType): void
    {
        $title = trim($title);
        $pageType = strtolower(trim(str_replace(['-', ' '], '_', $pageType)));
        if ($title === '') {
            $title = (string)__('页面');
        }
        if ($pageType === '') {
            $pageType = 'web_page';
        }

        $privateTypes = [
            'cart', 'checkout', 'checkout_success', 'checkout_failure', 'checkout_failer',
            'account', 'account_auth', 'account_logout', 'account_orders', 'account_profile',
        ];
        $robots = in_array($pageType, $privateTypes, true) ? 'noindex,follow' : 'index,follow';

        $descriptions = [
            'about' => (string)__('了解云裳汉服品牌故事、匠心工艺与传统服饰选购理念。'),
            'contact' => (string)__('联系云裳汉服客服，咨询订单、定制与批发合作。'),
            'policy' => (string)__('阅读本站隐私、Cookie、退款与相关法律政策说明。'),
            'privacy' => (string)__('了解我们如何收集、使用与保护您的个人信息。'),
            'terms' => (string)__('阅读使用本站服务前需要了解的条款与条件。'),
            'guide' => (string)__('查看配送、退换与购物相关说明，帮助顺利完成汉服选购。'),
            'payment_guide' => (string)__('了解可用支付方式、账单与安全保障说明。'),
            'faq' => (string)__('查找订单、物流、退换与账户相关常见问题。'),
            'cart' => (string)__('查看已选汉服商品、调整数量并进入结算。'),
            'account_auth' => (string)__('登录或注册账户，管理订单与收藏。'),
        ];
        $description = $descriptions[$pageType]
            ?? ((string)__('浏览%{1}相关信息，了解汉服选购与服务说明。', [$title]));

        $this->assign('seo', [
            'page_type' => $pageType,
            'title' => $title,
            'description' => $description,
            'robots' => $robots,
        ]);
    }

    private function publicLayoutExists(string $layoutType, string $layoutOption): bool
    {
        $allowedLayouts = [
            'account' => ['default'],
            'account_auth' => ['default'],
            'account_logout' => ['default'],
            'account_orders' => ['default'],
            'account_profile' => ['default'],
            'activity' => ['default'],
            'cart' => ['default', 'empty'],
            'category' => ['default', 'list'],
            'checkout' => ['default', 'one-page'],
            'checkout_failure' => ['default'],
            'checkout_failer' => ['default'],
            'checkout_success' => ['default'],
            'cms_page' => ['default'],
            'contact' => ['default'],
            'about' => ['default'],
            'faq' => ['default'],
            'guide' => ['default'],
            'payment_guide' => ['default'],
            'not_found' => ['default'],
            'promotion' => ['default'],
            'qa' => ['default'],
            'terms' => ['default'],
            'default' => ['default'],
            'policy' => ['default', 'cookie', 'privacy', 'term-condition', 'refund', 'disclaimer'],
            'product' => ['default'],
            'product_list' => ['default'],
            'review' => ['default'],
            'rma' => ['default'],
            'search' => ['default'],
        ];

        return isset($allowedLayouts[$layoutType])
            && in_array($layoutOption, $allowedLayouts[$layoutType], true);
    }
}
