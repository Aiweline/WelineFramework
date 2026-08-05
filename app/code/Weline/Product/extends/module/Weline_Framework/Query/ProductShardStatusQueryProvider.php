<?php

declare(strict_types=1);

namespace Weline\Product\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Product\Model\ProductShardRegistry;

/**
 * Read-only storefront health boundary for the current Product website shard.
 *
 * It never provisions, repairs or mutates schema. The trusted request Scope
 * selects the Website; callers cannot inspect another Website by parameter.
 */
final class ProductShardStatusQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly ProductShardRegistry $registry,
    ) {
    }

    public function getProviderName(): string
    {
        return 'product_shard_status';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'current' => $this->current(),
            default => throw new \InvalidArgumentException((string)__(
                'Product shard 状态接口不支持操作：%{1}',
                [$operation],
            )),
        };
    }

    /** @return array<string, mixed> */
    private function current(): array
    {
        $scope = RequestContext::scopeIdentity();
        if ($scope === null || $scope->isGlobal() || $scope->websiteId === null) {
            return [
                'success' => false,
                'error_code' => 'product_shard_request_scope_unavailable',
                'message' => (string)__('当前请求没有可用的商城 Scope'),
            ];
        }

        $websiteId = $scope->websiteId;
        $model = clone $this->registry;
        $rows = $model->clearData()->clearQuery()
            ->where(ProductShardRegistry::schema_fields_WEBSITE_ID, $websiteId)
            ->select()
            ->fetchArray();
        $row = is_array($rows[0] ?? null) ? $rows[0] : [];
        $status = (string)($row[ProductShardRegistry::schema_fields_STATUS]
            ?? ProductShardRegistry::STATUS_UNPROVISIONED);
        $payload = [
            'website_id' => $websiteId,
            'scope' => $scope->toArray(),
            'status' => $status,
            'writable' => $status === ProductShardRegistry::STATUS_READY,
            'fingerprint' => (string)($row[ProductShardRegistry::schema_fields_FINGERPRINT] ?? ''),
            'schema_version' => (string)($row[ProductShardRegistry::schema_fields_SCHEMA_VERSION] ?? '1'),
            'database_driver' => strtolower((string)$model->getConnection()
                ->getConnector()
                ->getConfigProvider()
                ->getDbType()),
        ];

        return [
            'success' => true,
            'data' => $payload,
        ] + $payload;
    }

    /** @return array<string, mixed> */
    public function getDescriptor(): array
    {
        return [
            'provider' => $this->getProviderName(),
            'name' => __('Product shard 当前状态'),
            'description' => __('只读返回可信请求 Website 的 Product shard 状态，不执行 DDL。'),
            'module' => 'Weline_Product',
            'operations' => [
                [
                    'name' => 'current',
                    'frontend' => true,
                    'auth' => 'any',
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 1,
                    'params' => [],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Read current trusted Website Product shard status without DDL',
                ],
            ],
        ];
    }
}
