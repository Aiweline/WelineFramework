<?php

declare(strict_types=1);

namespace Weline\B2B\Extends\Module\Weline_Customer\AccountMenuSignalProvider;

use Weline\B2B\Model\B2BOrderThreadRecord;
use Weline\B2B\Service\B2BOrderThreadService;
use Weline\Customer\Api\AccountMenuSignalProviderInterface;
use Weline\Framework\Manager\ObjectManager;

/** Unread badge for b2b.order_chat (JS-painted only; never SSR). */
final class B2BOrderChatMenuSignalProvider implements AccountMenuSignalProviderInterface
{
    public function code(): string
    {
        return B2BOrderThreadRecord::MENU_SIGNAL_CODE;
    }

    public function count(int $customerId, int $websiteId): int
    {
        $customerId = max(0, $customerId);
        if ($customerId <= 0) {
            return 0;
        }
        try {
            $service = ObjectManager::getInstance(B2BOrderThreadService::class);
            if (!$service instanceof B2BOrderThreadService) {
                return 0;
            }

            return max(0, $service->countUnreadForCustomer($customerId, $websiteId));
        } catch (\Throwable) {
            return 0;
        }
    }
}
