<?php

declare(strict_types=1);

namespace Weline\Geo\Service\Protocol;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\StorefrontRenderContext;
use Weline\Framework\Runtime\StorefrontRenderContextReader;
use Weline\Seo\Service\SeoWebsiteDirectory;

/**
 * Binds GEO protocol copy (llms.txt / feeds / trust.txt) to the website being
 * generated instead of whatever site the worker happens to be rendering (E9).
 *
 * Rule: a brand name resolved from the request may only be used when the request
 * itself resolves to this very website id. Otherwise the name comes from the
 * registered website row for that id, then a host reverse lookup, then the host.
 */
final class SiteBindingResolver
{
    private const PLACEHOLDER_PATTERN = '/^(默认网站|默认店铺|Default Website|Default Store|Weline|Weline Framework|Website\s+\d+)(?:\s+(默认网站|默认店铺|Default Website|Default Store))*$/iu';

    public function __construct(
        private readonly SeoWebsiteDirectory $websiteDirectory,
    ) {
    }

    public function publicSiteName(object $website): string
    {
        $websiteId = $this->websiteIdOf($website);
        $registered = $websiteId >= 0 ? $this->websiteDirectory->getWebsiteById($websiteId, true) : null;
        $registeredName = trim((string)($registered['name'] ?? ''));

        if ($this->requestWebsiteId() === $websiteId) {
            $bound = $this->requestBoundSeoName($website);
            if ($bound !== '' && !$this->isPlaceholder($bound)) {
                return $bound;
            }
        }

        if ($registeredName !== '' && !$this->isPlaceholder($registeredName)) {
            return $registeredName;
        }

        $matched = $this->hostMatchedName($website);
        if ($matched !== '') {
            return $matched;
        }

        // $website->name is intentionally NOT trusted here: the website context is
        // merged with whatever site the worker currently renders, so an unbound name
        // is exactly the cross-site leak this class exists to stop (E9).

        $host = strtolower((string)(parse_url(trim((string)($website->url ?? '')), PHP_URL_HOST) ?? ''));

        return $host !== '' ? $host : 'Weline';
    }

    public function isPlaceholder(string $siteName): bool
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $siteName) ?? $siteName);

        return (bool) preg_match(self::PLACEHOLDER_PATTERN, $normalized);
    }

    private function websiteIdOf(object $website): int
    {
        return (int)($website->id ?? $website->website_id ?? 0);
    }

    private function requestWebsiteId(): ?int
    {
        $env = \w_env('website_id', null);
        if ($env !== null && $env !== '') {
            return (int)$env;
        }

        try {
            $context = StorefrontRenderContextReader::get();
            if ($context instanceof StorefrontRenderContext) {
                $row = $context->websiteTableSnapshot['row'] ?? null;
                if (is_array($row) && isset($row['website_id'])) {
                    return (int)$row['website_id'];
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function requestBoundSeoName(object $website): string
    {
        try {
            $context = ObjectManager::getInstance(
                \Weline\Seo\Api\Head\PageContextResolverInterface::class
            )->resolve(null, [
                'slot' => 'head',
                'canonical_url' => rtrim((string)($website->url ?? ''), '/') . '/',
                'url' => rtrim((string)($website->url ?? ''), '/') . '/',
            ]);

            return trim((string)($context->siteName ?? ''));
        } catch (\Throwable) {
            return '';
        }
    }

    private function hostMatchedName(object $website): string
    {
        $url = trim((string)($website->url ?? ''));
        if ($url === '') {
            return '';
        }

        try {
            $matched = $this->websiteDirectory->matchWebsiteByUrl($url);
        } catch (\Throwable) {
            return '';
        }

        $name = trim((string)($matched['name'] ?? ''));

        return $name !== '' && !$this->isPlaceholder($name) ? $name : '';
    }
}
