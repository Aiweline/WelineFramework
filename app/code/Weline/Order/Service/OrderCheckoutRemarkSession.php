<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;

class OrderCheckoutRemarkSession
{
    private const SESSION_KEY = 'weline_order_checkout_remark';

    public function __construct(private readonly SessionFactory $sessionFactory)
    {
    }

    public function getRemark(): string
    {
        return trim((string)$this->frontendSession()->getData(self::SESSION_KEY));
    }

    public function saveRemark(string $remark): array
    {
        $normalized = trim($remark);
        if (mb_strlen($normalized) > 1000) {
            return ['success' => false, 'message' => (string)__('订单留言过长，请控制在 1000 字以内')];
        }

        $this->frontendSession()->setData(self::SESSION_KEY, $normalized);

        return [
            'success' => true,
            'remark' => $normalized,
            'message' => (string)__('订单留言已保存，将在结账时带入'),
        ];
    }

    public function clearRemark(): array
    {
        $this->frontendSession()->delete(self::SESSION_KEY);

        return ['success' => true, 'message' => (string)__('已清除订单留言')];
    }

    private function frontendSession(): AuthenticatedSessionInterface
    {
        return $this->sessionFactory->createFrontendSession();
    }
}
