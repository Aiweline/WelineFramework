<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Payment\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Service\PaymentService;

class Callback extends FrontendController
{
    private PaymentService $paymentService;

    public function __construct(
        ObjectManager $objectManager
    ) {
        $this->paymentService = $objectManager->getInstance(PaymentService::class);
    }

    /**
     * 支付回调通知
     */
    public function notify()
    {
        $methodCode = $this->request->getParam('method_code');
        
        if (!$methodCode) {
            // 尝试从请求数据中获取
            $methodCode = $this->request->getBodyParam('method_code');
        }
        
        if (!$methodCode) {
            http_response_code(400);
            echo __('缺少支付方式代码');
            return;
        }
        
        // 获取所有回调数据
        $callbackData = array_merge(
            $this->request->getParams(),
            $this->request->getBodyParams()
        );
        
        try {
            $transaction = $this->paymentService->handleCallback($methodCode, $callbackData);
            
            if ($transaction && $transaction->isSuccess()) {
                // 支付成功，触发订单处理事件
                // TODO: 触发订单支付成功事件
                
                http_response_code(200);
                echo 'success';
            } else {
                http_response_code(200);
                echo 'fail';
            }
        } catch (\Exception $e) {
            w_log_error('支付回调处理失败: ' . $e->getMessage());
            http_response_code(500);
            echo 'error';
        }
    }
}

