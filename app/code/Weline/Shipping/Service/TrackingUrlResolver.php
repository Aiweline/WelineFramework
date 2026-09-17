<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

/**
 * Resolve public tracking page URLs: prefer real carrier return, else 17track.
 * Placeholder hosts (example.com / example.test / …) are never exposed.
 */
final class TrackingUrlResolver
{
    public const DEFAULT_TEMPLATE = 'https://www.17track.net/zh-cn/track?nums={tracking_number}';

    /**
     * Host suffixes / exact hosts that must never be used as customer-facing track pages.
     *
     * @var list<string>
     */
    private const FORBIDDEN_HOST_MARKERS = [
        'example.com',
        'example.org',
        'example.net',
        'example.test',
        'invalid',
        'localhost',
        '127.0.0.1',
    ];

    public function defaultTemplate(): string
    {
        return self::DEFAULT_TEMPLATE;
    }

    public function isUsableTrackingUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }
        if (!preg_match('#^https?://#i', $url)) {
            return false;
        }
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host === '') {
            return false;
        }
        foreach (self::FORBIDDEN_HOST_MARKERS as $marker) {
            if ($host === $marker || str_ends_with($host, '.' . $marker)) {
                return false;
            }
        }

        return true;
    }

    public function isUsableTemplate(string $template): bool
    {
        $template = trim($template);
        if ($template === '' || !str_contains($template, '{tracking_number}')) {
            return false;
        }
        // Validate with a sentinel so query placeholders stay intact.
        $probe = str_replace('{tracking_number}', 'PROBE123', $template);

        return $this->isUsableTrackingUrl($probe);
    }

    /**
     * Prefer carrier/provider URL when usable; otherwise 17track.
     */
    public function resolve(string $trackingNumber, string $carrierOrProviderUrl = '', string $carrierTemplate = ''): string
    {
        $trackingNumber = trim($trackingNumber);
        if ($trackingNumber === '') {
            return '';
        }

        $candidate = trim($carrierOrProviderUrl);
        if ($candidate !== '' && $this->isUsableTrackingUrl($candidate)) {
            return $candidate;
        }

        $template = trim($carrierTemplate);
        if ($template !== '' && $this->isUsableTemplate($template)) {
            return str_replace('{tracking_number}', rawurlencode($trackingNumber), $template);
        }

        return str_replace('{tracking_number}', rawurlencode($trackingNumber), self::DEFAULT_TEMPLATE);
    }

    /**
     * Normalize a stored carrier template: forbidden → 17track default.
     */
    public function sanitizeTemplate(string $template): string
    {
        $template = trim($template);
        if ($this->isUsableTemplate($template)) {
            return $template;
        }

        return self::DEFAULT_TEMPLATE;
    }
}
