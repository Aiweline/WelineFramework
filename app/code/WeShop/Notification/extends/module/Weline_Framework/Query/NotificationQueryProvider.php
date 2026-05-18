<?php
declare(strict_types=1);

namespace WeShop\Notification\Extends\Module\Weline_Framework\Query;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Notification\Service\NotificationService;
use Weline\Framework\Http\Url;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

class NotificationQueryProvider implements QueryProviderInterface
{
    private const LOGIN_ROUTE = 'customer/account/login';

    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly NotificationService $notificationService,
        private readonly Url $url
    ) {
    }

    public function getProviderName(): string
    {
        return 'notification';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'markRead' => $this->markRead($params),
            default => throw new \InvalidArgumentException(
                (string)__('Unsupported notification provider operation: %{1}', $operation)
            ),
        };
    }

    private function markRead(array $params): array
    {
        $customerId = (int)($this->customerContext->getUserId() ?? 0);
        if ($customerId <= 0) {
            return [
                'success' => false,
                'message' => (string)__('Please log in to continue.'),
                'data' => ['redirect_url' => $this->url->getUrl(self::LOGIN_ROUTE)],
            ];
        }

        $notificationId = (int)($params['notification_id'] ?? $params['item_id'] ?? 0);
        if ($notificationId <= 0) {
            return [
                'success' => false,
                'message' => (string)__('Notification ID is required.'),
            ];
        }

        if (!$this->notificationService->markAsRead($notificationId, $customerId)) {
            return [
                'success' => false,
                'message' => (string)__('Notification could not be marked as read.'),
            ];
        }

        return [
            'success' => true,
            'message' => (string)__('Notification marked as read.'),
            'data' => [
                'notification_id' => $notificationId,
                'unread_count' => $this->notificationService->getUnreadCount($customerId),
            ],
        ];
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'notification',
            'name' => __('Notification Query'),
            'description' => __('Provides frontend notification operations through the worker API.'),
            'module' => 'WeShop_Notification',
            'operations' => [
                [
                    'name' => 'markRead',
                    'description' => __('Mark a notification owned by the current customer as read.'),
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 2,
                    'params' => [
                        'notification_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Mark notification as read',
                ],
            ],
        ];
    }
}
