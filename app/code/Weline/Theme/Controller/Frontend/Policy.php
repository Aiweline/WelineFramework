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

/**
 * 政策/法律壳（店面）。
 *
 * @Extra type=fpc enabled=true ttl=1800 namespaces=website/default/theme public_path_patterns=/about,/sitemap,/guide,/qa,/activity,/policy,/policy/*,/terms,/theme/policy/*
 */
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
        if ((string)$this->request->getParam('layout_type', '') !== '') {
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
        if ($title === '' && $layoutType === 'policy') {
            $policyTitles = [
                'cookie' => 'Cookie 政策',
                'privacy' => '隐私政策',
                'term-condition' => '服务条款',
                'refund' => '退款政策',
                'disclaimer' => '免责声明',
                'shipping' => '配送政策',
                'accessibility' => '无障碍声明',
                'default' => '政策页面',
            ];
            $title = $policyTitles[$layoutOption] ?? $policyTitles['default'];
        }
        if ($title === '' && $layoutType === 'error') {
            $title = '服务异常';
        }
        if ($title === '' && $layoutType === 'sitemap') {
            $title = '站点地图';
        }
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
            'shipping' => '配送政策',
            'accessibility' => '无障碍声明',
            'default' => '政策页面'
        ];
        
        $title = $titles[$layout] ?? $titles['default'];
        $translatedTitle = \Weline\Theme\Helper\WidgetI18n::label($title);
        $this->assign('title', $translatedTitle);
        $this->assignThemeShellSeo($translatedTitle, 'policy');
        
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
        // Nested layout types (checkout/success, checkout/failure, account/login) need `/`.
        $layout = strtolower(trim(str_replace('\\', '/', $layout), '/'));
        $layout = (string)preg_replace('/[^a-z0-9_\/-]+/', '', $layout);
        $layout = (string)preg_replace('#/+#', '/', $layout);

        if ($layout === '' || $layout === '/') {
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
            'disclaimer',
            'shipping',
            'accessibility',
        ];
        
        return in_array($layout, $allowedLayouts, true);
    }

    private function renderThroughThemeLayout(): string
    {
        $layoutType = $this->sanitizeLayoutName((string)$this->request->getParam('layout_type', 'policy'));
        $layoutOption = $this->sanitizeLayoutName((string)$this->request->getParam('layout_option', 'default'));
        if (str_contains($layoutType, '.')) {
            [$type, $option] = explode('.', $layoutType, 2);
            $layoutType = $type !== '' ? $type : 'policy';
            if ($layoutOption === 'default' && $option !== '') {
                $layoutOption = $option;
            }
        }

        return (string)$this->fetch(
            'Weline_Theme::theme/frontend/layouts/' . $layoutType . '/' . $layoutOption . '.phtml'
        );
    }

    /**
     * Publish Theme shell structural SEO (page_type / robots / breadcrumbs).
     * Title/description defaults come from layout explicit SEO fallback + providers,
     * so ops layout meta_title/meta_description can win over hardcoded shell copy.
     * BreadcrumbList needs ≥2 ListItems for Google; shells always emit Home → current page.
     */
    private function assignThemeShellSeo(string $title, string $pageType): void
    {
        $title = trim($title);
        $pageType = strtolower(trim(str_replace(['-', ' '], '_', $pageType)));
        if ($pageType === '') {
            $pageType = 'web_page';
        }

        $privateTypes = [
            // Canonical slash paths; underscore aliases kept for legacy page_type strings still in SEO bags.
            'cart', 'checkout', 'checkout/success', 'checkout/failure', 'checkout_success', 'checkout_failure', 'checkout_failer',
            'account',
            'error',
            'not_found',
        ];
        $robots = in_array($pageType, $privateTypes, true) ? 'noindex,follow' : 'index,follow';
        if ($pageType === 'error' || $pageType === 'not_found') {
            $robots = 'noindex,nofollow';
        }

        if ($title === '') {
            $title = $this->defaultShellPageTitle($pageType);
        }

        $seo = [
            'page_type' => $pageType,
            'robots' => $robots,
        ];
        if (!in_array($pageType, $privateTypes, true)) {
            $seo['breadcrumbs'] = [
                ['name' => \Weline\Theme\Helper\WidgetI18n::label('首页'), 'url' => '/'],
                ['name' => $title, 'url' => ''],
            ];
            // Visible Theme breadcrumb partial reads template `breadcrumbs` (not only the seo bag).
            $this->assign('breadcrumbs', $seo['breadcrumbs']);
        }

        $this->assign('seo', $seo);
        // Keep visible/page chrome title; do not put it into the entity SEO bag.
        $this->assign('title', $title);
        if ($title !== '' && class_exists(\Weline\Seo\Service\Head\SeoPageProfileBag::class)) {
            try {
                \Weline\Seo\Service\Head\SeoPageProfileBag::publish(['title' => $title]);
            } catch (\Throwable) {
            }
        }
    }

    private function defaultShellPageTitle(string $pageType): string
    {
        $titles = [
            'about' => '关于我们',
            'contact' => '联系我们',
            'faq' => 'FAQ/常见问题',
            'journal' => '道记',
            'sanctuary' => '无为空间',
            'shop' => '选购',
            'lounge' => '道家居服',
            'terms' => '服务条款',
            'guide' => '指南',
            'payment_guide' => '支付方式指南',
            'policy' => '政策页面',
            'privacy' => '隐私政策',
            'error' => '服务异常',
            'sitemap' => '站点地图',
            'cart' => '购物车',
            'account' => '账户中心',
            'checkout/failure' => '订单尚未完成',
            'checkout_failure' => '订单尚未完成',
            'checkout_failer' => '订单尚未完成',
            'checkout/success' => '结账成功',
            'checkout_success' => '结账成功',
        ];

        $source = $titles[$pageType] ?? '页面';

        return \Weline\Theme\Helper\WidgetI18n::label($source);
    }

    private function publicLayoutExists(string $layoutType, string $layoutOption): bool
    {
        $allowedLayouts = [
            'account' => ['default'],
            'activity' => ['default'],
            'cart' => ['default', 'empty'],
            'category' => ['default', 'list'],
            'checkout' => ['default', 'one-page'],
            'checkout/success' => ['default'],
            'checkout/failure' => ['default'],
            'cms_page' => ['default'],
            'contact' => ['default'],
            'about' => ['default'],
            'error' => ['default'],
            'faq' => ['default'],
            'guide' => ['default'],
            'payment_guide' => ['default'],
            'journal' => ['default'],
            'sanctuary' => ['default'],
            'shop' => ['default'],
            'lounge' => ['default'],
            'not_found' => ['default'],
            'promotion' => ['default'],
            'qa' => ['default'],
            'sitemap' => ['default'],
            'terms' => ['default'],
            'default' => ['default'],
            'policy' => [
                'default',
                'cookie',
                'privacy',
                'term-condition',
                'refund',
                'disclaimer',
                'shipping',
                'accessibility',
            ],
            'product' => ['default'],
            'products' => ['default'],
            'rma' => ['default'],
            'search' => ['default'],
        ];

        return isset($allowedLayouts[$layoutType])
            && in_array($layoutOption, $allowedLayouts[$layoutType], true);
    }
}
