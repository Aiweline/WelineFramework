<?php

declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Visitor\Model\Pixel;

/**
 * Accepts only the compact, rendered PageBuilder V2 attribution envelope.
 *
 * Browser metrics are never accepted here: every aggregate is derived from
 * persisted real Pixel events. Invalid, preview, or consent-denied envelopes
 * are deliberately stored without optimization attribution, so they cannot
 * enter the optimization snapshot.
 */
final class PageBuilderOptimizationAttributionService
{
    private const VERSION = 'pagebuilder_ai_v1';

    /** @var array<string,true> */
    private const ALLOWED_EVENTS = [
        'page_view' => true,
        'ai_block_impression' => true,
        'hero_cta_click' => true,
        'pricing_cta_click' => true,
        'lead_submit' => true,
        'signup_click' => true,
        'contact_click' => true,
        'download_click' => true,
        'booking_click' => true,
        'demo_request_click' => true,
        'add_to_cart' => true,
        'buy_now' => true,
        'begin_checkout' => true,
        'route_click' => true,
        'view_item' => true,
        'proof_badge_interaction' => true,
    ];

    /**
     * @return list<string>
     */
    public static function allowedEvents(): array
    {
        return \array_keys(self::ALLOWED_EVENTS);
    }

    public static function isAllowedEvent(string $event): bool
    {
        return isset(self::ALLOWED_EVENTS[$event]);
    }

    /**
     * @param array<string,mixed> $post
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function hydrate(array $post, array $data): array
    {
        $data = $this->withoutOptimizationAttribution($data);
        $additional = \is_array($post['additionalInfo'] ?? null) ? $post['additionalInfo'] : [];
        $raw = \is_array($additional['pagebuilder_attribution'] ?? null)
            ? $additional['pagebuilder_attribution']
            : [];
        if ($raw === [] || (string)($post['source'] ?? '') !== 'worker') {
            return $data;
        }

        if (
            \trim((string)($raw['attribution_version'] ?? '')) !== self::VERSION
            || \trim((string)($raw['source'] ?? '')) !== 'pagebuilder_rendered_dom'
            || \trim((string)($raw['surface'] ?? '')) !== 'published'
            || !empty($raw['preview'])
            || \trim((string)($raw['analytics_consent'] ?? '')) !== 'granted'
        ) {
            return $data;
        }

        $websiteId = $this->nonNegativeInteger($data[Pixel::schema_fields_WEBSITE_ID] ?? null);
        $attributionWebsiteId = $this->nonNegativeInteger($raw['website_id'] ?? null);
        $environment = \is_array($additional['environment'] ?? null) ? $additional['environment'] : [];
        $environmentWebsiteId = $this->nonNegativeInteger($environment['website_id'] ?? null);
        if (
            $websiteId === null
            || $attributionWebsiteId === null
            || $environmentWebsiteId === null
            || $websiteId !== $attributionWebsiteId
            || $websiteId !== $environmentWebsiteId
            || !$this->matchesCanonicalPath((string)($data[Pixel::schema_fields_URL] ?? ''), (string)($raw['canonical_path'] ?? ''))
        ) {
            return $data;
        }

        $event = \strtolower(\trim((string)($data[Pixel::schema_fields_EVENT] ?? '')));
        $pageType = $this->identifier($raw['page_type'] ?? '', 64);
        $revision = $this->nonNegativeInteger($raw['plan_revision'] ?? null);
        if (!isset(self::ALLOWED_EVENTS[$event]) || $pageType === '' || $revision === null) {
            return $data;
        }

        $isPageView = $event === 'page_view';
        $blockKey = $isPageView ? '' : $this->identifier($raw['block_key'] ?? '', 128);
        $fingerprint = $isPageView ? '' : $this->fingerprint($raw['content_fingerprint'] ?? '');
        if (!$isPageView && ($blockKey === '' || $fingerprint === '')) {
            return $data;
        }

        $experimentId = $this->identifier(
            $isPageView
                ? ($raw['page_experiment_id'] ?? $raw['experiment_id'] ?? '')
                : ($raw['experiment_id'] ?? ''),
            96
        );
        $variant = $this->identifier(
            $isPageView
                ? ($raw['page_variant'] ?? $raw['variant'] ?? '')
                : ($raw['variant'] ?? ''),
            32
        );

        $data[Pixel::schema_fields_ATTRIBUTION_VERSION] = self::VERSION;
        $data[Pixel::schema_fields_PAGE_TYPE] = $pageType;
        $data[Pixel::schema_fields_BLOCK_KEY] = $blockKey;
        $data[Pixel::schema_fields_PLAN_REVISION] = $revision;
        $data[Pixel::schema_fields_CONTENT_FINGERPRINT] = $fingerprint;
        $data[Pixel::schema_fields_EXPERIMENT_ID] = $experimentId;
        $data[Pixel::schema_fields_VARIANT] = $variant;

        return $data;
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function withoutOptimizationAttribution(array $data): array
    {
        $data[Pixel::schema_fields_ATTRIBUTION_VERSION] = '';
        $data[Pixel::schema_fields_PAGE_TYPE] = '';
        $data[Pixel::schema_fields_BLOCK_KEY] = '';
        $data[Pixel::schema_fields_PLAN_REVISION] = 0;
        $data[Pixel::schema_fields_CONTENT_FINGERPRINT] = '';
        $data[Pixel::schema_fields_EXPERIMENT_ID] = '';
        $data[Pixel::schema_fields_VARIANT] = '';

        return $data;
    }

    private function nonNegativeInteger(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (!\is_string($value) || \preg_match('/^(?:0|[1-9][0-9]*)$/D', \trim($value)) !== 1) {
            return null;
        }

        return (int)\trim($value);
    }

    private function identifier(mixed $value, int $maxLength): string
    {
        if (!\is_scalar($value) && !$value instanceof \Stringable) {
            return '';
        }
        $value = \trim((string)$value);
        if ($value === '' || \strlen($value) > $maxLength) {
            return '';
        }

        return \preg_match('/^[A-Za-z0-9_-]+$/D', $value) === 1 ? $value : '';
    }

    private function fingerprint(mixed $value): string
    {
        $value = \strtolower(\trim((string)$value));

        return \preg_match('/^[a-f0-9]{64}$/D', $value) === 1 ? $value : '';
    }

    private function matchesCanonicalPath(string $url, string $canonicalPath): bool
    {
        $canonicalPath = \trim($canonicalPath);
        if (
            $canonicalPath === ''
            || !\str_starts_with($canonicalPath, '/')
            || \str_contains($canonicalPath, '?')
            || \str_contains($canonicalPath, '#')
        ) {
            return false;
        }
        $path = \parse_url($url, \PHP_URL_PATH);
        $path = \is_string($path) && $path !== '' ? $path : '/';

        return $this->normalizePath($path) === $this->normalizePath($canonicalPath);
    }

    private function normalizePath(string $path): string
    {
        $path = '/' . \ltrim(\trim($path), '/');
        if ($path !== '/' && \str_ends_with($path, '/')) {
            $path = \rtrim($path, '/');
        }

        return $path;
    }
}
