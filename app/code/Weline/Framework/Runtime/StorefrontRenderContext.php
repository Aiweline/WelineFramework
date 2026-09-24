<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\Context;

/**
 * Immutable storefront render facts installed in RequestContext.
 *
 * Parallel to {@see \Weline\Framework\Cache\StorefrontCacheKeyContext}:
 * cache fingerprint ≠ render facts. Bag key is the sole authority.
 *
 * Underlying website_local rows remain in {@see self::WEBSITE_LOCAL_ROWS_BAG_KEY};
 * this VO holds a read-only projection / reference view, never a second row store.
 */
final readonly class StorefrontRenderContext
{
    public const BAG_KEY = 'storefront.render_context.v1';
    public const SCHEMA_VERSION = 'storefront-render-v1';

    /** Underlying WebsiteData request bag (not a parallel authority). */
    public const WEBSITE_LOCAL_ROWS_BAG_KEY = 'websites.website_local_rows.v1';

    /**
     * @param list<array{local_code?:string,name?:string,description?:string}>|null $websiteLocal
     * @param array{active:list<string>,installed:list<string>} $localeCatalog
     * @param array{scope_key?:string,enabled?:bool,reason?:string,generation?:int,since?:int}|null $maintenance
     * @param array<string,mixed>|null $themeMeta
     * @param array<string,mixed>|null $websiteTableSnapshot
     */
    public function __construct(
        public int $websiteId,
        public string $websiteCode,
        public string $websiteUrl,
        public ?array $websiteLocal,
        public string $locale,
        public string $currency,
        public string $timezone,
        public array $localeCatalog,
        public ?array $maintenance,
        public ?array $themeMeta,
        public ?array $websiteTableSnapshot,
        public bool $complete,
        public string $failureCode = '',
    ) {
        if (!isset($this->localeCatalog['active'], $this->localeCatalog['installed'])
            || !\is_array($this->localeCatalog['active'])
            || !\is_array($this->localeCatalog['installed'])
        ) {
            throw new \InvalidArgumentException(__('StorefrontRenderContext.locale_catalog 必须含 active/installed 列表'));
        }
    }

    public static function current(): ?self
    {
        if (!Context::hasCurrent()) {
            return null;
        }
        $context = RequestContext::get(self::BAG_KEY);
        return $context instanceof self ? $context : null;
    }

    public static function install(self $context): void
    {
        if (!Context::hasCurrent()) {
            return;
        }
        RequestContext::set(self::BAG_KEY, $context);
    }

    /**
     * @return array{
     *   schema:string,
     *   bag_key:string,
     *   website_id:int,
     *   website_code:string,
     *   website_url:string,
     *   website_local:list<array<string,mixed>>|null,
     *   locale:string,
     *   currency:string,
     *   timezone:string,
     *   locale_catalog:array{active:list<string>,installed:list<string>},
     *   maintenance:array<string,mixed>|null,
     *   theme_meta:array<string,mixed>|null,
     *   website_table_snapshot:array<string,mixed>|null,
     *   complete:bool,
     *   failure_code:string
     * }
     */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA_VERSION,
            'bag_key' => self::BAG_KEY,
            'website_id' => $this->websiteId,
            'website_code' => $this->websiteCode,
            'website_url' => $this->websiteUrl,
            'website_local' => $this->websiteLocal,
            'locale' => $this->locale,
            'currency' => $this->currency,
            'timezone' => $this->timezone,
            'locale_catalog' => [
                'active' => \array_values($this->localeCatalog['active']),
                'installed' => \array_values($this->localeCatalog['installed']),
            ],
            'maintenance' => $this->maintenance,
            'theme_meta' => $this->themeMeta,
            'website_table_snapshot' => $this->websiteTableSnapshot,
            'complete' => $this->complete,
            'failure_code' => $this->failureCode,
        ];
    }

    /**
     * Atomic field merge — keeps a single bag authority (no parallel keys).
     *
     * @param array{
     *   website_local?:list<array<string,mixed>>|null,
     *   locale_catalog?:array{active?:list<string>,installed?:list<string>},
     *   maintenance?:array<string,mixed>|null,
     *   theme_meta?:array<string,mixed>|null,
     *   website_table_snapshot?:array<string,mixed>|null
     * } $fields
     */
    public function withMergedFields(array $fields): self
    {
        $catalog = $this->localeCatalog;
        if (isset($fields['locale_catalog']) && \is_array($fields['locale_catalog'])) {
            $incoming = $fields['locale_catalog'];
            $catalog = [
                'active' => isset($incoming['active']) && \is_array($incoming['active'])
                    ? \array_values(\array_map('strval', $incoming['active']))
                    : $catalog['active'],
                'installed' => isset($incoming['installed']) && \is_array($incoming['installed'])
                    ? \array_values(\array_map('strval', $incoming['installed']))
                    : $catalog['installed'],
            ];
        }

        return new self(
            $this->websiteId,
            $this->websiteCode,
            $this->websiteUrl,
            \array_key_exists('website_local', $fields)
                ? (isset($fields['website_local']) && \is_array($fields['website_local'])
                    ? \array_values($fields['website_local'])
                    : null)
                : $this->websiteLocal,
            $this->locale,
            $this->currency,
            $this->timezone,
            $catalog,
            \array_key_exists('maintenance', $fields)
                ? (isset($fields['maintenance']) && \is_array($fields['maintenance'])
                    ? $fields['maintenance']
                    : null)
                : $this->maintenance,
            \array_key_exists('theme_meta', $fields)
                ? (isset($fields['theme_meta']) && \is_array($fields['theme_meta'])
                    ? $fields['theme_meta']
                    : null)
                : $this->themeMeta,
            \array_key_exists('website_table_snapshot', $fields)
                ? (isset($fields['website_table_snapshot']) && \is_array($fields['website_table_snapshot'])
                    ? $fields['website_table_snapshot']
                    : null)
                : $this->websiteTableSnapshot,
            $this->complete,
            $this->failureCode,
        );
    }
}
