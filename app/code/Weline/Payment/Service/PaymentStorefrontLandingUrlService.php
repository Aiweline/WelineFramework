<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Website;

/**
 * Rebuilds payment/checkout browser landings under a frozen website storefront base
 * (incl. path-prefix mounts such as /daocharms).
 */
class PaymentStorefrontLandingUrlService
{
    public function __construct(
        private readonly ?ObjectManager $objectManager = null,
    ) {
    }

    /**
     * Resolve storefront base URL (scheme://host[:port][/mount]) for a website.
     *
     * Priority: explicit freeze → request-built hint → current HTTP request mount → Website.url.
     * Website.url may be the production apex (e.g. https://daocharms.com) while local
     * acceptance uses path-prefix Host (e.g. https://p….test.weline.com/daocharms).
     */
    public function resolveStorefrontBaseUrl(
        int $websiteId = 0,
        string $frozenBaseUrl = '',
        string $hintLandingUrl = '',
    ): string {
        $frozen = $this->normalizeBaseUrl($frozenBaseUrl);
        $fromHint = $this->extractBaseFromLanding($hintLandingUrl);
        $fromRequest = $this->resolveBaseFromCurrentRequest($websiteId);
        $fromWebsite = $this->loadWebsiteBaseUrl($websiteId);

        // Prefer the most specific live base (request / hint mount) over a stale freeze
        // that only captured Host without path-prefix (e.g. …/daocharms).
        $preferredLive = $this->preferMoreSpecificBase($fromRequest, $fromHint);
        if ($preferredLive !== '') {
            if ($frozen === '' || $this->isStaleFreeze($frozen, $preferredLive)) {
                return $preferredLive;
            }
        }
        if ($frozen !== '' && !$this->isCrossHostMismatch($frozen, $fromRequest, $fromHint)) {
            return $frozen;
        }
        if ($fromHint !== '') {
            return $fromHint;
        }
        if ($fromRequest !== '') {
            return $fromRequest;
        }
        if ($frozen !== '') {
            return $frozen;
        }

        return $fromWebsite;
    }

    /**
     * Prefer request/hint base that includes a longer path mount.
     */
    private function preferMoreSpecificBase(string $fromRequest, string $fromHint): string
    {
        $a = $this->normalizeBaseUrl($fromRequest);
        $b = $this->normalizeBaseUrl($fromHint);
        if ($a === '') {
            return $b;
        }
        if ($b === '') {
            return $a;
        }
        $pathA = (string) (parse_url($a, PHP_URL_PATH) ?? '');
        $pathB = (string) (parse_url($b, PHP_URL_PATH) ?? '');

        return \strlen($pathB) > \strlen($pathA) ? $b : $a;
    }

    /**
     * Frozen base is stale when live base shares host but has a longer mount path,
     * or when hosts differ (production apex vs local project Host).
     */
    private function isStaleFreeze(string $frozen, string $live): bool
    {
        $frozenHost = strtolower((string) (parse_url($frozen, PHP_URL_HOST) ?? ''));
        $liveHost = strtolower((string) (parse_url($live, PHP_URL_HOST) ?? ''));
        if ($frozenHost === '' || $liveHost === '') {
            return false;
        }
        if ($frozenHost !== $liveHost) {
            return true;
        }
        $frozenPath = rtrim((string) (parse_url($frozen, PHP_URL_PATH) ?? ''), '/');
        $livePath = rtrim((string) (parse_url($live, PHP_URL_PATH) ?? ''), '/');

        return $livePath !== '' && $livePath !== $frozenPath && str_starts_with($livePath, $frozenPath === '' ? '/' : $frozenPath);
    }

    /**
     * Prefer local request / hint when frozen base points at a different host
     * (typical: Website.url = production apex, request = *.test.weline.com/mount).
     */
    private function isCrossHostMismatch(string $frozen, string $fromRequest, string $fromHint): bool
    {
        $frozenHost = strtolower((string) (parse_url($frozen, PHP_URL_HOST) ?? ''));
        if ($frozenHost === '') {
            return false;
        }
        foreach ([$fromRequest, $fromHint] as $candidate) {
            $host = strtolower((string) (parse_url($candidate, PHP_URL_HOST) ?? ''));
            if ($host !== '' && $host !== $frozenHost) {
                return true;
            }
        }

        return false;
    }

    /**
     * Live request public base: actual Host header + published WebsiteDomain.sub_path.
     *
     * Do NOT use Request::getBaseHost() here — on path-prefix mounts it often returns
     * Website.url (production apex like https://daocharms.com) via WELINE_WEBSITE_URL,
     * which would send PayPal return_url off the local acceptance Host.
     */
    public function resolveBaseFromCurrentRequest(int $websiteId = 0): string
    {
        try {
            $request = w_obj(\Weline\Framework\Http\Request::class);
            if ($websiteId > 0) {
                $ctxId = (int) \Weline\Framework\Runtime\RequestContext::getWelineWebsiteId();
                if ($ctxId > 0 && $ctxId !== $websiteId) {
                    return '';
                }
            } else {
                $websiteId = (int) \Weline\Framework\Runtime\RequestContext::getWelineWebsiteId();
            }

            $httpHost = trim((string) ($request->getHeader('Host') ?? ''));
            if ($httpHost === '') {
                $httpHost = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
            }
            if ($httpHost === '') {
                return '';
            }

            $scheme = 'https';
            try {
                $scheme = $request->isSecure() ? 'https' : 'http';
            } catch (\Throwable) {
                $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
                $scheme = ($https !== '' && $https !== 'off') ? 'https' : 'http';
            }

            $hostName = $httpHost;
            $port = '';
            if (str_contains($httpHost, ':')) {
                [$hostName, $port] = explode(':', $httpHost, 2);
            }
            $hostName = strtolower(trim($hostName));
            if ($hostName === '') {
                return '';
            }

            $subPath = $this->resolvePublishedSubPath($websiteId, $hostName, $request);
            if ($subPath === '') {
                $subPath = $this->resolveSubPathFromReferer($request, $hostName);
            }
            $portSuffix = '';
            if ($port !== '' && !(
                ($scheme === 'https' && $port === '443')
                || ($scheme === 'http' && $port === '80')
            )) {
                $portSuffix = ':' . $port;
            }

            return $this->normalizeBaseUrl($scheme . '://' . $hostName . $portSuffix . $subPath);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Fallback mount from Referer/Origin when worker URI has no storefront prefix.
     */
    private function resolveSubPathFromReferer(object $request, string $hostName): string
    {
        $candidates = [];
        foreach (['Referer', 'Origin', 'referer', 'origin'] as $header) {
            try {
                $value = trim((string) ($request->getHeader($header) ?? ''));
            } catch (\Throwable) {
                $value = '';
            }
            if ($value !== '') {
                $candidates[] = $value;
            }
        }
        foreach (['HTTP_REFERER', 'HTTP_ORIGIN'] as $key) {
            $value = trim((string) ($_SERVER[$key] ?? ''));
            if ($value !== '') {
                $candidates[] = $value;
            }
        }
        foreach ($candidates as $url) {
            $parts = parse_url($url);
            if (!\is_array($parts)) {
                continue;
            }
            $refHost = strtolower(trim((string) ($parts['host'] ?? '')));
            if ($refHost !== $hostName) {
                continue;
            }
            $path = (string) ($parts['path'] ?? '');
            if ($path === '' || $path === '/') {
                continue;
            }
            // First path segment as mount candidate: /daocharms/checkout → /daocharms
            if (preg_match('#^(/[^/]+)#', $path, $m)) {
                $seg = $m[1];
                if (!\in_array(strtolower($seg), ['/payment', '/checkout', '/cart', '/customer', '/weline', '/pub', '/static', '/api'], true)) {
                    return $seg;
                }
            }
        }

        return '';
    }

    /**
     * Longest WebsiteDomain.sub_path for this website+host.
     * Prefer a sub_path that matches the request URI; otherwise use the published
     * non-empty mount for this website on this Host (Frontend Worker calls often
     * hit a bin/query path without the storefront mount prefix).
     */
    private function resolvePublishedSubPath(int $websiteId, string $hostName, object $request): string
    {
        try {
            $om = $this->objectManager ?? ObjectManager::getInstance();
            /** @var \Weline\Websites\Model\WebsiteDomain $domain */
            $domain = $om->getInstance(\Weline\Websites\Model\WebsiteDomain::class);
            // Load by website first (authoritative), then filter host in PHP — avoids
            // query-builder edge cases on residual WHERE from shared model instances.
            $rows = [];
            if ($websiteId >= 0) {
                $rows = $domain->clear()
                    ->where(\Weline\Websites\Model\WebsiteDomain::schema_fields_WEBSITE_ID, $websiteId)
                    ->select()
                    ->fetch()
                    ->getItems();
            }
            if ($rows === []) {
                $rows = $domain->clear()
                    ->where(\Weline\Websites\Model\WebsiteDomain::schema_fields_DOMAIN, $hostName)
                    ->select()
                    ->fetch()
                    ->getItems();
            }

            $uriPath = '/';
            try {
                $uriPath = (string) parse_url((string) $request->getUri(), PHP_URL_PATH);
            } catch (\Throwable) {
                $uriPath = (string) ($_SERVER['REQUEST_URI'] ?? '/');
                $uriPath = (string) (parse_url($uriPath, PHP_URL_PATH) ?: '/');
            }
            if ($uriPath === '') {
                $uriPath = '/';
            }

            $bestMatch = '';
            $bestPublished = '';
            foreach ($rows as $row) {
                $rowHost = strtolower(trim((string) $row->getData(
                    \Weline\Websites\Model\WebsiteDomain::schema_fields_DOMAIN
                )));
                if ($rowHost !== '' && $rowHost !== $hostName) {
                    continue;
                }
                if ($websiteId > 0
                    && (int) $row->getData(\Weline\Websites\Model\WebsiteDomain::schema_fields_WEBSITE_ID) !== $websiteId
                ) {
                    continue;
                }
                $sub = trim((string) $row->getData(\Weline\Websites\Model\WebsiteDomain::schema_fields_SUB_PATH));
                if ($sub === '' || $sub === '/') {
                    continue;
                }
                if ($sub[0] !== '/') {
                    $sub = '/' . $sub;
                }
                $sub = rtrim($sub, '/');
                if ($sub === '') {
                    continue;
                }
                if (\strlen($sub) > \strlen($bestPublished)) {
                    $bestPublished = $sub;
                }
                if (str_starts_with($uriPath, $sub . '/') || $uriPath === $sub) {
                    if (\strlen($sub) > \strlen($bestMatch)) {
                        $bestMatch = $sub;
                    }
                }
            }

            return $bestMatch !== '' ? $bestMatch : $bestPublished;
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Build absolute URL for a frontend route under the storefront base.
     *
     * @param array<string, scalar|null> $query
     */
    public function buildAbsoluteRoute(
        string $routePath,
        array $query = [],
        string $storefrontBaseUrl = '',
        string $hintLandingUrl = '',
        int $websiteId = 0,
    ): string {
        $base = $this->resolveStorefrontBaseUrl($websiteId, $storefrontBaseUrl, $hintLandingUrl);
        $routePath = trim($routePath);
        $routePath = ltrim($routePath, '/');
        if ($routePath === '') {
            return $base !== '' ? $base : '/';
        }

        if ($base === '') {
            $path = '/' . $routePath;
            if ($query !== []) {
                $path .= '?' . http_build_query($this->filterQuery($query), '', '&', PHP_QUERY_RFC3986);
            }

            return $path;
        }

        $url = rtrim($base, '/') . '/' . $routePath;
        if ($query !== []) {
            $url .= '?' . http_build_query($this->filterQuery($query), '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }

    /**
     * If landing is absolute but not under the frozen base, rebuild same route under base.
     * Relative host-root paths (e.g. /checkout/success) are rebuilt under base when known.
     */
    public function ensureLandingUnderBase(string $landingUrl, string $storefrontBaseUrl): string
    {
        $landingUrl = trim($landingUrl);
        $base = $this->normalizeBaseUrl($storefrontBaseUrl);
        if ($landingUrl === '' || $base === '') {
            return $landingUrl;
        }

        $parts = parse_url($landingUrl);
        if (!\is_array($parts)) {
            return $landingUrl;
        }

        $isAbsolute = !empty($parts['scheme']) && !empty($parts['host']);
        if ($isAbsolute) {
            $normalizedLanding = $this->normalizeAbsoluteOriginPath($landingUrl);
            $normalizedBase = rtrim($base, '/');
            if (str_starts_with($normalizedLanding, $normalizedBase . '/')
                || $normalizedLanding === $normalizedBase
            ) {
                return $landingUrl;
            }
        }

        $path = (string) ($parts['path'] ?? '');
        $route = $this->stripMountFromPath($path, $base);
        if ($route === '') {
            $route = ltrim($path, '/');
        }
        if ($route === '') {
            return $landingUrl;
        }

        $query = [];
        if (isset($parts['query']) && \is_string($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $query);
            if (!\is_array($query)) {
                $query = [];
            }
        }

        $rebuilt = $this->buildAbsoluteRoute($route, $query, $base);
        if (!empty($parts['fragment'])) {
            $rebuilt .= '#' . $parts['fragment'];
        }

        return $rebuilt;
    }

    /**
     * Extract storefront base from an absolute landing that contains a known checkout route.
     */
    public function extractBaseFromLanding(string $landingUrl): string
    {
        $landingUrl = trim($landingUrl);
        if ($landingUrl === '') {
            return '';
        }

        $parts = parse_url($landingUrl);
        if (!\is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        $path = (string) ($parts['path'] ?? '');
        foreach (['/checkout/express-review', '/checkout/success', '/payment/handoff', '/payment/success'] as $needle) {
            $pos = strpos($path, $needle);
            if ($pos !== false) {
                $mount = substr($path, 0, $pos);

                return $this->composeOrigin($parts) . rtrim($mount, '/');
            }
        }

        // Absolute URL without known route: treat dirname of path as mount if nested.
        if ($path !== '' && $path !== '/') {
            $dir = rtrim(\dirname($path), '/');
            if ($dir !== '' && $dir !== '.' && $dir !== '/') {
                return $this->composeOrigin($parts) . $dir;
            }
        }

        return $this->composeOrigin($parts);
    }

    public function loadWebsiteBaseUrl(int $websiteId): string
    {
        try {
            $om = $this->objectManager ?? ObjectManager::getInstance();
            /** @var Website $website */
            $website = $om->getInstance(Website::class);
            if ($websiteId > 0) {
                $website->load($websiteId);
                if ((int) $website->getId() !== $websiteId) {
                    return '';
                }
            } else {
                $website->clear()->load(0);
                if ((string) $website->getData(Website::schema_fields_URL) === '') {
                    $website->clear()
                        ->where(Website::schema_fields_ID, 0, '>=')
                        ->order(Website::schema_fields_ID, 'ASC')
                        ->find()
                        ->fetch();
                }
            }

            return $this->normalizeBaseUrl((string) $website->getUrl());
        } catch (\Throwable) {
            return '';
        }
    }

    public function loadWebsiteCode(int $websiteId): string
    {
        if ($websiteId < 0) {
            return '';
        }
        try {
            $om = $this->objectManager ?? ObjectManager::getInstance();
            /** @var Website $website */
            $website = $om->getInstance(Website::class);
            $website->load($websiteId);
            if ($websiteId > 0 && (int) $website->getId() !== $websiteId) {
                return '';
            }
            $code = strtolower(trim((string) $website->getCode()));
            if ($code === '' && $websiteId === 0) {
                return 'default';
            }

            return $code;
        } catch (\Throwable) {
            return $websiteId === 0 ? 'default' : '';
        }
    }

    public function normalizeBaseUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            $url = 'https://' . ltrim($url, '/');
        }
        $parts = parse_url($url);
        if (!\is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        return rtrim($this->composeOrigin($parts) . rtrim((string) ($parts['path'] ?? ''), '/'), '/');
    }

    /**
     * @param array<string, mixed> $parts
     */
    private function composeOrigin(array $parts): string
    {
        $origin = (string) ($parts['scheme'] ?? 'https') . '://' . (string) ($parts['host'] ?? '');
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }

    private function normalizeAbsoluteOriginPath(string $url): string
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        return rtrim($this->composeOrigin($parts) . rtrim((string) ($parts['path'] ?? ''), '/'), '/');
    }

    private function stripMountFromPath(string $path, string $base): string
    {
        $path = '/' . ltrim($path, '/');
        $baseParts = parse_url($base);
        $mount = '';
        if (\is_array($baseParts)) {
            $mount = rtrim((string) ($baseParts['path'] ?? ''), '/');
        }
        if ($mount !== '' && str_starts_with($path, $mount . '/')) {
            return ltrim(substr($path, \strlen($mount)), '/');
        }
        if ($mount !== '' && $path === $mount) {
            return '';
        }

        return ltrim($path, '/');
    }

    /**
     * @param array<string, scalar|null> $query
     * @return array<string, scalar>
     */
    private function filterQuery(array $query): array
    {
        $out = [];
        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }
            $out[(string) $key] = $value;
        }

        return $out;
    }
}
