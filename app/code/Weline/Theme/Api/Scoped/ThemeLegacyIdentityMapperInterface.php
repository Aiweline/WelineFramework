<?php

declare(strict_types=1);

namespace Weline\Theme\Api\Scoped;

/** Optional extension for business modules that own non-canonical legacy identities. */
interface ThemeLegacyIdentityMapperInterface
{
    public function mapLegacyIdentity(
        string $scope,
        string $targetType,
        int $targetId,
    ): ?ThemeLegacyIdentityMapping;
}
