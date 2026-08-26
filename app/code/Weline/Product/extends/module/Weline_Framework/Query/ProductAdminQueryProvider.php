<?php

declare(strict_types=1);

namespace Weline\Product\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Product\Api\Data\ProductAdminCommand;
use Weline\Product\Api\ProductAdminCommandInterface;
use Weline\Product\Api\ProductAdminReadInterface;

/**
 * Backend browser Resource for the universal Product editor.
 *
 * The framework enforces the descriptor's backend session, ACL source and
 * write-mode CSRF contract before execute() reaches Product.
 */
final class ProductAdminQueryProvider implements QueryProviderInterface
{
    public const ACL_SOURCE = 'Weline_Product::commerce:catalog:products';

    public function __construct(
        private readonly ProductAdminReadInterface $reader,
        private readonly ProductAdminCommandInterface $commands,
    ) {
    }

    public function getProviderName(): string
    {
        return 'product_admin';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'search' => $this->search($params),
            'creationContext' => $this->creationContext($params),
            'snapshot' => $this->snapshot($params),
            'command' => $this->command($params),
            default => throw new \InvalidArgumentException(
                (string)__('商品后台 Resource 不支持操作：%{1}', [$operation]),
            ),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => $this->getProviderName(),
            'name' => (string)__('万能产品后台'),
            'description' => (string)__('读取商品聚合并执行受版本保护的商品后台命令。'),
            'module' => 'Weline_Product',
            'operations' => [
                $this->operation('search', (string)__('筛选商品列表'), 'read', [
                    ['name' => 'website_id', 'type' => 'int', 'required' => true, 'min' => 0],
                    ['name' => 'filters', 'type' => 'object', 'required' => false],
                ]),
                $this->operation('creationContext', (string)__('读取新建类型与活动 Store'), 'read', [
                    ['name' => 'website_id', 'type' => 'int', 'required' => true, 'min' => 0],
                ]),
                $this->operation('snapshot', (string)__('读取商品完整编辑快照'), 'read', [
                    ['name' => 'website_id', 'type' => 'int', 'required' => true, 'min' => 0],
                    ['name' => 'global_product_uuid', 'type' => 'string', 'required' => true, 'max_length' => 36],
                    ['name' => 'store_id', 'type' => 'int|null', 'required' => false, 'min' => 1],
                    ['name' => 'locale', 'type' => 'string', 'required' => false, 'max_length' => 32],
                    ['name' => 'currency', 'type' => 'string', 'required' => false, 'max_length' => 8],
                ]),
                $this->operation('command', (string)__('执行商品创建、保存、校验与生命周期命令'), 'write', [
                    ['name' => 'command', 'type' => 'object', 'required' => true],
                ]),
            ],
        ];
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function search(array $params): array
    {
        $filters = $params['filters'] ?? [];
        if (!is_array($filters)) {
            throw new \InvalidArgumentException('product_admin_filters_invalid');
        }
        return [
            'success' => true,
            'items' => $this->reader->search($this->websiteId($params), $filters),
        ];
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function creationContext(array $params): array
    {
        return [
            'success' => true,
            'context' => $this->reader->creationContext($this->websiteId($params)),
        ];
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function snapshot(array $params): array
    {
        $storeId = null;
        if (array_key_exists('store_id', $params) && $params['store_id'] !== null && $params['store_id'] !== '') {
            $storeId = $this->canonicalInt($params['store_id'], 'store_id');
            if ($storeId <= 0) {
                throw new \InvalidArgumentException('product_admin_store_invalid');
            }
        }
        return [
            'success' => true,
            'snapshot' => $this->reader->snapshot(
                $this->websiteId($params),
                $this->requiredString($params, 'global_product_uuid', 36),
                $storeId,
                $this->optionalString($params, 'locale', 32),
                strtoupper($this->optionalString($params, 'currency', 8) ?: 'CNY'),
            )->toArray(),
        ];
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function command(array $params): array
    {
        $raw = $params['command'] ?? null;
        if (!is_array($raw)) {
            throw new \InvalidArgumentException('product_admin_command_invalid');
        }
        // Actor identity is server-owned. ProductAdmin audit integration will
        // replace zero with the authenticated backend actor when that public
        // context contract is available; callers cannot inject an actor ID.
        $raw['actor_id'] = 0;
        return $this->commands->execute(ProductAdminCommand::fromArray($raw))->toArray();
    }

    /** @return array<string,mixed> */
    private function operation(string $name, string $description, string $mode, array $params): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'frontend' => true,
            'backend' => true,
            'external' => false,
            'auth' => 'backend',
            'backend_acl' => [
                'kind' => 'source',
                'source_id' => self::ACL_SOURCE,
            ],
            'mode' => $mode,
            'graph' => false,
            'cost' => $mode === 'write' ? 3 : 1,
            'params' => $params,
            'returns' => ['type' => 'map'],
        ];
    }

    /** @param array<string,mixed> $params */
    private function websiteId(array $params): int
    {
        $websiteId = $this->canonicalInt($params['website_id'] ?? null, 'website_id');
        if ($websiteId < 0) {
            throw new \InvalidArgumentException('product_admin_website_invalid');
        }
        return $websiteId;
    }

    private function canonicalInt(mixed $value, string $field): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            throw new \InvalidArgumentException('product_admin_' . $field . '_invalid');
        }
        $integer = (int)$value;
        if ((string)$integer !== $value) {
            throw new \InvalidArgumentException('product_admin_' . $field . '_invalid');
        }
        return $integer;
    }

    /** @param array<string,mixed> $params */
    private function requiredString(array $params, string $field, int $maxLength): string
    {
        $value = trim((string)($params[$field] ?? ''));
        if ($value === '' || strlen($value) > $maxLength) {
            throw new \InvalidArgumentException('product_admin_' . $field . '_invalid');
        }
        return $value;
    }

    /** @param array<string,mixed> $params */
    private function optionalString(array $params, string $field, int $maxLength): string
    {
        $value = trim((string)($params[$field] ?? ''));
        if (strlen($value) > $maxLength) {
            throw new \InvalidArgumentException('product_admin_' . $field . '_invalid');
        }
        return $value;
    }
}
