<?php

declare(strict_types=1);

namespace Weline\Order\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Order\Service\BuyerAftersaleConfig;
use Weline\Order\Service\BuyerAftersaleService;

/**
 * 后台买家售后写操作。
 *
 * 现有 OrderAdminQueryProvider 只发布展示号与订单状态，且其授权测试钉死操作名，
 * 因此售后单独使用本 provider。auth 与其它后台 provider 一样标为 backend。
 */
final class OrderBuyerAftersaleAdminQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly ?BuyerAftersaleConfig $config = null,
        private readonly ?BuyerAftersaleService $service = null,
    ) {
    }

    public function getProviderName(): string
    {
        return 'buyer-aftersale-admin';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        if (!$this->config()->isBuyerAftersaleEnabled()) {
            return ['success' => false, 'error_code' => BuyerAftersaleService::ERROR_DISABLED];
        }
        $requestUuid = trim((string)($params['request_uuid'] ?? ''));
        $service = $this->service();

        return match ($operation) {
            'approveBuyerAftersale' => $this->present($service->approve($requestUuid, 'admin')),
            'rejectBuyerAftersale' => $this->present($service->reject($requestUuid, 'admin')),
            'receiveBuyerAftersale' => $this->present($service->markReturnReceived($requestUuid, 'admin')),
            'reshipBuyerAftersale' => $this->present($service->markReshipped(
                $requestUuid,
                trim((string)($params['tracking_no'] ?? '')),
                'admin',
            )),
            default => throw new \InvalidArgumentException('Unsupported buyer aftersale admin operation: ' . $operation),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => $this->getProviderName(),
            'name' => $this->getProviderName(),
            'module' => 'Weline_Order',
            'summary' => 'Backend buyer after-sale state transitions',
            'operations' => [
                $this->operation('approveBuyerAftersale', 'Approve a submitted buyer after-sale request', [
                    ['name' => 'request_uuid', 'type' => 'string', 'required' => true],
                ]),
                $this->operation('rejectBuyerAftersale', 'Reject a submitted buyer after-sale request', [
                    ['name' => 'request_uuid', 'type' => 'string', 'required' => true],
                ]),
                $this->operation('receiveBuyerAftersale', 'Mark return received or exchange awaiting reship', [
                    ['name' => 'request_uuid', 'type' => 'string', 'required' => true],
                ]),
                $this->operation('reshipBuyerAftersale', 'Record exchange reship tracking and finish manually', [
                    ['name' => 'request_uuid', 'type' => 'string', 'required' => true],
                    ['name' => 'tracking_no', 'type' => 'string', 'required' => true],
                ]),
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $params
     * @return array<string, mixed>
     */
    private function operation(string $name, string $description, array $params): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'frontend' => false,
            'auth' => 'backend',
            'backend' => true,
            'backend_acl' => [
                'kind' => 'source',
                'source_id' => 'Weline_Order::order_edit',
            ],
            'mode' => 'write',
            'params' => $params,
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

    private function config(): BuyerAftersaleConfig
    {
        return $this->config ?? new BuyerAftersaleConfig();
    }

    private function service(): BuyerAftersaleService
    {
        return $this->service ?? new BuyerAftersaleService($this->config());
    }
}
