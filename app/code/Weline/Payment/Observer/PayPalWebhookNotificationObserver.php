<?php

declare(strict_types=1);

namespace Weline\Payment\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Service\PayPalWebhookNotificationService;
use Weline\Payment\Service\PaymentCallbackReceiver;

final class PayPalWebhookNotificationObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        if (!\is_array($data)) {
            return;
        }

        $inboxCode = trim((string) ($data['inbox_code'] ?? ''));
        if ($inboxCode === '') {
            return;
        }

        try {
            $receiver = ObjectManager::getInstance(PaymentCallbackReceiver::class);
            $inbox = $receiver->getInbox($inboxCode);
            if ($inbox === null) {
                return;
            }
            ObjectManager::getInstance(PayPalWebhookNotificationService::class)
                ->recordFromInbox($inbox);
        } catch (\Throwable $throwable) {
            if (\function_exists('w_log_error')) {
                w_log_error('[PayPal] webhook notification record failed: ' . $throwable->getMessage());
            }
        }
    }
}
