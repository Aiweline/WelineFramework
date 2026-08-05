<?php

declare(strict_types=1);

namespace Weline\I18n\Api\Seo;

/**
 * Pure canonical localized URL builder.
 *
 * Does not read Cookie, $_SERVER, or the current request. Callers must pass
 * explicit base URL, route, locale, and default locale/currency.
 */
interface LocalizedUrlBuilderInterface
{
    /**
     * Build a canonical absolute URL for the given locale.
     *
     * Output prefix order is currency then locale. Default locale/currency do
     * not add path segments. Existing language/currency prefixes on routePath
     * are stripped before rebuilding.
     */
    public function build(
        string $baseUrl,
        string $routePath,
        string $locale,
        string $defaultLocale,
        ?string $currency = null,
        ?string $defaultCurrency = null,
    ): string;
}
