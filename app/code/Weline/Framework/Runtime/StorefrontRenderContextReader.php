<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\Context;

/**
 * Read-only API for Theme / Seo / I18n / Websites consumers.
 *
 * Coordinates with Theme seat: prefer these accessors over parallel RequestContext keys.
 */
final class StorefrontRenderContextReader
{
    public static function get(): ?StorefrontRenderContext
    {
        return StorefrontRenderContext::current();
    }

    public static function require(): StorefrontRenderContext
    {
        $context = StorefrontRenderContext::current();
        if ($context instanceof StorefrontRenderContext) {
            return $context;
        }
        throw new \LogicException(__('StorefrontRenderContext 尚未安装（袋键 storefront.render_context.v1）'));
    }

    public static function bagKey(): string
    {
        return StorefrontRenderContext::BAG_KEY;
    }

    /**
     * @return list<array{local_code?:string,name?:string,description?:string}>|null
     */
    public static function websiteLocal(): ?array
    {
        $context = StorefrontRenderContext::current();
        if ($context instanceof StorefrontRenderContext && $context->websiteLocal !== null) {
            return $context->websiteLocal;
        }
        try {
            if (!\class_exists(\Weline\Websites\Data\WebsiteData::class) || !Context::hasCurrent()) {
                return null;
            }
            $rows = \Weline\Websites\Data\WebsiteData::localRowsForWebsite(RequestContext::getWelineWebsiteId());

            return $rows === [] ? null : $rows;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{active:list<string>,installed:list<string>} */
    public static function localeCatalog(): array
    {
        $context = StorefrontRenderContext::current();
        if ($context instanceof StorefrontRenderContext) {
            return $context->localeCatalog;
        }
        return ['active' => [], 'installed' => []];
    }

    /**
     * @return array{scope_key?:string,enabled?:bool,reason?:string,generation?:int,since?:int}|null
     */
    public static function maintenance(): ?array
    {
        return StorefrontRenderContext::current()?->maintenance;
    }

    /** @return array<string,mixed>|null */
    public static function themeMeta(): ?array
    {
        return StorefrontRenderContext::current()?->themeMeta;
    }

    /**
     * Lazy field merge into the single authority bag (no parallel keys).
     *
     * @param array{
     *   website_local?:list<array<string,mixed>>|null,
     *   locale_catalog?:array{active?:list<string>,installed?:list<string>},
     *   maintenance?:array<string,mixed>|null,
     *   theme_meta?:array<string,mixed>|null,
     *   website_table_snapshot?:array<string,mixed>|null
     * } $fields
     */
    public static function mergeFields(array $fields): ?StorefrontRenderContext
    {
        if (!Context::hasCurrent()) {
            return null;
        }
        $current = StorefrontRenderContext::current();
        if (!$current instanceof StorefrontRenderContext) {
            return null;
        }
        $merged = $current->withMergedFields($fields);
        StorefrontRenderContext::install($merged);
        return $merged;
    }

    /**
     * Publish website_local projection after WebsiteData warms the process/shared bag.
     *
     * @param list<array{local_code?:string,name?:string,description?:string}> $rows
     */
    public static function mergeWebsiteLocal(array $rows): ?StorefrontRenderContext
    {
        return self::mergeFields(['website_local' => \array_values($rows)]);
    }
}
