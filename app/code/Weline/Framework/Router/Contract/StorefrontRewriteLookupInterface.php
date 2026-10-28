<?php

declare(strict_types=1);

namespace Weline\Framework\Router\Contract;

/**
 * 店面 slug（SEO rewrite）可解析性契约：由拥有 rewrite 数据的模块（Weline_UrlManager）
 * 实现，Framework 路由自检只通过本接口反查，禁止直连 Seo/UrlManager 表。
 *
 * owning_module：数据在 Weline_UrlManager；契约与消费方在 Weline_Framework。
 */
interface StorefrontRewriteLookupInterface
{
    /**
     * 该 pretty path（不含 locale/currency 前缀、不含前后斜杠）是否存在 rewrite 行。
     */
    public function hasRewrite(string $prettyPath): bool;

    /**
     * rewrite 命中的目标控制器路径（如 "catalog/product/view/id/123"），用于再查静态路由表。
     */
    public function resolveTargetPath(string $prettyPath): ?string;
}
