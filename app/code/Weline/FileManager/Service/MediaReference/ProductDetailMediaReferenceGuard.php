<?php

declare(strict_types=1);

namespace Weline\FileManager\Service\MediaReference;

/**
 * Product detail / AI illustration must use kind:detail under product+sku+scope.
 */
final class ProductDetailMediaReferenceGuard
{
    public function __construct(
        private readonly MediaReferenceIdentityBuilder $builder,
        private readonly MediaReferenceService $service,
    ) {
    }

    /**
     * @param array<string, mixed> $extraSlot
     */
    public function bindDetailAsset(
        ?string $scope,
        string $sku,
        string $assetId,
        string $ownerId,
        int $ownerVersion = 1,
        string $localeCode = '',
        array $extraSlot = [],
    ): MediaReferenceIdentity {
        $sku = trim($sku);
        $assetId = trim($assetId);
        if ($sku === '' || $assetId === '') {
            throw new \InvalidArgumentException('详情插图必须提供 sku 与 asset_id。');
        }
        $slot = array_merge(['kind' => 'detail'], $extraSlot);
        $slot['kind'] = 'detail';
        $identity = $this->builder->build($scope, 'product', $sku, $slot);
        $this->service->bindSingle(
            $identity,
            $assetId,
            'product',
            $ownerId !== '' ? $ownerId : $sku,
            max(1, $ownerVersion),
            $identity->path,
            $localeCode,
        );

        return $identity;
    }

    public function assertDetailIdentity(MediaReferenceIdentity $identity): void
    {
        if ($identity->root !== 'product' || ($identity->tags['kind'] ?? '') !== 'detail') {
            throw new \InvalidArgumentException('详情插图身份必须为 product…kind:detail。');
        }
        if ($identity->code === '' || $identity->scope === '') {
            throw new \InvalidArgumentException('详情插图缺少 sku 或 scope。');
        }
    }
}
