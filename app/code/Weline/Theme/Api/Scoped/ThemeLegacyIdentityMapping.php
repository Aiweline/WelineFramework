<?php

declare(strict_types=1);

namespace Weline\Theme\Api\Scoped;

/** Canonical destination for a legacy Scope/business-target identity pair. */
final readonly class ThemeLegacyIdentityMapping
{
    public function __construct(
        public string $scope,
        public string $targetType,
        public int $targetId,
    ) {
        $segments = explode('.', $scope);
        if (
            $scope !== strtolower(trim($scope))
            || count($segments) !== 3
            || array_filter(
                $segments,
                static fn(string $segment): bool => preg_match('/^[a-z0-9_][a-z0-9_-]{0,254}$/D', $segment) !== 1,
            ) !== []
            || $targetType !== strtolower(trim($targetType))
            || preg_match('/^[a-z][a-z0-9_.-]*$/D', $targetType) !== 1
            || $targetId < 0
        ) {
            throw new \InvalidArgumentException('theme_legacy_identity_mapping_invalid');
        }
    }
}
