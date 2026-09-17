<?php

declare(strict_types=1);

namespace Weline\FileManager\Service\MediaReference;

use Weline\Framework\Runtime\RequestContext;

/**
 * Resolve storage_scope from ambient/request context when callers omit it.
 */
final class MediaReferenceScopeResolver
{
    public const REQUEST_KEY = 'media_ref.storage_scope';
    public const AMBIENT_KEY = 'media_ref.ambient';

    public function fromContext(): ?string
    {
        if (!RequestContext::isInitialized()) {
            return null;
        }
        $direct = trim((string)RequestContext::get(self::REQUEST_KEY, ''));
        if ($this->isValidStorageScope($direct)) {
            return $direct;
        }
        $ambient = RequestContext::get(self::AMBIENT_KEY, null);
        if (is_array($ambient)) {
            $fromAmbient = trim((string)($ambient['scope'] ?? $ambient['storage_scope'] ?? ''));
            if ($this->isValidStorageScope($fromAmbient)) {
                return $fromAmbient;
            }
        }
        $legacy = trim((string)RequestContext::get('storage_scope', ''));
        if ($this->isValidStorageScope($legacy)) {
            return $legacy;
        }

        return null;
    }

    public function isValidStorageScope(string $scope): bool
    {
        $scope = strtolower(trim($scope));
        if ($scope === '') {
            return false;
        }
        $parts = explode('.', $scope);

        return count($parts) === 3
            && $parts[0] !== ''
            && $parts[1] !== ''
            && $parts[2] !== ''
            && !str_contains($scope, ':');
    }

    public function assertStorageScope(string $scope): string
    {
        $scope = strtolower(trim($scope));
        if (!$this->isValidStorageScope($scope)) {
            throw new \InvalidArgumentException('媒体引用 scope 必须是三点分 storage_scope。');
        }

        return $scope;
    }
}
