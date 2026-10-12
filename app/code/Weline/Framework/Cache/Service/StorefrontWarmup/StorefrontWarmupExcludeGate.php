<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Service\StorefrontWarmup;

use Weline\Framework\App\Env;
use Weline\Framework\App\State;

/**
 * Skip paths that must not receive storefront HTML FPC priming.
 */
final class StorefrontWarmupExcludeGate
{
    /** @var list<string> */
    private const DEFAULT_PREFIXES = [
        '/search',
        '/cart',
        '/checkout',
        '/customer',
        '/account',
        '/wishlist',
        '/helppay',
        '/api/',
        '/rest/',
        '/backend',
        '/admin',
        '/graphql',
    ];

    /**
     * @return array{allow:bool,reason:string,path:string}
     */
    public function evaluate(string $rawUrlOrPath): array
    {
        $path = $this->normalizePath($rawUrlOrPath);
        if ($path === '') {
            return ['allow' => false, 'reason' => 'empty_path', 'path' => ''];
        }

        if ($this->isBlacklisted($path)) {
            return ['allow' => false, 'reason' => 'blacklist', 'path' => $path];
        }

        // Pagination / filter / sort query forms are never primed as HTML FPC.
        $query = (string)(\parse_url($rawUrlOrPath, \PHP_URL_QUERY) ?: '');
        if ($query !== '' && \preg_match('/(?:^|&)(?:page|p|sort|order|filter|q)=/i', $query) === 1) {
            return ['allow' => false, 'reason' => 'query_combo', 'path' => $path];
        }

        $defaults = $this->resolveWebsiteDefaults();
        if ($defaults['language'] !== '' || $defaults['currency'] !== '') {
            try {
                $canonical = State::canonicalizeStorefrontLocalizationPath(
                    $path,
                    $defaults['language'],
                    $defaults['currency'],
                );
                if ($canonical !== null) {
                    return ['allow' => false, 'reason' => 'default_prefix_301', 'path' => $path];
                }
            } catch (\Throwable) {
                // Fail-open on canonicalize errors — still warm path shape.
            }
        }

        return ['allow' => true, 'reason' => '', 'path' => $path];
    }

    public function normalizePath(string $rawUrlOrPath): string
    {
        $raw = \str_replace(["\r", "\n", "\t"], '', \trim($rawUrlOrPath));
        if ($raw === '') {
            return '';
        }
        if (\str_contains($raw, '://')) {
            $parsed = \parse_url($raw, \PHP_URL_PATH);
            $raw = \is_string($parsed) ? $parsed : '';
        }
        $raw = \trim($raw);
        if ($raw === '') {
            return '/';
        }
        if ($raw[0] !== '/') {
            $raw = '/' . $raw;
        }

        return $raw === '' ? '/' : $raw;
    }

    private function isBlacklisted(string $path): bool
    {
        $pathLower = \strtolower($path);
        foreach ($this->blacklistPrefixes() as $prefix) {
            $prefix = \strtolower($prefix);
            if ($prefix === '') {
                continue;
            }
            if ($pathLower === $prefix || \str_starts_with($pathLower, \rtrim($prefix, '/') . '/')) {
                return true;
            }
            if ($prefix[-1] === '*' && \str_starts_with($pathLower, \rtrim($prefix, '*'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function blacklistPrefixes(): array
    {
        $prefixes = self::DEFAULT_PREFIXES;
        try {
            $configured = Env::get('framework.cache.storefront_warmup_blacklist', []);
        } catch (\Throwable) {
            return $prefixes;
        }
        if (\is_string($configured)) {
            $decoded = \json_decode($configured, true);
            $configured = \is_array($decoded)
                ? $decoded
                : (\preg_split('/[,\s]+/', $configured, -1, \PREG_SPLIT_NO_EMPTY) ?: []);
        }
        if (\is_array($configured)) {
            foreach ($configured as $item) {
                if (\is_scalar($item)) {
                    $prefixes[] = (string)$item;
                }
            }
        }

        return \array_values(\array_unique($prefixes));
    }

    /**
     * @return array{language:string,currency:string}
     */
    private function resolveWebsiteDefaults(): array
    {
        $language = '';
        $currency = '';
        try {
            $language = \trim((string)(Env::get('website.language', null) ?: ''));
            $currency = \trim((string)(Env::get('currency', null) ?: Env::get('website.currency', null) ?: ''));
        } catch (\Throwable) {
        }
        try {
            if ($language === '') {
                $language = \trim(State::resolveWebsiteDefaultLanguage());
            }
            if ($currency === '') {
                $currency = \trim(State::resolveWebsiteDefaultCurrency());
            }
        } catch (\Throwable) {
        }

        return ['language' => $language, 'currency' => $currency];
    }
}
