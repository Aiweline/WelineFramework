<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Observer;

use WeShop\Affiliate\Service\AffiliateService;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class CustomerRegisteredObserver implements ObserverInterface
{
    public function __construct(
        private readonly AffiliateService $affiliateService
    ) {
    }

    public function execute(Event &$event): void
    {
        $data = $event->getData();
        if (is_object($data) && method_exists($data, 'getData')) {
            $data = [
                'customer_id' => (int) ($data->getData('customer_id') ?? 0),
                'customer' => $data->getData('customer'),
                'user' => $data->getData('user'),
                'email' => (string) ($data->getData('email') ?? ''),
                'profile_data' => $data->getData('profile_data') ?: [],
                'referral_code' => (string) ($data->getData('referral_code') ?? ''),
                'created_at' => (string) ($data->getData('created_at') ?? ''),
            ];
        }

        if (!is_array($data)) {
            return;
        }

        if (empty($data['customer_id'])) {
            $customer = $data['customer'] ?? $data['user'] ?? null;
            if (is_object($customer) && method_exists($customer, 'getId')) {
                $data['customer_id'] = (int) $customer->getId();
            }
        }

        $this->affiliateService->handleCustomerRegistered($data);
    }
}
