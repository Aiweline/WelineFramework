<?php

declare(strict_types=1);

namespace Weline\Cdn\Service;

use Weline\Framework\Runtime\ScopeIdentity;

/**
 * 媒体 URL COW：共享基址 + Scope 覆盖基址；编辑后使用覆盖 URL 空间（TASK-P1D-002）。
 */
final class MediaUrlCowResolver
{
    public function __construct(
        private readonly ScopedAccountBindingService $bindings,
    ) {
    }

    public function resolveCowMediaUrl(
        string $path,
        ScopeIdentity $scope,
        string $sharedBaseUrl = '/pub/media',
    ): string {
        $path = \ltrim(\str_replace('\\', '/', $path), '/');
        $shared = \rtrim($sharedBaseUrl, '/');
        $binding = $this->bindings->resolve($scope, ScopedAccountBindingService::ADAPTER_MEDIA);
        $base = $shared;
        if ($binding !== null && ($binding['media_base_url'] ?? '') !== '') {
            $base = (string)$binding['media_base_url'];
        }

        return $base . '/' . $path;
    }

    /**
     * 是否相对共享基址发生了 COW 覆盖。
     */
    public function isCowOverride(ScopeIdentity $scope, string $sharedBaseUrl = '/pub/media'): bool
    {
        $binding = $this->bindings->resolve($scope, ScopedAccountBindingService::ADAPTER_MEDIA);
        if ($binding === null || ($binding['media_base_url'] ?? '') === '') {
            return false;
        }

        return \rtrim((string)$binding['media_base_url'], '/') !== \rtrim($sharedBaseUrl, '/');
    }
}
