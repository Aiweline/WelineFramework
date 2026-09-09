<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Head;

use Weline\Framework\Runtime\RequestContext;

/**
 * Request-scoped SEO facts published by controllers/layouts.
 *
 * Theme layout materialization may unset Template data before Partials head
 * renders, so page-specific SEO must survive outside the Template bag.
 */
final class SeoPageProfileBag
{
    public const REQUEST_KEY = 'weline.seo.page_profile';

    /**
     * @param array<string, mixed> $profile
     */
    public static function publish(array $profile): void
    {
        if ($profile === []) {
            return;
        }

        $existing = self::pull();
        RequestContext::set(self::REQUEST_KEY, self::mergeProfiles($existing, $profile));
    }

    /**
     * Replace the request bag (used when a controller publishes a complete page profile).
     *
     * @param array<string, mixed> $profile
     */
    public static function replace(array $profile): void
    {
        RequestContext::set(self::REQUEST_KEY, is_array($profile) ? $profile : []);
    }

    public static function reset(): void
    {
        RequestContext::remove(self::REQUEST_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    public static function pull(): array
    {
        $value = RequestContext::get(self::REQUEST_KEY);

        return is_array($value) ? $value : [];
    }

    public static function fingerprint(): string
    {
        $payload = self::fingerprintPayload(self::pull());
        $payload['request_path'] = self::currentRequestPath();

        return sha1((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function currentRequestPath(): string
    {
        $fullUri = (string) \Weline\Framework\Env\WelineEnv::server('WELINE_FULL_REQUEST_URI', '');
        if ($fullUri !== '' && preg_match('/^https?:\/\//i', $fullUri)) {
            return (string) (parse_url($fullUri, PHP_URL_PATH) ?: '');
        }
        $uri = (string) \Weline\Framework\Env\WelineEnv::server('REQUEST_URI', '');
        if ($uri !== '') {
            return (string) (parse_url($uri, PHP_URL_PATH) ?: $uri);
        }

        return '';
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    private static function mergeProfiles(array $existing, array $incoming): array
    {
        $incomingType = strtolower(trim((string) ($incoming['page_type'] ?? '')));
        $existingType = strtolower(trim((string) ($existing['page_type'] ?? '')));
        // A concrete page profile must not keep leftovers from another page type
        // (e.g. product variants item_list bleeding into a later listing publish).
        if ($incomingType !== '' && $existingType !== '' && $incomingType !== $existingType) {
            $existing = [];
        }
        if ($incomingType === 'product') {
            unset($existing['item_list'], $existing['storefront_offers'], $existing['category']);
        }

        foreach (['schema_nodes', 'item_list', 'faqs', 'qa_list', 'breadcrumbs', 'breadcrumb_trails', 'reviews'] as $listKey) {
            if (!isset($incoming[$listKey]) || !is_array($incoming[$listKey])) {
                continue;
            }
            if (array_key_exists($listKey, $incoming)) {
                $existing[$listKey] = array_values($incoming[$listKey]);
                unset($incoming[$listKey]);
            }
        }

        return array_replace_recursive($existing, $incoming);
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private static function fingerprintPayload(array $profile): array
    {
        $product = is_array($profile['product'] ?? null) ? $profile['product'] : [];

        return [
            'page_type' => (string) ($profile['page_type'] ?? ''),
            'title' => (string) ($profile['title'] ?? ''),
            'description' => (string) ($profile['description'] ?? ''),
            'canonical_url' => (string) ($profile['canonical_url'] ?? $profile['canonical'] ?? ''),
            'image' => (string) ($profile['image'] ?? ''),
            'robots' => (string) ($profile['robots'] ?? ''),
            'product_id' => (string) ($product['product_id'] ?? $product['id'] ?? $product['sku'] ?? ''),
            'item_list_count' => is_array($profile['item_list'] ?? null) ? count($profile['item_list']) : 0,
        ];
    }
}
