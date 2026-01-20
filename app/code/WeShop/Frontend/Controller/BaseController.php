<?php

declare(strict_types=1);

namespace WeShop\Frontend\Controller;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\App\State;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Helper\ThemeConfigHelper;
use Weline\Theme\Model\WelineTheme;

/**
 * WeShop 前端控制器基类
 * 
 * 提供统一的布局管理、数据准备等功能
 */
class BaseController extends FrontendController
{
    /**
     * 布局类型
     * 可选值：'homepage', 'product_list', 'product', 'cart', 'checkout', 'checkout_success', 
     *         'account_auth', 'account', 'cms', 'customer_service', 'promotion', 'qa', 'review', 'rma'
     * 
     * @var string|null
     */
    protected ?string $layoutType = null;
    
    /**
     * 布局变体（1-9，根据页面类型不同）
     * 如果为null，则从主题配置中获取
     * 
     * @var int|null
     */
    protected ?int $layoutVariant = null;
    
    /**
     * 当前主题对象
     * 
     * @var WelineTheme|null
     */
    protected ?WelineTheme $currentTheme = null;
    
    public function __init()
    {
        parent::__init();
        
        // 初始化布局
        $this->initLayout();
        
        // 初始化公共数据
        $this->initCommonData();
    }
    
    /**
     * 初始化布局
     */
    protected function initLayout(): void
    {
        // 从请求参数获取布局变体（如果允许）
        $requestVariant = $this->request->getParam('layout');
        if ($requestVariant !== null && is_numeric($requestVariant)) {
            $this->layoutVariant = (int)$requestVariant;
        }
        
        // 如果未设置布局变体，从主题配置获取
        if ($this->layoutVariant === null && $this->layoutType !== null) {
            $this->layoutVariant = $this->getLayoutVariantFromConfig();
        }
        
        // 默认变体为1
        if ($this->layoutVariant === null) {
            $this->layoutVariant = 1;
        }
        
        // 设置布局类型（如果已设置）
        // Theme Observer 会读取 layoutType 属性，所以直接设置即可
        // 注意：layoutType 应该设置为布局类型，如 'cart', 'checkout' 等
        // 布局变体通过主题配置中的 layoutOption 来指定
        
        // 设置布局数据
        $this->assign('layout_variant', $this->layoutVariant);
        $this->assign('meta', [
            'showHeader' => true,
            'showFooter' => true,
            'layoutType' => $this->layoutType,
            'layoutVariant' => $this->layoutVariant,
        ]);
    }
    
    /**
     * 从主题配置获取布局变体
     * 
     * 注意：Theme模块通过layoutOption来指定布局变体
     * 配置格式：layouts.{layoutType} = {layoutOption}
     * 例如：layouts.cart = shopping_cart_page_1
     * 
     * @return int
     */
    protected function getLayoutVariantFromConfig(): int
    {
        try {
            $theme = $this->getCurrentTheme();
            if (!$theme || !$theme->getId()) {
                return 1;
            }
            
            // 从主题配置中获取布局选项
            // Theme模块会自动处理，这里只需要返回默认值
            // 实际的布局选项会从主题配置的 layouts.{layoutType} 中读取
            // 例如：layouts.cart = shopping_cart_page_1
            // Theme Observer 会自动解析并加载对应的布局文件
            
        } catch (\Throwable $e) {
            // 配置获取失败，使用默认值
        }
        
        return 1;
    }
    
    /**
     * 获取当前主题
     * 
     * @return WelineTheme|null
     */
    protected function getCurrentTheme(): ?WelineTheme
    {
        if ($this->currentTheme === null) {
            try {
                /** @var WelineTheme $theme */
                $theme = ObjectManager::getInstance(WelineTheme::class);
                $theme = $theme->getActiveTheme('frontend');
                $this->currentTheme = $theme;
            } catch (\Throwable $e) {
                // 获取主题失败
                $this->currentTheme = null;
            }
        }
        
        return $this->currentTheme;
    }
    
    /**
     * 初始化公共数据
     */
    protected function initCommonData(): void
    {
        // 设置商店名称
        $this->assign('store_name', $this->getStoreName());
        
        // 设置当前语言
        $this->assign('locale', State::getLangLocal());
    }
    
    /**
     * 获取商店名称
     * 
     * @return string
     */
    protected function getStoreName(): string
    {
        // TODO: 从配置或数据库获取商店名称
        return __('WeShop');
    }
    
    /**
     * 获取布局文件路径
     * 
     * @param string|null $layoutType 布局类型，如果为null则使用 $this->layoutType
     * @param int|null $variant 布局变体，如果为null则使用 $this->layoutVariant
     * @return string
     */
    protected function getLayoutPath(?string $layoutType = null, ?int $variant = null): string
    {
        $layoutType = $layoutType ?? $this->layoutType;
        $variant = $variant ?? $this->layoutVariant ?? 1;
        
        if ($layoutType === null) {
            return '';
        }
        
        // 布局文件路径映射
        $layoutPathMap = [
            'homepage' => "homepage/e_commerce_home_page_{$variant}",
            'product_list' => "product_list/product_listing_page_{$variant}",
            'product' => "product/product_detail_page_{$variant}",
            'cart' => "cart/shopping_cart_page_{$variant}",
            'checkout' => "checkout/checkout_page_{$variant}",
            'checkout_success' => "checkout_success/order_confirmation_page_{$variant}",
            'account_auth' => [
                'login' => "account_auth/login_page_{$variant}",
                'register' => "account_auth/sign_up_page_{$variant}",
                'forgot_password' => "account_auth/forgot_password_page_{$variant}",
            ],
            'account' => "account/account_page_{$variant}",
            'cms' => "cms/cms_page_{$variant}",
            'customer_service' => "customer_service/customer_service_page_{$variant}",
            'promotion' => "promotion/promotion_page_{$variant}",
            'qa' => "qa/qa_page_{$variant}",
            'review' => "review/review_page_{$variant}",
            'rma' => "rma/rma_page_{$variant}",
        ];
        
        $layoutPath = $layoutPathMap[$layoutType] ?? null;
        
        if (is_array($layoutPath)) {
            // 对于 account_auth 类型，需要根据具体页面类型选择
            // 从控制器类名推断页面类型
            $controllerClass = get_class($this);
            $authType = 'login'; // 默认
            
            if (strpos($controllerClass, 'Login') !== false) {
                $authType = 'login';
            } elseif (strpos($controllerClass, 'Register') !== false || strpos($controllerClass, 'Create') !== false) {
                $authType = 'register';
            } elseif (strpos($controllerClass, 'ForgotPassword') !== false) {
                $authType = 'forgot_password';
            }
            
            $layoutPath = $layoutPath[$authType] ?? $layoutPath['login'];
        }
        
        if ($layoutPath === null) {
            return '';
        }
        
        // 返回完整布局路径
        return "WeShop_Theme::theme/frontend/layouts/{$layoutPath}.phtml";
    }
    
    /**
     * 渲染布局（重写父类方法以支持 WeShop 布局系统）
     * 
     * @param string $layoutType 布局类型（如果为空字符串，则使用 $this->layoutType）
     * @param string $contentTemplate 内容模板路径（如果为空字符串，则自动推断）
     * @param string|null $title 页面标题
     * @param array $additionalData 额外的模板数据
     * @return string
     */
    protected function renderLayout(
        string $layoutType,
        string $contentTemplate,
        ?string $title = null,
        array $additionalData = []
    ): string {
        // 如果 layoutType 为空且设置了 $this->layoutType，使用 WeShop 布局系统
        if (empty($layoutType) && $this->layoutType !== null) {
            // 自动推断内容模板路径（如果未提供）
            if (empty($contentTemplate)) {
                $controllerClass = get_class($this);
                $moduleName = str_replace('\\', '_', explode('\\', $controllerClass)[0] . '\\' . explode('\\', $controllerClass)[1]);
                $controllerPath = str_replace('Controller\\Frontend\\', '', substr($controllerClass, strpos($controllerClass, 'Controller\\Frontend\\') + strlen('Controller\\Frontend\\')));
                $controllerPath = str_replace('\\', '/', $controllerPath);
                $contentTemplate = "{$moduleName}::templates/frontend/{$controllerPath}/index.phtml";
            }
            
            // 如果设置了 layoutType，Theme Observer 会自动处理布局加载
            // 这里只需要调用 fetch() 即可
            return $this->fetch($contentTemplate);
        }
        
        // 否则调用父类方法
        return parent::renderLayout($layoutType, $contentTemplate, $title, $additionalData);
    }
    
    /**
     * 格式化价格
     * 
     * @param float $price 价格
     * @param string $currency 货币代码
     * @return string
     */
    protected function formatPrice(float $price, string $currency = 'USD'): string
    {
        // TODO: 实现价格格式化逻辑，考虑货币、小数位数等
        return number_format($price, 2) . ' ' . $currency;
    }
}
