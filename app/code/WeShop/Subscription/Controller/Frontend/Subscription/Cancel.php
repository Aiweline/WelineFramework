<?php

declare(strict_types=1);

namespace WeShop\Subscription\Controller\Frontend\Subscription;

use Weline\Framework\App\Controller\FrontendController;
use WeShop\Subscription\Service\SubscriptionService;

/**
 * @DESC | 前台取消订阅
 */
class Cancel extends FrontendController
{
    private SubscriptionService $subscriptionService;

    public function __construct(SubscriptionService $subscriptionService)
    {
        $this->subscriptionService = $subscriptionService;
    }

    /**
     * 取消订阅
     */
    public function postIndex(): string
    {
        try {
            $customerId = (int)$this->session->getLoginCustomerId();

            if (!$customerId) {
                return $this->fetchJson([
                    'code' => 401,
                    'msg'  => __('请先登录'),
                ]);
            }

            $id = (int)$this->request->getPost('id');
            $reason = (string)$this->request->getPost('reason');

            $this->subscriptionService->cancelSubscription($id, $customerId, $reason);

            return $this->fetchJson([
                'code' => 200,
                'msg'  => __('订阅已取消'),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 400,
                'msg'  => __('取消失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }
}
