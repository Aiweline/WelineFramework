<?php
declare(strict_types=1);

namespace WeShop\Subscription\Extends\Module\Weline_Framework\Query;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Subscription\Service\SubscriptionService;
use Weline\Framework\Http\Url;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

class SubscriptionQueryProvider implements QueryProviderInterface
{
    private const LOGIN_ROUTE = 'customer/account/login';

    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly SubscriptionService $subscriptionService,
        private readonly Url $url
    ) {
    }

    public function getProviderName(): string
    {
        return 'subscription';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'pause' => $this->pause($params),
            'resume' => $this->resume($params),
            'cancel' => $this->cancel($params),
            default => throw new \InvalidArgumentException('Subscription query provider does not support operation: ' . $operation),
        };
    }

    private function pause(array $params): array
    {
        $subscriptionId = $this->readSubscriptionId($params);
        $customerId = $this->currentCustomerId();
        if ($customerId <= 0) {
            return $this->loginRequired();
        }
        if ($subscriptionId <= 0) {
            return $this->failure('Subscription ID is required.');
        }

        try {
            $this->subscriptionService->pauseSubscription($subscriptionId, $customerId);
        } catch (\Throwable) {
            return $this->failure('Unable to pause this subscription right now.');
        }

        return $this->success('Subscription paused.', ['subscription_id' => $subscriptionId]);
    }

    private function resume(array $params): array
    {
        $subscriptionId = $this->readSubscriptionId($params);
        $customerId = $this->currentCustomerId();
        if ($customerId <= 0) {
            return $this->loginRequired();
        }
        if ($subscriptionId <= 0) {
            return $this->failure('Subscription ID is required.');
        }

        try {
            $this->subscriptionService->resumeSubscription($subscriptionId, $customerId);
        } catch (\Throwable) {
            return $this->failure('Unable to resume this subscription right now.');
        }

        return $this->success('Subscription resumed.', ['subscription_id' => $subscriptionId]);
    }

    private function cancel(array $params): array
    {
        $subscriptionId = $this->readSubscriptionId($params);
        $customerId = $this->currentCustomerId();
        if ($customerId <= 0) {
            return $this->loginRequired();
        }
        if ($subscriptionId <= 0) {
            return $this->failure('Subscription ID is required.');
        }

        try {
            $this->subscriptionService->cancelSubscription(
                $subscriptionId,
                $customerId,
                trim((string)($params['reason'] ?? ''))
            );
        } catch (\Throwable) {
            return $this->failure('Unable to cancel this subscription right now.');
        }

        return $this->success('Subscription cancelled.', ['subscription_id' => $subscriptionId]);
    }

    private function currentCustomerId(): int
    {
        return (int)($this->customerContext->getUserId() ?? 0);
    }

    private function readSubscriptionId(array $params): int
    {
        return (int)($params['id'] ?? $params['subscription_id'] ?? 0);
    }

    private function loginRequired(): array
    {
        return $this->failure('Please login first.', [
            'redirect_url' => $this->url->getUrl(self::LOGIN_ROUTE),
        ], 401);
    }

    private function success(string $message, array $data = []): array
    {
        return [
            'success' => true,
            'code' => 200,
            'message' => (string)__($message),
            'msg' => (string)__($message),
            'data' => $data,
        ];
    }

    private function failure(string $message, array $data = [], int $code = 400): array
    {
        return [
            'success' => false,
            'code' => $code,
            'message' => (string)__($message),
            'msg' => (string)__($message),
            'data' => $data,
        ];
    }

    public function getDescriptor(): array
    {
        $idParam = ['type' => 'int', 'required' => true, 'min' => 1];

        return [
            'provider' => 'subscription',
            'name' => 'Subscription frontend worker API',
            'description' => 'Storefront subscription operations for the current customer.',
            'module' => 'WeShop_Subscription',
            'operations' => [
                [
                    'name' => 'pause',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 3,
                    'params' => ['id' => $idParam],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Pause subscription',
                ],
                [
                    'name' => 'resume',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 3,
                    'params' => ['id' => $idParam],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Resume subscription',
                ],
                [
                    'name' => 'cancel',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 4,
                    'params' => [
                        'id' => $idParam,
                        'reason' => ['type' => 'string', 'max_length' => 1000],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Cancel subscription',
                ],
            ],
        ];
    }
}
