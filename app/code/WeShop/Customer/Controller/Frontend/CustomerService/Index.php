<?php

declare(strict_types=1);

namespace WeShop\Customer\Controller\Frontend\CustomerService;

use WeShop\Frontend\Controller\BaseController;

/**
 * 客户服务页控制器
 * 
 * 支持2种布局变体：
 * - customer_service_page_1
 * - customer_service_page_2
 * 
 * 布局变体通过主题配置设置：layouts.customer_service = customer_service_page_1
 */
class Index extends BaseController
{
    /**
     * 布局类型
     * Theme模块会根据此类型从主题配置中加载对应的布局
     */
    protected ?string $layoutType = 'customer_service';
    
    /**
     * 客户服务页
     */
    public function index(): string
    {
        // 常见问题列表
        $faqs = [
            [
                'question' => __('如何下单？'),
                'answer' => __('您可以在产品页面点击"加入购物车"，然后在购物车页面点击"去结账"完成订单。'),
            ],
            [
                'question' => __('如何支付？'),
                'answer' => __('我们支持多种支付方式，包括支付宝、微信支付、PayPal等。'),
            ],
            [
                'question' => __('如何退换货？'),
                'answer' => __('您可以在个人中心的订单页面申请退换货，我们会在收到退货后处理退款。'),
            ],
            [
                'question' => __('配送时间需要多久？'),
                'answer' => __('一般情况下，我们会在1-3个工作日内发货，具体配送时间取决于您所在地区。'),
            ],
            [
                'question' => __('如何联系客服？'),
                'answer' => __('您可以通过在线客服、电话或邮件联系我们，我们会在24小时内回复您。'),
            ],
        ];
        
        // 联系方式
        $contactInfo = [
            'phone' => '400-123-4567',
            'email' => 'service@weshop.com',
            'address' => __('北京市朝阳区xxx街道xxx号'),
            'working_hours' => __('周一至周五 9:00-18:00'),
        ];
        
        // 准备模板数据
        $this->assign('faqs', $faqs);
        $this->assign('contact_info', $contactInfo);
        
        // 设置页面标题
        $this->assign('title', __('客户服务'));
        
        // Theme模块会自动根据 layoutType 和主题配置加载对应的布局
        // 布局文件路径：app/design/WeShop/default/frontend/layouts/customer_service/customer_service_page_{variant}.phtml
        return $this->fetch();
    }
}
