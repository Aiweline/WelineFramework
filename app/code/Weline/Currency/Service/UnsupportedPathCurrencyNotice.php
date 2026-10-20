<?php

declare(strict_types=1);

namespace Weline\Currency\Service;

use Weline\Framework\App\State;
use Weline\Framework\Runtime\RequestContext;

/**
 * Storefront notice when the visitor URL names a currency the website has not enabled.
 *
 * The request stays on that URL. Prices use the website default currency.
 * The visitor chooses an enabled currency; nothing redirects on its own.
 */
final class UnsupportedPathCurrencyNotice
{
    public const CONTEXT_KEY = 'route.rejected_path_currency';

    public function rejectedCode(): string
    {
        return strtoupper(trim((string) RequestContext::get(self::CONTEXT_KEY, '')));
    }

    public function isActive(): bool
    {
        return $this->rejectedCode() !== '';
    }

    public function canonicalUri(string $requestUri): string
    {
        $rejected = $this->rejectedCode();
        if ($rejected === '') {
            return $requestUri;
        }
        $default = strtoupper(trim(State::resolveWebsiteDefaultCurrency()));
        if ($default === '') {
            return $requestUri;
        }

        return self::replaceCurrencySegment($requestUri, $rejected, $default);
    }

    public static function replaceCurrencySegment(string $uri, string $from, string $to): string
    {
        $from = strtoupper(trim($from));
        $to = strtoupper(trim($to));
        if ($from === '' || $to === '' || $from === $to) {
            return $uri;
        }

        $parts = parse_url($uri);
        if (!is_array($parts)) {
            return $uri;
        }
        $path = (string) ($parts['path'] ?? '');
        if ($path === '' && !isset($parts['host'])) {
            $path = $uri;
            $parts = [];
        }
        $segments = explode('/', $path);
        $replaced = false;
        foreach ($segments as $index => $segment) {
            if ($segment !== '' && strtoupper($segment) === $from) {
                $segments[$index] = $to;
                $replaced = true;
                break;
            }
        }
        if (!$replaced) {
            return $uri;
        }
        $newPath = implode('/', $segments);
        if (!isset($parts['host'])) {
            $rebuilt = $newPath;
            if (isset($parts['query']) && $parts['query'] !== '') {
                $rebuilt .= '?' . $parts['query'];
            }

            return $rebuilt;
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $auth = $parts['host'];
        if (isset($parts['port'])) {
            $auth .= ':' . $parts['port'];
        }
        $rebuilt = $scheme . $auth . $newPath;
        if (isset($parts['query']) && $parts['query'] !== '') {
            $rebuilt .= '?' . $parts['query'];
        }

        return $rebuilt;
    }
}
