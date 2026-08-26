<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Product\Api\ProductDownloadEntitlementException;
use Weline\Framework\Session\SessionFactory;

final class ProductCurrentCustomerResolver
{
    public function __construct(
        private readonly ?SessionFactory $sessions = null,
    ) {
    }

    public function currentCustomerId(): int
    {
        try {
            $session = ($this->sessions ?? SessionFactory::getInstance())->createFrontendSession();
            if (!$session->isLoggedIn()) {
                return 0;
            }
            return max(0, (int)($session->getUserId() ?? $session->getUser()?->getId() ?? 0));
        } catch (\Throwable) {
            return 0;
        }
    }

    public function requireCustomerId(): int
    {
        $customerId = $this->currentCustomerId();
        if ($customerId < 1) {
            throw new ProductDownloadEntitlementException(
                'download_customer_required',
                (string)__('请先登录后访问下载权益'),
            );
        }
        return $customerId;
    }
}
