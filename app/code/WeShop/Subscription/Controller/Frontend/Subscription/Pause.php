<?php

declare(strict_types=1);

namespace WeShop\Subscription\Controller\Frontend\Subscription;

use Weline\Framework\App\Controller\FrontendController;
use WeShop\Subscription\Service\SubscriptionService;

/**
 * @DESC | 前台暂停/恢复订阅
 */
class Pause extends FrontendController
{
    private SubscriptionService $subscriptionService;

    public function __construct(SubscriptionService $subscriptionService)
    {
        $this->subscriptionService = $subscriptionService;
    }

    /**
     * 暂停订阅
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

            $this->subscriptionService->pauseSubscription($id, $customerId);

            return $this->fetchJson([
                'code' => 200,
                'msg'  => __('订阅已暂停'),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 400,
                'msg'  => __('暂停失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 恢复订阅
     */
    public function postResume(): string
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

            $this->subscriptionService->resumeSubscription($id, $customerId);

            return $this->fetchJson([
                'code' => 200,
                'msg'  => __('订阅已恢复'),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 400,
                'msg'  => __('恢复失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }
}
