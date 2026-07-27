<?php
declare(strict_types=1);

namespace Weline\Framework\Runtime;

/**
 * Request-scoped configuration scope.
 *
 * Scope identity is shared by SystemConfig and modules that need scope-aware
 * reads. The first segment is the website code, the second is the store code,
 * and the third segment is reserved for later extension.
 */
class ScopeContext
{
    public const KEY = 'system_config.scope';
    public const LEGACY_KEY = 'scope';
    public const DEFAULT_SEGMENT = 'default';
    public const MAX_SEGMENTS = 3;

    public static function getScope(): string
    {
        $identity = RequestContext::scopeIdentity();
        if ($identity instanceof ScopeIdentity) {
            return $identity->toLegacyScopeString();
        }

        $scope = trim((string)RequestContext::get(self::KEY, ''));
        if ($scope === '') {
            $scope = trim((string)RequestContext::get(self::LEGACY_KEY, ''));
        }
        if ($scope === '') {
            $scope = trim(RequestContext::getWelineWebsiteCode());
        }

        return self::normalizeScope($scope);
    }

    /**
     * @deprecated Install a ScopeIdentity through RequestContext. This setter
     *             only supports pre-freeze request assembly.
     */
    public static function setScope(?string $scope): string
    {
        $identity = RequestContext::scopeIdentity();
        if ($identity instanceof ScopeIdentity) {
            $canonical = $identity->toLegacyScopeString();
            $candidate = $identity->isGlobal() && trim((string)$scope) === ''
                ? ''
                : self::normalizeScope($scope);
            if (!hash_equals($canonical, $candidate)) {
                throw new \LogicException(__('当前请求的 ScopeIdentity 已冻结，禁止二次改写'));
            }
            return $canonical;
        }

        $normalized = self::normalizeScope($scope);
        RequestContext::set(self::KEY, $normalized);

        return $normalized;
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    public static function getSegments(): array
    {
        $scope = self::getScope();
        if ($scope === '') {
            return ['', '', ''];
        }

        [$website, $store, $extra] = explode('.', $scope) + [
            self::DEFAULT_SEGMENT,
            self::DEFAULT_SEGMENT,
            self::DEFAULT_SEGMENT,
        ];

        return [$website, $store, $extra];
    }

    /** @deprecated Install a ScopeIdentity through RequestContext. */
    public static function setSegment(int $position, ?string $segment): string
    {
        if ($position < 1 || $position > self::MAX_SEGMENTS) {
            throw new \InvalidArgumentException('Scope segment position must be between 1 and 3.');
        }

        $segments = self::getSegments();
        $segments[$position - 1] = self::normalizeSegment((string)$segment);

        return self::setScope(implode('.', $segments));
    }

    /** @deprecated Install a ScopeIdentity through RequestContext. */
    public static function setWebsiteCode(?string $code): string
    {
        return self::setSegment(1, $code);
    }

    /** @deprecated Install a ScopeIdentity through RequestContext. */
    public static function setStoreCode(?string $code): string
    {
        return self::setSegment(2, $code);
    }

    /** @deprecated Install a ScopeIdentity through RequestContext. */
    public static function setChannelCode(?string $code): string
    {
        return self::setSegment(3, $code);
    }

    public static function getWebsiteCode(): string
    {
        return self::getSegments()[0];
    }

    public static function getStoreCode(): string
    {
        return self::getSegments()[1];
    }

    public static function getChannelCode(): string
    {
        return self::getSegments()[2];
    }

    public static function normalizeScope(?string $scope): string
    {
        $scope = trim((string)$scope);
        $segments = [];
        if ($scope !== '') {
            foreach (explode('.', $scope) as $segment) {
                $normalized = self::normalizeSegment($segment);
                $segments[] = $normalized;
            }
        }

        $segments = array_slice($segments, 0, self::MAX_SEGMENTS);
        while (count($segments) < self::MAX_SEGMENTS) {
            $segments[] = self::DEFAULT_SEGMENT;
        }

        return implode('.', $segments);
    }

    private static function normalizeSegment(string $segment): string
    {
        $segment = strtolower(trim($segment));
        if ($segment === '') {
            return self::DEFAULT_SEGMENT;
        }

        $segment = str_replace('.', '_', $segment);
        $segment = preg_replace('/[^a-z0-9_-]+/', '_', $segment) ?: '';
        $segment = trim($segment, '_-');

        return $segment !== '' ? $segment : self::DEFAULT_SEGMENT;
    }
}
