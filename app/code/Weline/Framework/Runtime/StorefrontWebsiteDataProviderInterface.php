<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

/**
 * Optional storefront website projection supplied by the site/website module.
 *
 * Framework code must not depend on the website module directly; it resolves this
 * contract from the container and degrades when no provider is registered.
 */
interface StorefrontWebsiteDataProviderInterface
{
    /** @return list<string> */
    public function languageCodesFor(int $websiteId): array;

    public function defaultLanguageFor(int $websiteId): ?string;

    /** @return array<int|string, mixed> */
    public function localRowsFor(int $websiteId): array;
}
