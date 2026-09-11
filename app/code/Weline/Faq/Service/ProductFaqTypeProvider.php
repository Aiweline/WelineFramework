<?php

declare(strict_types=1);

namespace Weline\Faq\Service;

use Weline\Faq\Api\FaqTypeProviderInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Product\Api\ProductIdentityResolverInterface;

final class ProductFaqTypeProvider implements FaqTypeProviderInterface
{
    public function typeCode(): string
    {
        return 'product';
    }

    public function resolveEntity(string $uuid): ?array
    {
        $uuid = trim($uuid);
        if ($uuid === '' || !interface_exists(ProductIdentityResolverInterface::class)) {
            return null;
        }
        try {
            $resolver = ObjectManager::getInstance(RuntimeProviderResolver::class)
                ->resolve(ProductIdentityResolverInterface::class);
            if (!$resolver instanceof ProductIdentityResolverInterface) {
                return null;
            }
            $identity = $resolver->resolveByOfferUuid($uuid)
                ?? $resolver->resolveByProductUuid($uuid);

            return $identity === null ? null : [
                'entity_id' => $identity->registryId,
                'entity_uuid' => $identity->globalProductUuid,
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
