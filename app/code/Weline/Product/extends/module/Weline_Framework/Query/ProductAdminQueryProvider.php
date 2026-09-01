<?php

declare(strict_types=1);

namespace Weline\Product\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Product\Api\Data\ProductAdminCommand;
use Weline\Product\Api\ProductAdminCommandInterface;
use Weline\Product\Api\ProductAdminReadInterface;
use Weline\Product\Service\ProductAdminBulkService;
use Weline\Product\Service\ProductCategoryBulkAssignService;

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
        private readonly ProductAdminBulkService $bulk,
        private readonly ProductCategoryBulkAssignService $categoryBulk,
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
            'attributeCatalog' => $this->attributeCatalog($params),
            'checkSlug' => $this->checkSlug($params),
            'bulkCommand' => $this->bulkCommand($params),
            'bulkAssignCategories' => $this->bulkAssignCategories($params),
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
                $this->operation('attributeCatalog', (string)__('读取商品属性目录'), 'read', [
                    ['name' => 'website_id', 'type' => 'int', 'required' => true, 'min' => 0],
                    ['name' => 'global_product_uuid', 'type' => 'string', 'required' => true, 'max_length' => 36],
                ]),
                $this->operation('checkSlug', (string)__('检查前台 URL Handle 是否可用'), 'read', [
                    ['name' => 'website_id', 'type' => 'int', 'required' => true, 'min' => 0],
                    ['name' => 'slug', 'type' => 'string', 'required' => true, 'max_length' => 255],
                    ['name' => 'exclude_product_id', 'type' => 'int', 'required' => false, 'min' => 0],
                ]),
                $this->operation('bulkCommand', (string)__('批量执行商品后台命令'), 'write', [
                    ['name' => 'website_id', 'type' => 'int', 'required' => true, 'min' => 0],
                    ['name' => 'action', 'type' => 'string', 'required' => true, 'max_length' => 64],
                    ['name' => 'base_request_hash', 'type' => 'string', 'required' => true, 'min_length' => 64, 'max_length' => 64],
                    ['name' => 'items', 'type' => 'array', 'required' => true],
                ]),
                $this->operation('bulkAssignCategories', (string)__('批量调整商品 Website 分类'), 'write', [
                    ['name' => 'website_id', 'type' => 'int', 'required' => true, 'min' => 0],
                    ['name' => 'mode', 'type' => 'string', 'required' => true, 'max_length' => 16],
                    ['name' => 'category_ids', 'type' => 'array', 'required' => true],
                    ['name' => 'items', 'type' => 'array', 'required' => true],
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
            if ($storeId < 0) {
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
    private function attributeCatalog(array $params): array
    {
        return [
            'success' => true,
            'catalog' => $this->reader->attributeCatalog(
                $this->websiteId($params),
                $this->requiredString($params, 'global_product_uuid', 36),
            ),
        ];
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function checkSlug(array $params): array
    {
        $excludeProductId = 0;
        if (array_key_exists('exclude_product_id', $params) && $params['exclude_product_id'] !== null && $params['exclude_product_id'] !== '') {
            $excludeProductId = $this->canonicalInt($params['exclude_product_id'], 'exclude_product_id');
            if ($excludeProductId < 0) {
                throw new \InvalidArgumentException('product_admin_exclude_product_invalid');
            }
        }

        $availability = $this->reader->slugAvailability(
            $this->websiteId($params),
            $this->requiredString($params, 'slug', 255),
            $excludeProductId,
        );

        return [
            'success' => true,
            'available' => (bool)($availability['available'] ?? false),
            'slug' => (string)($availability['slug'] ?? ''),
            'reason' => (string)($availability['reason'] ?? ''),
            'conflict_product_id' => (int)($availability['conflict_product_id'] ?? 0),
        ];
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function bulkCommand(array $params): array
    {
        $items = $params['items'] ?? null;
        if (!is_array($items)) {
            throw new \InvalidArgumentException('product_admin_bulk_items_invalid');
        }

        return $this->bulk->execute(
            $this->websiteId($params),
            $this->requiredString($params, 'action', 64),
            $this->requiredString($params, 'base_request_hash', 64),
            $items,
        );
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function bulkAssignCategories(array $params): array
    {
        $items = $params['items'] ?? null;
        $categoryIds = $params['category_ids'] ?? null;
        if (!is_array($items)) {
            throw new \InvalidArgumentException('product_admin_bulk_items_invalid');
        }
        if (!is_array($categoryIds)) {
            throw new \InvalidArgumentException('product_category_bulk_categories_invalid');
        }

        return $this->categoryBulk->execute(
            $this->websiteId($params),
            $this->requiredString($params, 'mode', 16),
            $categoryIds,
            $items,
        );
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
