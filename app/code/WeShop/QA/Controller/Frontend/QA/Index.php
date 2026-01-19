<?php

declare(strict_types=1);

namespace WeShop\QA\Controller\Frontend\QA;

use WeShop\Frontend\Controller\BaseController;
use WeShop\QA\Service\QAService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 商品问答页控制器
 * 
 * 支持1种布局变体：
 * - qa_page_1
 * 
 * 布局变体通过主题配置设置：layouts.qa = qa_page_1
 */
class Index extends BaseController
{
    /**
     * 布局类型
     * Theme模块会根据此类型从主题配置中加载对应的布局
     */
    protected ?string $layoutType = 'qa';
    
    /**
     * 商品问答页
     */
    public function index(): string
    {
        $productId = (int)($this->request->getParam('product_id') ?? 0);
        
        if (!$productId) {
            $this->getMessageManager()->addError(__('产品ID不能为空'));
            return $this->redirect('weshop/product/list');
        }
        
        // 获取问答列表
        $qaList = [];
        try {
            /** @var QAService $qaService */
            $qaService = ObjectManager::getInstance(QAService::class);
            // TODO: 调用问答服务获取问答列表
            // $qaList = $qaService->getProductQA($productId);
        } catch (\Throwable $e) {
            // 问答服务不存在，使用示例数据
            $qaList = [
                [
                    'question' => __('这个产品有保修吗？'),
                    'answer' => __('是的，我们提供一年质保服务。'),
                    'asked_by' => __('用户A'),
                    'answered_by' => __('商家'),
                    'created_at' => date('Y-m-d H:i:s', strtotime('-2 days')),
                ],
                [
                    'question' => __('支持哪些支付方式？'),
                    'answer' => __('我们支持支付宝、微信支付、信用卡等多种支付方式。'),
                    'asked_by' => __('用户B'),
                    'answered_by' => __('商家'),
                    'created_at' => date('Y-m-d H:i:s', strtotime('-5 days')),
                ],
            ];
        }
        
        // 准备模板数据
        $this->assign('product_id', $productId);
        $this->assign('qa_list', $qaList);
        
        // 设置页面标题
        $this->assign('title', __('商品问答'));
        
        // Theme模块会自动根据 layoutType 和主题配置加载对应的布局
        // 布局文件路径：app/design/WeShop/default/frontend/layouts/qa/qa_page_1.phtml
        return $this->fetch();
    }
}
