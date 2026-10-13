<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Fpc\OfflineBake;

use Weline\Framework\Http\Fpc\FpcPayloadPathIndex;

/**
 * Derive lang/currency hints from a host-scoped storefront URL path.
 * Preference cookies must not fork FPC; path prefixes do.
 *
 * Heuristic only (no App\State / Env bootstrap) so bake export works in unit tests
 * and CLI before full request context. Live HIT still uses StorefrontCacheKeyContext.
 */
final class FpcOfflineBakeLocaleHint
{
    /**
     * @return array{lang:string,currency:string}
     */
    public static function fromFullUri(string $fullUri, string $defaultLang = 'zh_Hans_CN', string $defaultCurrency = 'CNY'): array
    {
        $indexKey = FpcPayloadPathIndex::normalizeIndexKey($fullUri);
        $path = '/';
        if ($indexKey !== '' && \str_contains($indexKey, '://')) {
            $parsed = \parse_url($indexKey, \PHP_URL_PATH);
            $path = \is_string($parsed) && $parsed !== '' ? $parsed : '/';
        }
        $segments = \array_values(\array_filter(
            \explode('/', \trim($path, '/')),
            static fn(string $s): bool => $s !== '',
        ));

        $lang = '';
        $currency = '';
        foreach ($segments as $segment) {
            if ($currency === '' && \preg_match('/^[A-Z]{3}$/', $segment) === 1) {
                $currency = $segment;
                continue;
            }
            // en_US / zh_Hans_CN / pt_BR …
            if ($lang === '' && \preg_match('/^[a-z]{2}(_[A-Za-z0-9]+)+$/', $segment) === 1) {
                $lang = $segment;
            }
        }

        return [
            'lang' => $lang !== '' ? $lang : $defaultLang,
            'currency' => $currency !== '' ? $currency : $defaultCurrency,
        ];
    }
}
