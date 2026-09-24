<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Storefront;

use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\StorefrontRenderContext;
use Weline\Framework\Runtime\StorefrontRenderContextReader;

/**
 * Theme-side read adapter for WS1 bag {@see StorefrontRenderContext::BAG_KEY}.
 *
 * Prefer {@see StorefrontRenderContextReader}; miss → null so callers keep legacy
 * fallbacks. Never installs a parallel bag or process-static Fiber state.
 * theme_meta is null at Installer time — Theme may {@see mergeThemeMeta()} lazily.
 */
final class StorefrontRenderContextBag
{
    public const BAG_KEY = StorefrontRenderContext::BAG_KEY;

    public static function current(): ?StorefrontRenderContext
    {
        if (\class_exists(StorefrontRenderContextReader::class)) {
            try {
                $viaReader = StorefrontRenderContextReader::get();
                if ($viaReader instanceof StorefrontRenderContext) {
                    return $viaReader;
                }
            } catch (\Throwable) {
            }
        }

        if (\class_exists(StorefrontRenderContext::class)) {
            try {
                $direct = StorefrontRenderContext::current();
                if ($direct instanceof StorefrontRenderContext) {
                    return $direct;
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    public static function websiteId(): ?int
    {
        $ctx = self::current();
        if ($ctx instanceof StorefrontRenderContext) {
            return $ctx->websiteId >= 0 ? $ctx->websiteId : null;
        }

        return null;
    }

    public static function websiteCode(): ?string
    {
        $ctx = self::current();
        if (!$ctx instanceof StorefrontRenderContext) {
            return null;
        }
        $code = \strtolower(\trim($ctx->websiteCode));

        return $code !== '' ? $code : null;
    }

    public static function websiteLocalName(): ?string
    {
        $row = self::resolveWebsiteLocalRow();
        if ($row === null) {
            return null;
        }
        $name = \trim((string)($row['name'] ?? ''));

        return $name !== '' ? $name : null;
    }

    public static function websiteLocalDescription(): ?string
    {
        $row = self::resolveWebsiteLocalRow();
        if ($row === null) {
            return null;
        }
        $description = \trim((string)($row['description'] ?? ''));

        return $description !== '' ? $description : null;
    }

    /**
     * @return list<array{local_code?:string,name?:string,description?:string}>|null
     */
    public static function websiteLocal(): ?array
    {
        if (\class_exists(StorefrontRenderContextReader::class)) {
            try {
                $rows = StorefrontRenderContextReader::websiteLocal();
                if (\is_array($rows)) {
                    return $rows;
                }
            } catch (\Throwable) {
            }
        }
        $ctx = self::current();

        return $ctx instanceof StorefrontRenderContext ? $ctx->websiteLocal : null;
    }

    public static function locale(): ?string
    {
        $ctx = self::current();
        if (!$ctx instanceof StorefrontRenderContext) {
            return null;
        }
        $locale = \trim($ctx->locale);
        if ($locale === ''
            || \preg_match('/^[a-z]{2,3}_[A-Za-z0-9]+(?:_[A-Za-z0-9]+)?$/', $locale) !== 1
        ) {
            return null;
        }

        return $locale;
    }

    public static function currency(): ?string
    {
        $ctx = self::current();
        if (!$ctx instanceof StorefrontRenderContext) {
            return null;
        }
        $currency = \strtoupper(\trim($ctx->currency));

        return $currency !== '' ? $currency : null;
    }

    public static function maintenance(): mixed
    {
        if (\class_exists(StorefrontRenderContextReader::class)) {
            try {
                return StorefrontRenderContextReader::maintenance();
            } catch (\Throwable) {
            }
        }

        return self::current()?->maintenance;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public static function themeMetaList(string $area, string $type): ?array
    {
        $themeMeta = self::themeMetaPayload();
        if ($themeMeta === null) {
            return null;
        }

        $area = \trim($area);
        $type = \trim($type);
        if ($area === '' || $type === '') {
            return null;
        }

        $listKey = $area . '.' . $type;
        foreach ([
            $themeMeta['lists'][$listKey] ?? null,
            $themeMeta[$area][$type] ?? null,
            $themeMeta['lists'][$area][$type] ?? null,
            $themeMeta[$listKey] ?? null,
        ] as $candidate) {
            if (\is_array($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function themeMetaIdentify(string $identify): ?array
    {
        $identify = \trim($identify);
        if ($identify === '') {
            return null;
        }
        $themeMeta = self::themeMetaPayload();
        if ($themeMeta === null) {
            return null;
        }

        $byIdentify = $themeMeta['by_identify'] ?? $themeMeta['byIdentify'] ?? null;
        if (\is_array($byIdentify) && isset($byIdentify[$identify]) && \is_array($byIdentify[$identify])) {
            return $byIdentify[$identify];
        }

        $parts = \explode('.', $identify);
        if (\count($parts) >= 4 && $parts[0] === 'theme') {
            $list = self::themeMetaList($parts[1], $parts[2]);
            if (\is_array($list)) {
                foreach ($list as $row) {
                    if (\is_array($row) && ($row['meta_identify'] ?? '') === $identify) {
                        return $row;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Lazy-merge theme_meta into the single authority bag (Installer leaves it null).
     *
     * @param array<string, mixed> $themeMeta
     */
    public static function mergeThemeMeta(array $themeMeta): void
    {
        if ($themeMeta === [] || !\class_exists(StorefrontRenderContextReader::class)) {
            return;
        }
        try {
            StorefrontRenderContextReader::mergeFields(['theme_meta' => $themeMeta]);
        } catch (\Throwable) {
        }
    }

    /**
     * After ThemeData loads a meta list from HotCache/DB, publish into the bag.
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function mergeThemeMetaList(string $area, string $type, array $rows): void
    {
        $area = \trim($area);
        $type = \trim($type);
        if ($area === '' || $type === '') {
            return;
        }
        $existing = self::themeMetaPayload() ?? [];
        $listKey = $area . '.' . $type;
        $lists = \is_array($existing['lists'] ?? null) ? $existing['lists'] : [];
        $lists[$listKey] = $rows;
        $byIdentify = \is_array($existing['by_identify'] ?? null) ? $existing['by_identify'] : [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $identify = \trim((string)($row['meta_identify'] ?? ''));
            if ($identify !== '') {
                $byIdentify[$identify] = $row;
            }
        }
        self::mergeThemeMeta([
            'lists' => $lists,
            'by_identify' => $byIdentify,
            'area' => $area,
        ]);
    }

    /**
     * @return array<string, mixed>|list<array<string, mixed>>|null
     */
    public static function websiteTableSnapshot(): array|null
    {
        $ctx = self::current();
        if (!$ctx instanceof StorefrontRenderContext) {
            return null;
        }

        return $ctx->websiteTableSnapshot;
    }

    /**
     * Fields merged into SlotRenderer page render context (not offer/session).
     *
     * @return array<string, mixed>
     */
    public static function captureFields(): array
    {
        $ctx = self::current();
        if (!$ctx instanceof StorefrontRenderContext) {
            return [];
        }

        $out = [
            'website_id' => $ctx->websiteId,
            'website_code' => $ctx->websiteCode,
            'locale' => $ctx->locale,
            'currency' => $ctx->currency,
            'timezone' => $ctx->timezone,
            'locale_catalog' => $ctx->localeCatalog,
        ];
        if ($ctx->websiteLocal !== null) {
            $out['website_local'] = $ctx->websiteLocal;
        }
        if ($ctx->maintenance !== null) {
            $out['maintenance'] = $ctx->maintenance;
        }
        if ($ctx->themeMeta !== null) {
            $out['theme_meta'] = $ctx->themeMeta;
        }
        if ($ctx->websiteTableSnapshot !== null) {
            $out['website_table_snapshot'] = $ctx->websiteTableSnapshot;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function themeMetaPayload(): ?array
    {
        if (\class_exists(StorefrontRenderContextReader::class)) {
            try {
                $meta = StorefrontRenderContextReader::themeMeta();
                if (\is_array($meta)) {
                    return $meta;
                }
            } catch (\Throwable) {
            }
        }
        $ctx = self::current();

        return $ctx instanceof StorefrontRenderContext ? $ctx->themeMeta : null;
    }

    /**
     * @return array{local_code?:string,name?:string,description?:string}|null
     */
    private static function resolveWebsiteLocalRow(): ?array
    {
        $rows = self::websiteLocal();
        if ($rows === null || $rows === []) {
            return null;
        }

        $locale = self::locale();
        if ($locale === null && RequestContext::isInitialized()) {
            try {
                $locale = \trim((string)(RequestContext::locale() ?? ''));
            } catch (\Throwable) {
                $locale = '';
            }
        }
        if ($locale !== null && $locale !== '') {
            foreach ($rows as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $code = \trim((string)($row['local_code'] ?? ''));
                if ($code !== '' && \strcasecmp($code, $locale) === 0) {
                    return $row;
                }
            }
        }

        $first = $rows[0] ?? null;

        return \is_array($first) ? $first : null;
    }
}
