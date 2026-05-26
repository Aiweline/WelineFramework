<?php

declare(strict_types=1);

namespace WeShop\Payment\Provider;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Interface\PaymentConfigTesterInterface;
use WeShop\Order\Model\Order;
use WeShop\Payment\Interface\PaymentProviderInterface;

class CreditLineEvent implements PaymentProviderInterface, PaymentConfigTesterInterface
{
    use ProviderContextHelperTrait;

    public const EVENT_NAME = 'WeShop_Payment::credit_payment_requested';

    public function __construct(
        private readonly ?EventsManager $eventsManager = null
    ) {
    }

    public function processPayment(Order $order, array $paymentData = [], array $context = []): array
    {
        $orderNumber = $this->readOrderNumber($order);
        $eventData = [
            'order' => $order,
            'order_number' => $orderNumber,
            'payment_data' => $paymentData,
            'context' => $context,
            'accepted' => false,
            'status' => 'pending',
            'provider_reference' => '',
            'message' => '',
        ];

        $this->getEventsManager()->dispatch(self::EVENT_NAME, $eventData);

        return [
            'status' => (string) ($eventData['status'] ?? 'pending'),
            'requires_action' => false,
            'redirect_url' => '',
            'provider_reference' => (string) ($eventData['provider_reference'] ?? ''),
            'event_name' => self::EVENT_NAME,
            'event_accepted' => (bool) ($eventData['accepted'] ?? false),
            'message' => (string) ($eventData['message'] ?? ''),
            'payment_params' => [
                'order_reference' => $orderNumber,
                'credit_event' => self::EVENT_NAME,
            ],
        ];
    }

    public function handleCallback(array $callbackData, array $context = []): bool
    {
        return true;
    }

    public function queryPaymentStatus(string $orderNumber, array $context = []): string
    {
        return (string) ($context['status'] ?? 'pending');
    }

    public function testConfig(array $config, array $context = []): array
    {
        return [
            'success' => true,
            'message' => (string) __('Credit line payment uses event dispatch only; listener-side credit policy is validated outside payment configuration.'),
            'details' => ['event_name' => self::EVENT_NAME],
        ];
    }

    private function getEventsManager(): EventsManager
    {
        return $this->eventsManager ?? ObjectManager::getInstance(EventsManager::class);
    }
}
