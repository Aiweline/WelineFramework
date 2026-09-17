<?php

declare(strict_types=1);

namespace Weline\Marketing\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Marketing\Service\WelcomeLifecycleService;

final class CustomerRegisteredWelcomeObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        if (\is_object($data) && \method_exists($data, 'getData')) {
            $data = [
                'customer_id' => (int)($data->getData('customer_id') ?? 0),
                'customer' => $data->getData('customer'),
                'user' => $data->getData('user'),
                'email' => (string)($data->getData('email') ?? ''),
                'website_id' => (int)($data->getData('website_id') ?? 0),
                'locale' => (string)($data->getData('locale') ?? ''),
                'name' => (string)($data->getData('name') ?? ''),
            ];
        }
        if (!\is_array($data)) {
            return;
        }

        $customerId = (int)($data['customer_id'] ?? 0);
        $customer = $data['customer'] ?? $data['user'] ?? null;
        if ($customerId <= 0 && \is_object($customer) && \method_exists($customer, 'getId')) {
            $customerId = (int)$customer->getId();
        }
        $email = \trim((string)($data['email'] ?? ''));
        if ($email === '' && \is_object($customer) && \method_exists($customer, 'getEmail')) {
            $email = \trim((string)$customer->getEmail());
        }
        $name = \trim((string)($data['name'] ?? ''));
        if ($name === '' && \is_object($customer)) {
            if (\method_exists($customer, 'getName')) {
                $name = \trim((string)$customer->getName());
            } elseif (\method_exists($customer, 'getData')) {
                $name = \trim((string)($customer->getData('firstname') ?? $customer->getData('name') ?? ''));
            }
        }
        $websiteId = (int)($data['website_id'] ?? 0);
        if ($websiteId <= 0 && \is_object($customer) && \method_exists($customer, 'getData')) {
            $websiteId = (int)($customer->getData('website_id') ?? 0);
        }

        try {
            /** @var WelcomeLifecycleService $service */
            $service = ObjectManager::getInstance(WelcomeLifecycleService::class);
            $service->handleRegistration([
                'customer_id' => $customerId,
                'email' => $email,
                'name' => $name,
                'website_id' => $websiteId,
                'locale' => (string)($data['locale'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            if (\function_exists('w_log_error')) {
                w_log_error('WelcomeLifecycle failed: ' . $e->getMessage(), [], 'marketing_lifecycle');
            }
        }
    }
}
