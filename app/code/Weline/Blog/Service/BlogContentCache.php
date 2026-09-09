<?php

declare(strict_types=1);

namespace Weline\Blog\Service;

use Weline\Framework\Cache\CachePolicy;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Runtime\RequestContext;

/** Raw public read snapshots. Explicit query scope also supports sitemap/CLI callers. */
final class BlogContentCache
{
    public function __construct(private readonly StorefrontScopeHotCache $hotCache)
    {
    }

    public static function policy(int $websiteId): CachePolicy
    {
        $dependencies = array_map(
            static fn(int $id): string => 'blog/content/website/' . $id,
            BlogWebsiteScope::websiteIdsForQuery($websiteId),
        );
        return new CachePolicy(
            resource: 'blog.public.website.' . max(0, $websiteId),
            pool: 'blog',
            scope: 'global',
            dependencies: $dependencies,
            freshTtlSeconds: 300,
            staleTtlSeconds: 0,
        );
    }

    public function remember(int $websiteId, string $locale, string $resource, array $parameters, callable $builder): mixed
    {
        if (\Weline\Framework\Database\TransactionContext::activeTransactionConnectionCount() > 0) {
            return $builder();
        }
        $key = 'blog.public.v1:' . hash('sha256', serialize([$websiteId, $locale, $resource, $parameters]));
        return $this->hotCache->rememberPolicy(self::policy($websiteId), $key, $builder);
    }

    /** Request snapshots may include EAV values whose shared invalidation is separate. */
    public function rememberForRequest(string $resource, array $parameters, callable $builder): mixed
    {
        if (\Weline\Framework\Database\TransactionContext::activeTransactionConnectionCount() > 0) {
            return $builder();
        }
        $key = serialize([$parameters, (int)RequestContext::get('blog.request_snapshot_revision', 0)]);
        return $this->hotCache->rememberForRequest('blog.' . $resource, $key, $builder);
    }

    /** Namespaces for the facts that changed, not all consumers of the global fallback. */
    public static function changedPaths(int $websiteId, int $previousWebsiteId): array
    {
        $path = new \Weline\Framework\Cache\Namespace\NamespacePath();
        $paths = [];
        foreach (array_unique([max(0, $websiteId), max(0, $previousWebsiteId)]) as $id) {
            $paths[] = $path->global('storefront', ['blog', 'content', 'website', (string)$id]);
        }
        sort($paths, SORT_STRING);
        return $paths;
    }

    /** Called after a committed write; immutable request copies must not hide the new revision. */
    public static function clearRequestSnapshots(): void
    {
        if (\Weline\Framework\Context::hasCurrent()) {
            RequestContext::set('blog.request_snapshot_revision', (int)RequestContext::get('blog.request_snapshot_revision', 0) + 1);
        }
        foreach (array_keys(RequestContext::all()) as $key) {
            if (str_starts_with($key, 'blog.published_post.') || str_starts_with($key, 'blog.category_meta.') || str_starts_with($key, 'blog.post_keywords.')) {
                RequestContext::remove($key);
            }
        }
    }
}
