<?php

declare(strict_types=1);

namespace Weline\Catalog\Service;

use Weline\Catalog\Api\Data\CatalogScopeContext;
use Weline\Catalog\Exception\CatalogScopeForbiddenException;

final class CatalogScopeGuard
{
    /** @var list<string> */
    public const STRUCTURE_OPERATIONS = [
        'save',
        'delete',
        'reorder',
        'writeAttributes',
    ];

    /** @var list<string> */
    public const DISPLAY_OPERATIONS = [
        'readDisplaySelection',
        'saveDisplaySelection',
    ];

    /**
     * @param array<string, mixed> $params
     */
    public function resolve(array $params): CatalogScopeContext
    {
        $space = trim((string)($params['space'] ?? $params['domain'] ?? ''));
        $scopeLevel = strtolower(trim((string)($params['scope_level'] ?? 'website')));
        if (!in_array($scopeLevel, ['website', 'store', 'channel'], true)) {
            $scopeLevel = 'website';
        }

        return new CatalogScopeContext(
            space: $space,
            scopeLevel: $scopeLevel,
            websiteId: max(0, (int)($params['website_id'] ?? 0)),
            storeId: max(0, (int)($params['store_id'] ?? 0)),
            channelId: max(0, (int)($params['channel_id'] ?? 0)),
        );
    }

    public function assertOperationAllowed(string $operation, CatalogScopeContext $scope): void
    {
        $operation = trim($operation);
        if (in_array($operation, self::STRUCTURE_OPERATIONS, true) && !$scope->isWebsiteStructureScope()) {
            throw new CatalogScopeForbiddenException(
                (string)__('分类结构只能在 Website 范围维护'),
                context: [
                    'operation' => $operation,
                    'scope_level' => $scope->scopeLevel,
                    'website_id' => $scope->websiteId,
                ],
            );
        }

        if (in_array($operation, self::DISPLAY_OPERATIONS, true) && $scope->isWebsiteStructureScope()) {
            throw new CatalogScopeForbiddenException(
                (string)__('展示选择只能在 Store/Channel 范围配置'),
                context: [
                    'operation' => $operation,
                    'scope_level' => $scope->scopeLevel,
                ],
            );
        }
    }
}
