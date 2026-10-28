<?php

declare(strict_types=1);

namespace Weline\UrlManager\Service;

use Weline\Framework\Router\Contract\StorefrontRewriteLookupInterface;
use Weline\UrlManager\Model\UrlRewrite;

/**
 * M4 自检的 rewrite 反查提供方：Framework 经契约接口访问，不直连 w_url_rewrite。
 * 进程内 memo 避免同一收集轮对同一路径重复查询。
 */
final class StorefrontRewriteLookup implements StorefrontRewriteLookupInterface
{
    /** @var array<string, array<string, mixed>|null> */
    private array $memo = [];

    public function __construct(private readonly UrlRewrite $urlRewrite)
    {
    }

    public function hasRewrite(string $prettyPath): bool
    {
        return $this->row($prettyPath) !== null;
    }

    public function resolveTargetPath(string $prettyPath): ?string
    {
        $row = $this->row($prettyPath);
        if ($row === null) {
            return null;
        }
        $path = $row[UrlRewrite::schema_fields_PATH] ?? null;
        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row(string $prettyPath): ?array
    {
        $normalized = trim($prettyPath, '/');
        if ($normalized === '') {
            return null;
        }
        if (array_key_exists($normalized, $this->memo)) {
            return $this->memo[$normalized];
        }
        $this->memo[$normalized] = $this->urlRewrite->findLatestByWebsiteAndRewrites(
            UrlRewrite::getCurrentWebsiteId(),
            [$normalized, '/' . $normalized]
        );

        return $this->memo[$normalized];
    }
}
