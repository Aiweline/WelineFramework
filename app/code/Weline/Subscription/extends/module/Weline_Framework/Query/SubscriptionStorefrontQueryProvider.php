<?php

declare(strict_types=1);

namespace Weline\Subscription\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Subscription\Model\SubscriptionState;
use Weline\Subscription\Service\SubscriptionBuyerService;
use Weline\Subscription\Service\SubscriptionConflictException;

/**
 * Buyer subscribe / renew / cancel. Identity comes from the storefront session
 * and the frozen request scope, never from client-supplied customer or website ids.
 */
final class SubscriptionStorefrontQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly SubscriptionBuyerService $buyers,
        private readonly SessionFactory $sessionFactory,
    ) {
    }

    public function getProviderName(): string
    {
        return 'subscription';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        try {
            return match ($operation) {
                'listMine' => $this->listMine(),
                'subscribe' => $this->subscribe($params),
                'renew' => $this->renew($params),
                'cancel' => $this->cancel($params),
                default => throw new \InvalidArgumentException((string) __('订阅接口不支持操作：%{1}', [$operation])),
            };
        } catch (SubscriptionConflictException $exception) {
            return $this->failure($exception->errorCode, $exception->getMessage());
        } catch (\InvalidArgumentException $exception) {
            return $this->failure('subscription_buyer_invalid', $exception->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function listMine(): array
    {
        $customerId = $this->customerId();
        if ($customerId === '') {
            return $this->failure('subscription_buyer_login_required', (string) __('请先登录后再查看订阅'));
        }
        $scope = $this->scope();

        return [
            'success' => true,
            'subscriptions' => $this->buyers->listForCustomer($customerId, $scope['website_id']),
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function subscribe(array $params): array
    {
        $customerId = $this->customerId();
        if ($customerId === '') {
            return $this->failure('subscription_buyer_login_required', (string) __('请先登录后再开通订阅'));
        }
        $scope = $this->scope();
        $providerCode = trim((string) ($params['provider_code'] ?? ''));
        $planCode = trim((string) ($params['plan_code'] ?? ''));
        $idempotencyKey = trim((string) ($params['idempotency_key'] ?? ''));
        if (preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $providerCode) !== 1) {
            return $this->failure('subscription_buyer_provider_invalid', (string) __('计费方案不可用'));
        }
        if ($planCode === '' || strlen($planCode) > 128 || preg_match('/[\x00-\x1F\x7F]/', $planCode) === 1) {
            return $this->failure('subscription_buyer_plan_invalid', (string) __('请填写有效的方案代码'));
        }
        if (preg_match('/^[A-Za-z0-9_.:-]{8,128}$/D', $idempotencyKey) !== 1) {
            return $this->failure('subscription_buyer_idempotency_invalid', (string) __('订阅请求无效，请刷新页面后重试'));
        }

        return $this->buyers->subscribe([
            'customer_id' => $customerId,
            'website_id' => $scope['website_id'],
            'store_id' => $scope['store_id'],
            'provider_code' => $providerCode,
            'plan_code' => $planCode,
            'idempotency_key' => $idempotencyKey,
            'environment' => SubscriptionState::ENV_SANDBOX,
        ]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function renew(array $params): array
    {
        $customerId = $this->customerId();
        if ($customerId === '') {
            return $this->failure('subscription_buyer_login_required', (string) __('请先登录后再续费'));
        }

        return $this->buyers->renew($this->subscriptionId($params), $customerId);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function cancel(array $params): array
    {
        $customerId = $this->customerId();
        if ($customerId === '') {
            return $this->failure('subscription_buyer_login_required', (string) __('请先登录后再取消订阅'));
        }
        $version = (int) ($params['expected_version'] ?? 0);
        if ($version < 1) {
            return $this->failure('subscription_buyer_version_required', (string) __('订阅已变化，请刷新页面后重试'));
        }

        return $this->buyers->cancel($this->subscriptionId($params), $customerId, $version);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function subscriptionId(array $params): string
    {
        $subscriptionId = trim((string) ($params['subscription_id'] ?? ''));
        if ($subscriptionId === '' || strlen($subscriptionId) > 64 || preg_match('/^[A-Za-z0-9_-]+$/D', $subscriptionId) !== 1) {
            throw new \InvalidArgumentException((string) __('订阅编号无效'));
        }

        return $subscriptionId;
    }

    private function customerId(): string
    {
        $session = $this->sessionFactory->createFrontendSession();
        if (!$session->isLoggedIn()) {
            return '';
        }
        $id = $session->getUserId();
        if ($id === null || $id === '' || $id === 0 || $id === '0') {
            return '';
        }

        return trim((string) $id);
    }

    /**
     * @return array{website_id:int,store_id:int}
     */
    private function scope(): array
    {
        return [
            'website_id' => RequestContext::getWelineWebsiteId(),
            'store_id' => max(0, RequestContext::getWelineStoreId()),
        ];
    }

    /**
     * @return array{success:false,error_code:string,message:string}
     */
    private function failure(string $errorCode, string $message): array
    {
        return [
            'success' => false,
            'error_code' => $errorCode,
            'message' => $message,
        ];
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'subscription',
            'name' => (string) __('买家订阅'),
            'description' => (string) __('登录顾客开通、续费和取消自己的订阅。'),
            'module' => 'Weline_Subscription',
            'operations' => [
                [
                    'name' => 'listMine',
                    'frontend' => true,
                    'auth' => 'customer',
                    'mode' => 'read',
                    'params' => [],
                ],
                [
                    'name' => 'subscribe',
                    'frontend' => true,
                    'auth' => 'customer',
                    'mode' => 'write',
                    'params' => [
                        'provider_code' => ['type' => 'string', 'required' => true, 'max_length' => 64],
                        'plan_code' => ['type' => 'string', 'required' => true, 'max_length' => 128],
                        'idempotency_key' => ['type' => 'string', 'required' => true, 'max_length' => 128],
                    ],
                ],
                [
                    'name' => 'renew',
                    'frontend' => true,
                    'auth' => 'customer',
                    'mode' => 'write',
                    'params' => [
                        'subscription_id' => ['type' => 'string', 'required' => true, 'max_length' => 64],
                    ],
                ],
                [
                    'name' => 'cancel',
                    'frontend' => true,
                    'auth' => 'customer',
                    'mode' => 'write',
                    'params' => [
                        'subscription_id' => ['type' => 'string', 'required' => true, 'max_length' => 64],
                        'expected_version' => ['type' => 'int', 'required' => true, 'min' => 1],
                    ],
                ],
            ],
        ];
    }
}
