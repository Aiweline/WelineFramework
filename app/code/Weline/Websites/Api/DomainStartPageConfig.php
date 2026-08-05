<?php

declare(strict_types=1);

namespace Weline\Websites\Api;

/**
 * Stable domain-scoped key builder for frontend root routes.
 */
final class DomainStartPageConfig
{
    private const KEY_PREFIX = 'frontend_start_page_path_domain_';

    public static function key(string $domain): string
    {
        $domain = self::normalizeDomain($domain);
        return $domain === '' ? '' : self::KEY_PREFIX . \hash('sha256', $domain);
    }

    public static function normalizeDomain(string $domain): string
    {
        $domain = \strtolower(\trim($domain));
        if ($domain === '') {
            return '';
        }

        $candidate = \str_contains($domain, '://') ? $domain : '//' . $domain;
        $host = \parse_url($candidate, PHP_URL_HOST);
        if (!\is_string($host) || $host === '') {
            return '';
        }

        $host = \rtrim(\strtolower(\trim($host)), '.');
        if (\filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return '';
        }

        return $host;
    }
}
