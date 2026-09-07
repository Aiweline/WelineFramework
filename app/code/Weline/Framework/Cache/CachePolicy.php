<?php

declare(strict_types=1);

namespace Weline\Framework\Cache;

use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Runtime\ScopeIdentity;

/** Declarative reuse boundary, variants and invalidation dependencies for one resource. */
final readonly class CachePolicy
{
    /** @var list<string> */
    public array $vary;
    /** @var list<string> */
    public array $dependencies;

    /** @param list<string> $vary @param list<string> $dependencies Domain paths relative to the selected scope. */
    public function __construct(
        public string $resource,
        public string $pool,
        public string $scope,
        array $vary = [],
        array $dependencies = [],
        public int $freshTtlSeconds = 300,
        public int $staleTtlSeconds = 1800,
    ) {
        if (trim($resource) === '' || trim($pool) === '') {
            throw new \InvalidArgumentException('Cache policy resource and pool must not be empty.');
        }
        if (!in_array($scope, ScopeIdentity::KINDS, true)) {
            throw new \InvalidArgumentException('Unknown cache policy scope: ' . $scope);
        }
        $vary = array_map(static fn(string $dimension): string => $dimension === 'locale' ? 'lang' : $dimension, $vary);
        if (array_diff($vary, ['lang', 'currency', 'area']) !== []) {
            throw new \InvalidArgumentException('Cache policy varies only by lang, currency or area.');
        }
        if ($freshTtlSeconds < 1 || $staleTtlSeconds < 0) {
            throw new \InvalidArgumentException('Cache policy requires a positive fresh TTL and a non-negative stale TTL.');
        }
        $vary = array_values(array_unique($vary));
        $dependencies = array_values(array_unique($dependencies));
        sort($vary, SORT_STRING);
        sort($dependencies, SORT_STRING);
        $this->vary = $vary;
        $this->dependencies = $dependencies;
    }

    /** @return list<string> */
    public function namespacePaths(?ScopeIdentity $identity): array
    {
        $path = new NamespacePath();
        $paths = [];
        foreach ($this->dependencies as $domain) {
            $segments = explode('/', $domain);
            // Scoped resources may inherit the same domain from global configuration.
            $paths[] = $path->global('storefront', $segments);
            if ($this->scope === ScopeIdentity::KIND_GLOBAL) {
                continue;
            }
            if ($identity?->websiteCode === null) {
                throw new \InvalidArgumentException('A scoped cache dependency requires an established website identity.');
            }
            if ($this->scope === ScopeIdentity::KIND_STORE || $this->scope === ScopeIdentity::KIND_CHANNEL) {
                if ($identity->storeCode === null || $identity->storeMode === null) {
                    throw new \InvalidArgumentException('A store cache dependency requires an established store identity.');
                }
                $segments = [...$segments, 'store', $identity->storeCode, $identity->storeMode];
            }
            if ($this->scope === ScopeIdentity::KIND_CHANNEL) {
                if ($identity->channelCode === null) {
                    throw new \InvalidArgumentException('A channel cache dependency requires an established channel identity.');
                }
                $segments = [...$segments, 'channel', $identity->channelCode];
            }
            $paths[] = $path->website($identity->websiteCode, $segments);
        }
        return $paths;
    }

    /** @return array{resource:string,pool:string,scope:string,vary:list<string>,dependencies:list<string>,fresh_ttl:int,stale_ttl:int} */
    public function toArray(): array
    {
        return [
            'resource' => $this->resource,
            'pool' => $this->pool,
            'scope' => $this->scope,
            'vary' => $this->vary,
            'dependencies' => $this->dependencies,
            'fresh_ttl' => $this->freshTtlSeconds,
            'stale_ttl' => $this->staleTtlSeconds,
        ];
    }
}
