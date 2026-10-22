<?php

declare(strict_types=1);

namespace Weline\Order\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Order\Service\BuyerAftersaleConfig;
use Weline\Order\Service\BuyerAftersaleService;

/**
 * 顾客买家售后写操作。总闸关闭时一律拒绝。
 */
final class BuyerAftersaleQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly ?BuyerAftersaleConfig $config = null,
        private readonly ?BuyerAftersaleService $service = null,
        private readonly ?SessionFactory $sessionFactory = null,
    ) {
    }

    public function getProviderName(): string
    {
        return 'buyer-aftersale';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        if (!$this->config()->isBuyerAftersaleEnabled()) {
            return ['success' => false, 'error_code' => BuyerAftersaleService::ERROR_DISABLED];
        }

        return match ($operation) {
            'submit' => $this->present($this->service()->submit(
                $this->authenticatedCustomerId(),
                $params,
            )),
            'cancel' => $this->present($this->service()->cancel(
                $this->authenticatedCustomerId(),
                trim((string)($params['request_uuid'] ?? '')),
            )),
            'setTracking' => $this->present($this->service()->setTracking(
                $this->authenticatedCustomerId(),
                trim((string)($params['request_uuid'] ?? '')),
                trim((string)($params['tracking_no'] ?? '')),
            )),
            default => throw new \InvalidArgumentException(
                (string)__('售后接口不支持该操作：%{1}', [$operation]),
            ),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'name' => $this->getProviderName(),
            'module' => 'Weline_Order',
            'summary' => 'Authenticated customer buyer after-sale writes',
            'operations' => [
                [
                    'name' => 'submit',
                    'frontend' => true,
                    'auth' => 'customer',
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 1,
                    'params' => [
                        'order_uuid' => ['type' => 'string', 'required' => true, 'max_length' => 36],
                        'resolution' => ['type' => 'string', 'required' => true, 'max_length' => 32],
                        'reason_code' => ['type' => 'string', 'required' => true, 'max_length' => 32],
                        'request_uuid' => ['type' => 'string', 'required' => false, 'max_length' => 36],
                        'reason_text' => ['type' => 'string', 'required' => false],
                        'amount_minor' => ['type' => 'int', 'required' => false],
                        'item_count' => ['type' => 'int', 'required' => false],
                        'return_address_snapshot' => ['type' => 'string', 'required' => false],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Submit a buyer after-sale request for an owned order',
                ],
                [
                    'name' => 'cancel',
                    'frontend' => true,
                    'auth' => 'customer',
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 1,
                    'params' => [
                        'request_uuid' => ['type' => 'string', 'required' => true, 'max_length' => 36],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Cancel an owned submitted after-sale request',
                ],
                [
                    'name' => 'setTracking',
                    'frontend' => true,
                    'auth' => 'customer',
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 1,
                    'params' => [
                        'request_uuid' => ['type' => 'string', 'required' => true, 'max_length' => 36],
                        'tracking_no' => ['type' => 'string', 'required' => true, 'max_length' => 128],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Record the customer return tracking number',
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function present(array $result): array
    {
        return [
            'success' => (bool)($result['ok'] ?? false),
            'error_code' => $result['error_code'] ?? null,
            'request_uuid' => $result['request_uuid'] ?? null,
            'status' => $result['status'] ?? null,
        ];
    }

    private function authenticatedCustomerId(): int
    {
        $session = $this->sessionFactory()->createFrontendSession();
        if (!$session->isLoggedIn()) {
            return 0;
        }

        return (int)($session->getUserId() ?? $session->getUser()?->getId() ?? 0);
    }

    private function config(): BuyerAftersaleConfig
    {
        return $this->config ?? new BuyerAftersaleConfig();
    }

    private function service(): BuyerAftersaleService
    {
        return $this->service ?? new BuyerAftersaleService($this->config());
    }

    private function sessionFactory(): SessionFactory
    {
        if ($this->sessionFactory instanceof SessionFactory) {
            return $this->sessionFactory;
        }

        /** @var SessionFactory $factory */
        $factory = ObjectManager::getInstance(SessionFactory::class);

        return $factory;
    }
}
