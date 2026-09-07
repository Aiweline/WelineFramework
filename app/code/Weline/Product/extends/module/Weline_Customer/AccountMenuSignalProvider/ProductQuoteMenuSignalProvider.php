<?php

declare(strict_types=1);

namespace Weline\Product\Extends\Module\Weline_Customer\AccountMenuSignalProvider;

use Weline\Customer\Api\AccountMenuSignalProviderInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\ProductQuoteRequest;
use Weline\Product\Service\ProductQuoteRequestService;

/**
 * Unread account-menu badge for product.quotes (JS-painted only; never SSR).
 */
final class ProductQuoteMenuSignalProvider implements AccountMenuSignalProviderInterface
{
    public function code(): string
    {
        return ProductQuoteRequest::MENU_SIGNAL_CODE;
    }

    public function count(int $customerId, int $websiteId): int
    {
        unset($websiteId);
        $customerId = max(0, $customerId);
        if ($customerId <= 0) {
            return 0;
        }

        try {
            /** @var ProductQuoteRequestService $service */
            $service = ObjectManager::getInstance(ProductQuoteRequestService::class);

            return max(0, $service->countUnreadForCustomer($customerId));
        } catch (\Throwable) {
            return 0;
        }
    }
}
