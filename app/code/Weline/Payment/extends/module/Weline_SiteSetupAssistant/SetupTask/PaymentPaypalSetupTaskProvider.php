<?php

declare(strict_types=1);

namespace Weline\Payment\Extends\Module\Weline_SiteSetupAssistant\SetupTask;

use Weline\Framework\Manager\ObjectManager;
use Weline\SiteSetupAssistant\Api\AbstractSetupTaskProvider;

/**
 * 建站任务：PayPal 收款
 */
class PaymentPaypalSetupTaskProvider extends AbstractSetupTaskProvider
{
    public function provideTasks(array $context = []): array
    {
        $scope = $this->resolveStorageScope($context);
        $href = $this->systemConfigPath('Weline_Payment', 'backend', 'payment/method/paypal/enabled,payment/method/paypal/live_client_id', (string)__('PayPal'));
        $done = $this->anyConfigFilled('Weline_Payment', 'backend', ['payment/method/paypal/enabled', 'payment/method/paypal/live_client_id', 'payment/method/paypal/sandbox_client_id'], $scope);
        $status = $done ? 'done' : 'todo';
        $tip = $done
            ? (string)__('已检测到 PayPal 已启用或 Live Client。')
            : (string)__('沙盒连通后切 Live；迁站核对 return/cancel/webhook。');

        return $this->tasks([[
            'code' => 'paypal',
            'sort' => 70,
            'category' => (string)__('支付'),
            'module' => 'Weline_Payment',
            'title' => (string)__('PayPal 收款'),
            'tip' => $tip,
            'status' => $status,
            'href' => $href,
            'scenarios' => ['new', 'migrate'],
            'meta' => ['configured' => $done],
        ]]);
    }
}
