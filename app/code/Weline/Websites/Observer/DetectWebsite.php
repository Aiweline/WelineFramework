<?php

namespace Weline\Websites\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\App\State;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\Contract\NamespaceScopedCachePoolInterface;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Runtime\StorefrontNavigationScope;
use Weline\Framework\Runtime\StorefrontScopeInstallerInterface;
use Weline\Framework\Runtime\StorefrontWebsiteContext;
use Weline\Framework\Runtime\StorefrontWebsiteContextResolverInterface;
use Weline\Framework\Runtime\StorefrontWebsiteCodeResolverInterface;
use Weline\Server\Api\Domain\LocalDomainPolicy;
use Weline\Websites\Data\WebsiteData;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteDomain;
use Weline\Websites\Service\Exception\ScopeResolutionException;
use Weline\Websites\Service\ScopeResolver;
use Weline\Websites\Service\Value\CanonicalStorefrontUrl;

class DetectWebsite implements
    ObserverInterface,
    StorefrontWebsiteCodeResolverInterface,
    StorefrontWebsiteContextResolverInterface,
    StorefrontScopeInstallerInterface
{
    private const CACHE_TTL = 300;
    private const REQUEST_CACHE_PREFIX = 'websites.detect.';
    private const CACHE_KEY_WEBSITE_ROWS = 'websites.detect.website_rows.v1';
    private const CACHE_KEY_WEBSITE_DOMAINS = 'websites.detect.website_domains.v1';
    private const CACHE_KEY_EXPANDED_SITES = 'websites.detect.expanded_sites.v1';
    private const CACHE_KEY_MATCHED_SITE_PREFIX = 'websites.detect.matched_site.';
    private const PROCESS_VALUE_CACHE_MAX_ENTRIES = 256;
    private const MATCH_META_KEY = '__weline_website_match';

    private ?CachePoolInterface $cache = null;

    /**
     * Process-local website cache for WLS/shared observers.
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    private static array $processArrayCache = [];

    /**
     * @var array<string, int>
     */
    private static array $processArrayCacheExpiresAt = [];

    /**
     * Process-local scalar/mixed cache for request match results.
     *
     * @var array<string, mixed>
     */
    private static array $processValueCache = [];

    /**
     * @var array<string, int>
     */
    private static array $processValueCacheExpiresAt = [];

    private static string $processCacheVersion = '';

    public function execute(Event &$event): void
    {
        /** @var Website $websiteModel */
        $websiteModel = w_obj(Website::class);

        if ($event->getData('get_sites')) {
            $event->setData('sites', $this->getExpandedSites($websiteModel));
            return;
        }

        $requestUrl = (string)($event->getData('url') ?? '');
        if ($requestUrl === '') {
            return;
        }

        try {
            $matchedSite = $this->resolveMatchedSite($requestUrl, $websiteModel);
        } catch (ScopeResolutionException $exception) {
            ObjectManager::getInstance(\Weline\Framework\Http\Response::class)
                ->noRouter($exception->httpStatus, $exception->getMessage());
        }
        if ($matchedSite === null) {
            $banUnmatchedDomain = Env::module_env('Weline_Websites', 'ban_unmatched_domain') ?? false;
            if ($banUnmatchedDomain) {
                $response = ObjectManager::getInstance(\Weline\Framework\Http\Response::class);
                $response->noRouter(404, 'Website Not Found');
            }
            return;
        }

        /** @var Website $site */
        $site = $websiteModel->reset();
        $site->setData($matchedSite);
        $this->processSite($event, $site);
    }

    public static function clearProcessCache(): void
    {
        self::$processArrayCache = [];
        self::$processArrayCacheExpiresAt = [];
        self::$processValueCache = [];
        self::$processValueCacheExpiresAt = [];
        self::$processCacheVersion = '';
    }

    public function resolveWebsiteCode(string $fullUri): ?string
    {
        return $this->resolveWebsiteContext($fullUri)?->code;
    }

    public function resolveWebsiteContext(string $fullUri): ?StorefrontWebsiteContext
    {
        /** @var Website $websiteModel */
        $websiteModel = w_obj(Website::class);
        $matchedSite = $this->resolveMatchedSite($fullUri, $websiteModel);
        if ($matchedSite === null) {
            return null;
        }

        return $this->websiteContextFromData($matchedSite);
    }

    public function installNavigationScope(string $fullUri): StorefrontNavigationScope
    {
        /** @var Website $websiteModel */
        $websiteModel = w_obj(Website::class);
        $matchedSite = $this->resolveMatchedSite($fullUri, $websiteModel);
        if ($matchedSite === null) {
            ObjectManager::getInstance(\Weline\Framework\Http\Response::class)
                ->noRouter(404, (string)__('当前请求没有匹配到可用网站'));
        }

        /** @var Website $site */
        $site = $websiteModel->reset();
        $site->setData($matchedSite);
        $event = new Event(['data' => new DataObject(['url' => $fullUri])]);
        $this->processSite($event, $site);

        $identity = RequestContext::scopeIdentity();
        if (!$identity instanceof ScopeIdentity) {
            ObjectManager::getInstance(\Weline\Framework\Http\Response::class)
                ->noRouter(503, (string)__('网站请求未能安装完整商城范围'));
        }

        $routePath = RequestContext::getStorefrontRoutePath();
        if ($routePath === null) {
            ObjectManager::getInstance(\Weline\Framework\Http\Response::class)
                ->noRouter(503, (string)__('网站请求未能产生规范路由剩余路径'));
        }

        return new StorefrontNavigationScope($identity, $routePath);
    }

    public function processSite(Event &$event, Website $site): void
    {
        try {
            $data = $event->getData();
            if (!$data instanceof DataObject) {
                throw new ScopeResolutionException(
                    'website_event_payload_invalid',
                    (string)__('站点解析事件载荷无效'),
                    500,
                );
            }

            $website = $this->websiteContextFromModel($site);
            $data->setData('website_url', $website->url);
            $data->setData('website_id', $website->websiteId);
            $data->setData('code', $website->code);
            $data->setData('default_currency', $website->defaultCurrency);
            $data->setData('default_language', $website->defaultLanguage);
            $data->setData('default_timezone', $website->defaultTimezone);

            $requestUrl = (string)($event->getData('url') ?? '');
            if ($requestUrl === '') {
                throw new ScopeResolutionException(
                    'trusted_request_url_missing',
                    (string)__('缺少可信请求 URL，已拒绝解析商城范围'),
                    400,
                );
            }

            $identity = RequestContext::scopeIdentity();
            if ($identity instanceof ScopeIdentity) {
                if ($identity->scopeKind !== ScopeIdentity::KIND_CHANNEL
                    || $identity->websiteId !== $website->websiteId
                    || !\hash_equals((string)$identity->websiteCode, $website->code)) {
                    throw new ScopeResolutionException(
                        'scope_identity_conflict',
                        (string)__('当前请求的商城范围已冻结，站点身份冲突'),
                        409,
                    );
                }
            } else {
                RequestContext::setWelineWebsiteId($website->websiteId);
                RequestContext::setWelineWebsiteCode($website->code);
                ScopeContext::setWebsiteCode($website->code);
                $params = $this->explicitScopeAssertions($requestUrl);
                $scopeTarget = $this->scopeRequestTargetWithoutLocalization($requestUrl, $website->url);
                /** @var ScopeResolver $scopeResolver */
                $scopeResolver = ObjectManager::getInstance(ScopeResolver::class);
                $navigationScope = $scopeResolver->resolve(
                    $website->websiteId,
                    $website->code,
                    $scopeTarget['url'],
                    $params,
                    $scopeTarget['route_path'],
                );
                $identity = $navigationScope->identity;
            }

            RequestContext::setWelineWebsiteUrl($website->url);
            RequestContext::setWelineTimezone($website->defaultTimezone);
            $scopeMeta = RequestContext::scopeMetadata();
            if ($scopeMeta === null || !$identity->equals(RequestContext::scopeIdentity() ?? ScopeIdentity::global())) {
                throw new ScopeResolutionException(
                    'scope_metadata_inconsistent',
                    (string)__('商城范围元数据与已冻结上下文不一致'),
                    500,
                );
            }
            $data->setData('scope_meta', $scopeMeta);
            $data->setData('scope_route_path', RequestContext::getStorefrontRoutePath());

            WebsiteData::setWebsite($site);
        } catch (ScopeResolutionException $scopeError) {
            if (\function_exists('w_log_error')) {
                \w_log_error('Scope 三段解析拒绝请求', [
                    'reason' => $scopeError->reason,
                    'http_status' => $scopeError->httpStatus,
                ], 'websites');
            }
            ObjectManager::getInstance(\Weline\Framework\Http\Response::class)
                ->noRouter($scopeError->httpStatus, $scopeError->getMessage());
        } catch (\Throwable $scopeError) {
            if (\function_exists('w_log_error')) {
                \w_log_error('Scope 三段解析发生内部错误', [
                    'exception' => $scopeError::class,
                    'message' => $scopeError->getMessage(),
                ], 'websites');
            }
            ObjectManager::getInstance(\Weline\Framework\Http\Response::class)
                ->noRouter(500, (string)__('站点范围解析失败'));
        }
    }

    /** @return array<string, mixed> */
    private function explicitScopeAssertions(string $requestUrl): array
    {
        try {
            $query = (string)(\parse_url($requestUrl, PHP_URL_QUERY) ?? '');
        } catch (\ValueError $exception) {
            throw new ScopeResolutionException(
                'trusted_request_url_invalid',
                (string)__('可信请求 URL 无效'),
                400,
                $exception,
            );
        }
        if ($query === '') {
            return [];
        }

        $parsed = [];
        \parse_str($query, $parsed);
        $assertions = [];
        foreach ([ScopeResolver::PARAM_STORE, ScopeResolver::PARAM_CHANNEL] as $parameter) {
            if (\array_key_exists($parameter, $parsed)) {
                $assertions[$parameter] = $parsed[$parameter];
            }
        }

        return $assertions;
    }

    /** @return array{url: string, route_path: string} */
    private function scopeRequestTargetWithoutLocalization(string $requestUrl, string $websiteUrl): array
    {
        try {
            $request = CanonicalStorefrontUrl::fromRequestUrl($requestUrl);
        } catch (\InvalidArgumentException $exception) {
            throw new ScopeResolutionException(
                'trusted_request_url_invalid',
                (string)__('可信请求 URL 无效'),
                400,
                $exception,
            );
        }
        try {
            $website = CanonicalStorefrontUrl::fromStoreUrl($websiteUrl);
        } catch (\InvalidArgumentException $exception) {
            throw new ScopeResolutionException(
                'website_url_invalid',
                (string)__('网站入口 URL 配置无效'),
                503,
                $exception,
            );
        }
        if (!$website->sameOrigin($request) || !$website->matchesRequestPath($request)) {
            throw new ScopeResolutionException(
                'website_request_conflict',
                (string)__('请求 URL 与已命中的网站入口不一致'),
                409,
            );
        }

        $relativePath = $website->path === '/'
            ? \ltrim($request->path, '/')
            : \ltrim((string)\substr($request->path, \strlen($website->path)), '/');
        $segments = $relativePath === '' ? [] : \explode('/', $relativePath);
        $localization = State::resolveLocalizationFromPathSegments($segments);
        $remaining = \is_array($localization['remaining'] ?? null)
            ? $localization['remaining']
            : $segments;

        $scopePath = $website->path === '/' ? '' : $website->path;
        if ($remaining !== []) {
            $scopePath .= '/' . \implode('/', $remaining);
        }
        $scopePath = CanonicalStorefrontUrl::canonicalPath($scopePath === '' ? '/' : $scopePath);
        $query = (string)(\parse_url($requestUrl, PHP_URL_QUERY) ?? '');

        $routePath = $remaining === [] ? '/' : '/' . \implode('/', $remaining);

        return [
            'url' => $request->originString()
                . ($scopePath === '/' ? '' : $scopePath)
                . ($query === '' ? '' : '?' . $query),
            'route_path' => CanonicalStorefrontUrl::canonicalPath($routePath),
        ];
    }

    private function websiteContextFromModel(Website $site): StorefrontWebsiteContext
    {
        if (!$site->hasData(Website::schema_fields_ID)) {
            throw new ScopeResolutionException(
                'website_id_missing',
                (string)__('站点记录缺少 website_id（0 仅在显式存在时合法）'),
                503,
            );
        }

        $data = $site->getData();
        if (!\is_array($data)) {
            $data = [];
        }
        $data[Website::schema_fields_URL] = (string)($site->getData(Website::schema_fields_URL) ?: $site->getUrl());
        $data[Website::schema_fields_DEFAULT_CURRENCY] = (string)$site->getDefaultCurrency();
        $data[Website::schema_fields_DEFAULT_LANGUAGE] = (string)$site->getDefaultLanguage();
        $data[Website::schema_fields_DEFAULT_TIMEZONE] = (string)$site->getDefaultTimezone();

        return $this->websiteContextFromData($data);
    }

    /** @param array<string, mixed> $data */
    private function websiteContextFromData(array $data): StorefrontWebsiteContext
    {
        if (!\array_key_exists(Website::schema_fields_ID, $data)) {
            throw new ScopeResolutionException(
                'website_id_missing',
                (string)__('站点记录缺少 website_id（0 仅在显式存在时合法）'),
                503,
            );
        }
        $rawWebsiteId = $data[Website::schema_fields_ID];
        if (!\is_int($rawWebsiteId)
            && !(\is_string($rawWebsiteId) && \preg_match('/^(?:0|[1-9][0-9]*)$/D', $rawWebsiteId) === 1)) {
            throw new ScopeResolutionException(
                'website_id_invalid',
                (string)__('站点 website_id 必须是规范非负整数'),
                503,
            );
        }

        try {
            return new StorefrontWebsiteContext(
                (int)$rawWebsiteId,
                \trim((string)($data[Website::schema_fields_CODE] ?? '')),
                \trim((string)($data[Website::schema_fields_NAME] ?? '')),
                \trim((string)($data[Website::schema_fields_URL] ?? '')),
                \trim((string)($data[Website::schema_fields_DEFAULT_CURRENCY] ?? '')),
                \trim((string)($data[Website::schema_fields_DEFAULT_LANGUAGE] ?? '')),
                \trim((string)($data[Website::schema_fields_DEFAULT_TIMEZONE] ?? '')),
            );
        } catch (\InvalidArgumentException $exception) {
            throw new ScopeResolutionException(
                'website_context_invalid',
                (string)__('站点请求上下文不完整或不规范'),
                503,
                $exception,
            );
        }
    }

    private function isHostMatch(string $host1, string $host2): bool
    {
        if ($host1 === $host2) {
            return true;
        }

        $host1WithoutWww = preg_replace('/^www\./', '', $host1);
        $host2WithoutWww = preg_replace('/^www\./', '', $host2);

        return $host1WithoutWww === $host2WithoutWww;
    }

    private function isReservedProjectHost(string $host): bool
    {
        $host = \strtolower(\trim($host));
        return \class_exists(LocalDomainPolicy::class)
            && LocalDomainPolicy::isStandardProjectHost($host);
    }

    private function isReservedProjectHostAlias(string $host): bool
    {
        $host = \strtolower(\trim($host));
        return \str_starts_with($host, 'www.')
            && $this->isReservedProjectHost((string)\substr($host, 4));
    }

    /**
     * @param array<int, array<string, mixed>> $expanded
     * @param array<string, bool> $seen
     * @param array<string, mixed> $site
     */
    private function addExpandedSiteUrls(array &$expanded, array &$seen, array $site, string $baseUrl): void
    {
        if ($baseUrl === '') {
            return;
        }

        $parsed = \parse_url($baseUrl);
        if (!\is_array($parsed)) {
            return;
        }

        $scheme = (($parsed['scheme'] ?? '') === 'http') ? 'http' : 'https';
        $host = \strtolower(\trim((string)($parsed['host'] ?? '')));
        if ($host === '') {
            return;
        }
        if ($this->isReservedProjectHost($host)) {
            return;
        }

        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $path = (string)($parsed['path'] ?? '');

        $hosts = [$host];
        if (!\filter_var($host, FILTER_VALIDATE_IP) && \str_contains($host, '.')) {
            $hosts[] = \str_starts_with($host, 'www.') ? (string)\substr($host, 4) : 'www.' . $host;
        }

        foreach (\array_unique($hosts) as $candidateHost) {
            $url = $scheme . '://' . $candidateHost . $port . $path;
            if (isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;
            $row = $site;
            $row['url'] = $url;
            $expanded[] = $row;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findSiteByWebsiteUrl(string $requestUrl, Website $websiteModel): ?array
    {
        $sites = $this->getWebsiteRows($websiteModel);
        if ($sites === []) {
            return null;
        }

        $matchedSite = null;
        $bestHostExact = false;
        $maxLength = -1;
        $ambiguous = false;
        $parsedRequestUrl = \parse_url($requestUrl);
        $requestScheme = (($parsedRequestUrl['scheme'] ?? '') === 'http') ? 'http' : 'https';
        $requestHost = \strtolower(\trim((string)($parsedRequestUrl['host'] ?? '')));
        if ($this->isReservedProjectHost($requestHost)) {
            return null;
        }
        $requestPort = isset($parsedRequestUrl['port']) ? ':' . $parsedRequestUrl['port'] : '';
        $requestPath = Url::parse_url($requestUrl, 'path') ?: '/';
        $requestPath = '/' . \trim((string)$requestPath, '/');
        if ($requestPath === '//') {
            $requestPath = '/';
        }
        $requestPath = $this->canonicalRequestPath($requestPath);

        foreach ($sites as $siteData) {
            $siteUrl = (string)($siteData['url'] ?? '');
            if ($siteUrl === '') {
                continue;
            }

            $parsedSiteUrl = \parse_url($siteUrl);
            if (!\is_array($parsedSiteUrl)) {
                continue;
            }

            $siteHost = \strtolower(\trim((string)($parsedSiteUrl['host'] ?? '')));
            if ($siteHost === '' || !$this->isHostMatch($requestHost, $siteHost)) {
                continue;
            }

            $sitePath = '/' . \trim((string)($parsedSiteUrl['path'] ?? ''), '/');
            if ($sitePath === '//') {
                $sitePath = '/';
            }
            $sitePath = $this->canonicalConfiguredPath($sitePath);
            if (!CanonicalStorefrontUrl::matchesPathSegmentBoundary($sitePath, $requestPath)) {
                continue;
            }

            $length = \strlen($sitePath);
            $hostExact = $requestHost === $siteHost;
            $isBetter = ($hostExact && !$bestHostExact)
                || ($hostExact === $bestHostExact && $length > $maxLength);
            if ($isBetter) {
                $maxLength = $length;
                $bestHostExact = $hostExact;
                $ambiguous = false;
                $matchedSite = $siteData;
                $matchedSite['url'] = $requestScheme . '://' . $requestHost . $requestPort . ($sitePath === '/' ? '' : $sitePath);
                continue;
            }

            if ($matchedSite !== null
                && $hostExact === $bestHostExact
                && $length === $maxLength
                && !$this->sameWebsiteRecord($matchedSite, $siteData)) {
                $ambiguous = true;
            }
        }

        if ($ambiguous) {
            throw $this->ambiguousWebsiteRoute();
        }
        if ($matchedSite !== null) {
            $matchedSite[self::MATCH_META_KEY] = [
                'host_exact' => $bestHostExact,
                'path' => (string)(\parse_url((string)$matchedSite['url'], PHP_URL_PATH) ?: '/'),
            ];
        }
        return $matchedSite;
    }

    /**
     * @param array<int, array<string, mixed>> $sites
     * @return array<int, array<string, mixed>>
     */
    private function expandSitesWithDomains(array $sites): array
    {
        $expanded = [];
        $seen = [];
        $domainsByWebsite = [];

        foreach ($this->getWebsiteDomainRows() as $domainRow) {
            $websiteId = $this->canonicalWebsiteIdFromRow(
                $domainRow,
                WebsiteDomain::schema_fields_WEBSITE_ID,
                'WebsiteDomain',
            );
            if ($websiteId >= Website::ID_DEFAULT) {
                $domainsByWebsite[$websiteId][] = $domainRow;
            }
        }

        foreach ($sites as $site) {
            $siteUrl = (string)($site['url'] ?? '');
            $this->addExpandedSiteUrls($expanded, $seen, $site, $siteUrl);

            $websiteId = (int)($site['website_id'] ?? 0);
            $domains = $domainsByWebsite[$websiteId] ?? [];
            $parsedSiteUrl = \parse_url($siteUrl);
            $scheme = (($parsedSiteUrl['scheme'] ?? '') === 'http') ? 'http' : 'https';
            $port = isset($parsedSiteUrl['port']) ? ':' . $parsedSiteUrl['port'] : '';

            foreach ($domains as $domainRow) {
                $domain = \trim((string)($domainRow[WebsiteDomain::schema_fields_DOMAIN] ?? ''));
                if ($domain === '') {
                    continue;
                }
                if ($this->isReservedProjectHost($domain)) {
                    continue;
                }

                $subPath = \trim((string)($domainRow[WebsiteDomain::schema_fields_SUB_PATH] ?? ''), '/');
                $baseUrl = $scheme . '://' . $domain . $port;
                if ($subPath !== '') {
                    $baseUrl .= '/' . $subPath;
                }

                $this->addExpandedSiteUrls($expanded, $seen, $site, $baseUrl);
            }
        }

        return $expanded;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findSiteByWebsiteDomain(string $requestUrl, string $currentHost, Website $websiteModel): ?array
    {
        $domainRows = $this->getWebsiteDomainRows();
        if ($domainRows === []) {
            return null;
        }

        $websiteRowsById = $this->getWebsiteRowsById($websiteModel);
        if ($websiteRowsById === []) {
            return null;
        }

        $parsedRequestUrl = \parse_url($requestUrl);
        $requestScheme = (($parsedRequestUrl['scheme'] ?? '') === 'http') ? 'http' : 'https';
        $requestPort = isset($parsedRequestUrl['port']) ? ':' . $parsedRequestUrl['port'] : '';
        $path = Url::parse_url($requestUrl, 'path') ?: '';
        $path = '/' . \trim((string)$path, '/');
        if ($path === '') {
            $path = '/';
        }
        $path = $this->canonicalRequestPath($path);

        $hostNorm = \strtolower(\trim($currentHost));
        if ($this->isReservedProjectHost($hostNorm)) {
            return null;
        }
        $candidates = [];

        foreach ($domainRows as $domainRow) {
            $domain = \strtolower(\trim((string)($domainRow[WebsiteDomain::schema_fields_DOMAIN] ?? '')));
            if ($domain === '' || !$this->isHostMatch($hostNorm, $domain)) {
                continue;
            }

            $subPath = \trim((string)($domainRow[WebsiteDomain::schema_fields_SUB_PATH] ?? ''));
            if ($subPath !== '' && !\str_starts_with($subPath, '/')) {
                $subPath = '/' . $subPath;
            }
            $configuredPath = $this->canonicalConfiguredPath($subPath === '' ? '/' : $subPath);
            if (!CanonicalStorefrontUrl::matchesPathSegmentBoundary($configuredPath, $path)) {
                continue;
            }

            $candidates[] = [
                'sub_path' => $configuredPath,
                'website_id' => $this->canonicalWebsiteIdFromRow(
                    $domainRow,
                    WebsiteDomain::schema_fields_WEBSITE_ID,
                    'WebsiteDomain',
                ),
                'host_exact' => $hostNorm === $domain,
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static function (array $left, array $right): int {
            if (($left['host_exact'] ?? false) !== ($right['host_exact'] ?? false)) {
                return ($right['host_exact'] ?? false) <=> ($left['host_exact'] ?? false);
            }

            return \strlen((string)($right['sub_path'] ?? '')) <=> \strlen((string)($left['sub_path'] ?? ''));
        });

        $chosen = $candidates[0];
        foreach (\array_slice($candidates, 1) as $candidate) {
            if (($candidate['host_exact'] ?? false) !== ($chosen['host_exact'] ?? false)
                || \strlen((string)($candidate['sub_path'] ?? ''))
                    !== \strlen((string)($chosen['sub_path'] ?? ''))) {
                break;
            }
            if ((int)($candidate['website_id'] ?? -1) !== (int)($chosen['website_id'] ?? -1)) {
                throw $this->ambiguousWebsiteRoute();
            }
        }
        $websiteId = (int)($chosen['website_id'] ?? 0);
        if ($websiteId < Website::ID_DEFAULT || !isset($websiteRowsById[$websiteId])) {
            return null;
        }

        $matchedBaseUrl = $requestScheme . '://' . $hostNorm . $requestPort;
        $matchedSubPath = (string)($chosen['sub_path'] ?? '');
        if ($matchedSubPath !== '' && $matchedSubPath !== '/') {
            $matchedBaseUrl .= $matchedSubPath;
        }

        $data = $websiteRowsById[$websiteId];
        $data['url'] = $matchedBaseUrl;
        $data[self::MATCH_META_KEY] = [
            'host_exact' => (bool)($chosen['host_exact'] ?? false),
            'path' => $matchedSubPath === '' ? '/' : $matchedSubPath,
        ];
        return $data;
    }

    private function getCache(): CachePoolInterface
    {
        if ($this->cache === null) {
            $cache = w_cache('website_detect');
            $this->cache = $cache instanceof NamespaceScopedCachePoolInterface
                ? $cache->withNamespace('global/websites-registry')
                : $cache;
        }

        return $this->cache;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveMatchedSite(string $requestUrl, Website $websiteModel): ?array
    {
        $this->syncProcessCacheVersion();
        $matchContext = $this->parseHttpMatchContext($requestUrl);
        if ($matchContext === null) {
            return null;
        }
        if ($this->isReservedProjectHostAlias($matchContext['host'])) {
            return null;
        }

        $cachePath = $this->isReservedProjectHost($matchContext['host'])
            ? '/'
            : $matchContext['path'];
        $cacheIdentity = \implode("\n", [
            $matchContext['scheme'],
            $matchContext['host'],
            (string)$matchContext['port'],
            $cachePath,
        ]);
        $requestKey = self::REQUEST_CACHE_PREFIX . 'match.' . sha1($cacheIdentity);
        if (RequestContext::has($requestKey)) {
            $cached = RequestContext::get($requestKey);
            if (\is_array($cached)) {
                return $cached;
            }
            RequestContext::remove($requestKey);
        }

        $processKey = self::CACHE_KEY_MATCHED_SITE_PREFIX . sha1($cacheIdentity);
        $processCached = $this->getProcessValueCache($processKey);
        if ($processCached !== null || $this->hasProcessValueCache($processKey)) {
            if (\is_array($processCached)) {
                RequestContext::set($requestKey, $processCached);
                return $processCached;
            }
            unset(self::$processValueCache[$processKey], self::$processValueCacheExpiresAt[$processKey]);
        }

        $currentHost = $matchContext['host'];
        if ($this->isReservedProjectHost($currentHost)) {
            $matchedSite = $this->findDefaultSiteForProjectHost($requestUrl, $currentHost, $websiteModel);
        } else {
            $matchedSite = $this->chooseWebsiteCandidate([
                $this->findSiteByWebsiteDomain($requestUrl, $currentHost, $websiteModel),
                $this->findSiteByWebsiteUrl($requestUrl, $websiteModel),
            ]);
        }
        if ($matchedSite === null) {
            $matchedSite = $this->findSiteByWebsiteDomainDirect($requestUrl, $currentHost, $websiteModel);
        }
        if ($matchedSite !== null) {
            unset($matchedSite[self::MATCH_META_KEY]);
        }

        $cachedValue = $matchedSite ?? false;
        RequestContext::set($requestKey, $cachedValue);
        if ($matchedSite !== null) {
            $this->setProcessValueCache($processKey, $matchedSite);
        }

        return $matchedSite;
    }

    /**
     * Query strings and fragments never participate in website selection. The
     * normalized identity keeps hot-path lookups reusable while rejecting
     * non-HTTP schemes before they can be rewritten as HTTPS accidentally.
     *
     * @return array{scheme: string, host: string, port: int, path: string}|null
     */
    private function parseHttpMatchContext(string $requestUrl): ?array
    {
        $parsed = \parse_url(\trim($requestUrl));
        if (!\is_array($parsed)) {
            return null;
        }

        $scheme = \strtolower(\trim((string)($parsed['scheme'] ?? '')));
        if (!\in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = \strtolower(\trim((string)($parsed['host'] ?? '')));
        if ($host === '') {
            return null;
        }

        $path = (string)($parsed['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }

        return [
            'scheme' => $scheme,
            'host' => $host,
            'port' => isset($parsed['port']) ? (int)$parsed['port'] : 0,
            'path' => $path,
        ];
    }

    /**
     * Standard p<8hex> project hosts are the local entry for the system default
     * website. Keep arbitrary unmatched hosts unbound; only the reserved project
     * host pattern may resolve to website_id=0/code=default.
     *
     * @return array<string, mixed>|null
     */
    private function findDefaultSiteForProjectHost(
        string $requestUrl,
        string $currentHost,
        Website $websiteModel,
    ): ?array {
        $matchContext = $this->parseHttpMatchContext($requestUrl);
        if ($matchContext === null || !$this->isReservedProjectHost($currentHost)) {
            return null;
        }

        foreach ($this->getWebsiteRows($websiteModel) as $site) {
            if (!\array_key_exists(Website::schema_fields_ID, $site)) {
                continue;
            }
            if ((int)$site[Website::schema_fields_ID] !== Website::ID_DEFAULT) {
                continue;
            }
            if ((string)($site[Website::schema_fields_CODE] ?? '') !== Website::CODE_DEFAULT) {
                continue;
            }

            $host = LocalDomainPolicy::normalizeDomain($currentHost);
            if ($host === '') {
                return null;
            }
            $port = $matchContext['port'] > 0 ? ':' . $matchContext['port'] : '';
            $site[Website::schema_fields_URL] = $matchContext['scheme'] . '://' . $host . $port;
            return $site;
        }

        return null;
    }

    /**
     * Direct DB-backed fallback for freshly-bound domains under persistent workers.
     *
     * @return array<string, mixed>|null
     */
    private function findSiteByWebsiteDomainDirect(string $requestUrl, string $currentHost, Website $websiteModel): ?array
    {
        $hostNorm = \strtolower(\trim($currentHost));
        if ($hostNorm === '') {
            return null;
        }
        if ($this->isReservedProjectHost($hostNorm)) {
            return null;
        }

        $candidateHosts = [$hostNorm];
        if (\str_starts_with($hostNorm, 'www.')) {
            $candidateHosts[] = (string)\substr($hostNorm, 4);
        } elseif (\str_contains($hostNorm, '.')) {
            $candidateHosts[] = 'www.' . $hostNorm;
        }

        $path = Url::parse_url($requestUrl, 'path') ?: '';
        $path = '/' . \trim((string)$path, '/');
        if ($path === '//') {
            $path = '/';
        }
        $path = $this->canonicalRequestPath($path);
        $parsedRequestUrl = \parse_url($requestUrl);
        $requestScheme = (($parsedRequestUrl['scheme'] ?? '') === 'http') ? 'http' : 'https';
        $requestPort = isset($parsedRequestUrl['port']) ? ':' . $parsedRequestUrl['port'] : '';

        /** @var WebsiteDomain $baseDomainModel */
        $baseDomainModel = w_obj(WebsiteDomain::class);
        foreach (\array_values(\array_unique($candidateHosts)) as $candidateHost) {
            /** @var WebsiteDomain $domainModel */
            $domainQuery = clone $baseDomainModel;
            $domainQuery->clearData()->clearQuery()
                ->where(WebsiteDomain::schema_fields_DOMAIN, $candidateHost)
                ->where(WebsiteDomain::schema_fields_STATUS, WebsiteDomain::STATUS_ACTIVE)
                ->select()
                ->fetch();
            $domainItems = [];
            foreach ($domainQuery->getItems() ?: [] as $item) {
                if ($item instanceof WebsiteDomain) {
                    $domainItems[] = $item;
                }
            }
            if ($domainItems === []) {
                continue;
            }
            \usort($domainItems, static function (WebsiteDomain $left, WebsiteDomain $right): int {
                $leftPath = \trim((string)$left->getSubPath());
                $rightPath = \trim((string)$right->getSubPath());
                if ($leftPath === '/') {
                    $leftPath = '';
                }
                if ($rightPath === '/') {
                    $rightPath = '';
                }
                $lengthCompare = \strlen($rightPath) <=> \strlen($leftPath);
                return $lengthCompare;
            });

            $matchingDomainItems = [];
            foreach ($domainItems as $domainModel) {
                $subPath = \trim($domainModel->getSubPath());
                if ($subPath !== '' && !\str_starts_with($subPath, '/')) {
                    $subPath = '/' . $subPath;
                }
                $configuredPath = $this->canonicalConfiguredPath($subPath === '' ? '/' : $subPath);
                if (!CanonicalStorefrontUrl::matchesPathSegmentBoundary($configuredPath, $path)) {
                    continue;
                }
                $matchingDomainItems[] = [$domainModel, $configuredPath];
            }
            if ($matchingDomainItems === []) {
                continue;
            }
            \usort(
                $matchingDomainItems,
                static fn(array $left, array $right): int => \strlen((string)$right[1]) <=> \strlen((string)$left[1]),
            );

            /** @var WebsiteDomain $chosenDomain */
            [$chosenDomain, $chosenPath] = $matchingDomainItems[0];
            $chosenRank = \strlen($chosenPath);
            foreach (\array_slice($matchingDomainItems, 1) as [$candidateDomain, $candidatePath]) {
                $candidateRank = \strlen($candidatePath);
                if ($candidateRank !== $chosenRank) {
                    break;
                }
                if ($candidateDomain->getWebsiteId() !== $chosenDomain->getWebsiteId()) {
                    throw $this->ambiguousWebsiteRoute();
                }
            }

            /** @var Website $website */
            $website = clone $websiteModel;
            $website->clearData()->clearQuery()->load($chosenDomain->getWebsiteId());
            if (!$website->hasData(Website::schema_fields_ID)) {
                continue;
            }

            $matchedBaseUrl = $requestScheme . '://' . $hostNorm . $requestPort;
            if ($chosenPath !== '/') {
                $matchedBaseUrl .= $chosenPath;
            }

            $data = $website->getData();
            $data['url'] = $matchedBaseUrl;
            $data[self::MATCH_META_KEY] = [
                'host_exact' => $candidateHost === $hostNorm,
                'path' => $chosenPath,
            ];
            return $data;
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>|null> $candidates
     * @return array<string, mixed>|null
     */
    private function chooseWebsiteCandidate(array $candidates): ?array
    {
        $candidates = \array_values(\array_filter($candidates, static fn(mixed $candidate): bool => \is_array($candidate)));
        if ($candidates === []) {
            return null;
        }

        \usort($candidates, static function (array $left, array $right): int {
            $leftMeta = (array)($left[self::MATCH_META_KEY] ?? []);
            $rightMeta = (array)($right[self::MATCH_META_KEY] ?? []);
            $hostCompare = ((int)($rightMeta['host_exact'] ?? false)) <=> ((int)($leftMeta['host_exact'] ?? false));
            if ($hostCompare !== 0) {
                return $hostCompare;
            }

            return \strlen((string)($rightMeta['path'] ?? '/'))
                <=> \strlen((string)($leftMeta['path'] ?? '/'));
        });

        $chosen = $candidates[0];
        $chosenMeta = (array)($chosen[self::MATCH_META_KEY] ?? []);
        $chosenRank = [
            (bool)($chosenMeta['host_exact'] ?? false),
            \strlen((string)($chosenMeta['path'] ?? '/')),
        ];
        foreach (\array_slice($candidates, 1) as $candidate) {
            $candidateMeta = (array)($candidate[self::MATCH_META_KEY] ?? []);
            $candidateRank = [
                (bool)($candidateMeta['host_exact'] ?? false),
                \strlen((string)($candidateMeta['path'] ?? '/')),
            ];
            if ($candidateRank !== $chosenRank) {
                break;
            }
            if (!$this->sameWebsiteRecord($chosen, $candidate)) {
                throw $this->ambiguousWebsiteRoute();
            }
        }

        return $chosen;
    }

    private function canonicalConfiguredPath(string $path): string
    {
        try {
            return CanonicalStorefrontUrl::canonicalPath($path);
        } catch (\InvalidArgumentException $exception) {
            throw new ScopeResolutionException(
                'website_path_invalid',
                (string)__('网站入口路径配置无效'),
                503,
                $exception,
            );
        }
    }

    private function canonicalRequestPath(string $path): string
    {
        try {
            return CanonicalStorefrontUrl::canonicalPath($path);
        } catch (\InvalidArgumentException $exception) {
            throw new ScopeResolutionException(
                'trusted_request_path_invalid',
                (string)__('可信请求 URL 路径无效'),
                400,
                $exception,
            );
        }
    }

    /** @param array<string, mixed> $row */
    private function canonicalWebsiteIdFromRow(array $row, string $field, string $source): int
    {
        if (!\array_key_exists($field, $row)) {
            throw new ScopeResolutionException(
                'website_reference_missing',
                (string)__('站点或域名记录缺少显式 website_id'),
                503,
            );
        }

        $value = $row[$field];
        if (!\is_int($value)
            && !(\is_string($value) && \preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) === 1)) {
            throw new ScopeResolutionException(
                'website_reference_invalid',
                (string)__('站点或域名记录包含无效 website_id：%{1}', [$source]),
                503,
            );
        }

        return (int)$value;
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function sameWebsiteRecord(array $left, array $right): bool
    {
        if (\array_key_exists(Website::schema_fields_ID, $left)
            && \array_key_exists(Website::schema_fields_ID, $right)) {
            $leftCode = (string)($left[Website::schema_fields_CODE] ?? '');
            $rightCode = (string)($right[Website::schema_fields_CODE] ?? '');
            return (string)$left[Website::schema_fields_ID] === (string)$right[Website::schema_fields_ID]
                && $leftCode !== ''
                && \hash_equals($leftCode, $rightCode);
        }

        $leftCode = (string)($left[Website::schema_fields_CODE] ?? '');
        $rightCode = (string)($right[Website::schema_fields_CODE] ?? '');
        return $leftCode !== '' && \hash_equals($leftCode, $rightCode);
    }

    private function ambiguousWebsiteRoute(): ScopeResolutionException
    {
        return new ScopeResolutionException(
            'website_route_ambiguous',
            (string)__('当前 Host/URI 同优先级匹配到多个网站，已拒绝请求'),
            409,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getExpandedSites(Website $websiteModel): array
    {
        return $this->rememberArray(
            self::REQUEST_CACHE_PREFIX . 'expanded_sites',
            self::CACHE_KEY_EXPANDED_SITES,
            fn(): array => $this->expandSitesWithDomains($this->getWebsiteRows($websiteModel))
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getWebsiteRows(Website $websiteModel): array
    {
        return $this->rememberArray(
            self::REQUEST_CACHE_PREFIX . 'website_rows',
            self::CACHE_KEY_WEBSITE_ROWS,
            function () use ($websiteModel): array {
                try {
                    $rows = [];
                    /** @var Website $query */
                    $query = clone $websiteModel;
                    $query->clearData()->clearQuery()->select()->fetch();
                    foreach ($query->getItems() as $row) {
                        if (\is_object($row) && \method_exists($row, 'getData')) {
                            $row = $row->getData();
                        }
                        if (\is_array($row)) {
                            $rows[] = $row;
                        }
                    }

                    return $rows;
                } catch (\PDOException $e) {
                    $code = $e->getCode();
                    $message = $e->getMessage();
                    if ($code === '42P01' || $code === '42S02' || str_contains($message, 'does not exist')) {
                        return [];
                    }
                    throw $e;
                }
            }
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getWebsiteDomainRows(): array
    {
        return $this->rememberArray(
            self::REQUEST_CACHE_PREFIX . 'website_domains',
            self::CACHE_KEY_WEBSITE_DOMAINS,
            function (): array {
                try {
                    /** @var WebsiteDomain $domainModel */
                    $domainModel = w_obj(WebsiteDomain::class);
                    $rows = [];
                    /** @var WebsiteDomain $query */
                    $query = clone $domainModel;
                    $query->clearData()->clearQuery()
                        ->where(WebsiteDomain::schema_fields_STATUS, WebsiteDomain::STATUS_ACTIVE)
                        ->select()
                        ->fetch();
                    foreach ($query->getItems() as $row) {
                        if (\is_object($row) && \method_exists($row, 'getData')) {
                            $row = $row->getData();
                        }
                        if (\is_array($row)) {
                            $rows[] = $row;
                        }
                    }

                    return $rows;
                } catch (\Throwable $e) {
                    return [];
                }
            }
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rememberArray(string $requestKey, string $cacheKey, callable $loader): array
    {
        $this->syncProcessCacheVersion();
        if (RequestContext::has($requestKey)) {
            $cached = RequestContext::get($requestKey);
            return \is_array($cached) ? $cached : [];
        }

        $processCached = $this->getProcessArrayCache($cacheKey);
        if ($processCached !== null) {
            RequestContext::set($requestKey, $processCached);
            return $processCached;
        }

        $cached = $this->getCache()->get($cacheKey);
        if (\is_array($cached)) {
            $this->setProcessArrayCache($cacheKey, $cached);
            RequestContext::set($requestKey, $cached);
            return $cached;
        }

        $value = $loader();
        if (!\is_array($value)) {
            $value = [];
        }

        RequestContext::set($requestKey, $value);
        $this->setProcessArrayCache($cacheKey, $value);
        $this->getCache()->set($cacheKey, $value, self::CACHE_TTL);

        return $value;
    }

    private function syncProcessCacheVersion(): void
    {
        $version = Url::websiteParserSitesVersion();
        if ($version === '') {
            return;
        }
        if (self::$processCacheVersion !== '' && !\hash_equals(self::$processCacheVersion, $version)) {
            self::clearProcessCache();
        }
        self::$processCacheVersion = $version;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function getProcessArrayCache(string $cacheKey): ?array
    {
        $expiresAt = self::$processArrayCacheExpiresAt[$cacheKey] ?? 0;
        if ($expiresAt < \time()) {
            unset(self::$processArrayCache[$cacheKey], self::$processArrayCacheExpiresAt[$cacheKey]);
            return null;
        }

        $cached = self::$processArrayCache[$cacheKey] ?? null;
        return \is_array($cached) ? $cached : null;
    }

    /**
     * @param array<int, array<string, mixed>> $value
     */
    private function setProcessArrayCache(string $cacheKey, array $value): void
    {
        self::$processArrayCache[$cacheKey] = $value;
        self::$processArrayCacheExpiresAt[$cacheKey] = \time() + self::CACHE_TTL;
    }

    private function hasProcessValueCache(string $cacheKey): bool
    {
        $expiresAt = self::$processValueCacheExpiresAt[$cacheKey] ?? 0;
        if ($expiresAt < \time()) {
            unset(self::$processValueCache[$cacheKey], self::$processValueCacheExpiresAt[$cacheKey]);
            return false;
        }

        return \array_key_exists($cacheKey, self::$processValueCache);
    }

    private function getProcessValueCache(string $cacheKey): mixed
    {
        if (!$this->hasProcessValueCache($cacheKey)) {
            return null;
        }

        return self::$processValueCache[$cacheKey];
    }

    private function setProcessValueCache(string $cacheKey, mixed $value): void
    {
        if (!\array_key_exists($cacheKey, self::$processValueCache)) {
            while (\count(self::$processValueCache) >= self::PROCESS_VALUE_CACHE_MAX_ENTRIES) {
                $oldestKey = \array_key_first(self::$processValueCache);
                if ($oldestKey === null) {
                    break;
                }
                unset(self::$processValueCache[$oldestKey], self::$processValueCacheExpiresAt[$oldestKey]);
            }
        }

        self::$processValueCache[$cacheKey] = $value;
        self::$processValueCacheExpiresAt[$cacheKey] = \time() + self::CACHE_TTL;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getWebsiteRowsById(Website $websiteModel): array
    {
        $requestKey = self::REQUEST_CACHE_PREFIX . 'website_rows_by_id';
        if (RequestContext::has($requestKey)) {
            $cached = RequestContext::get($requestKey);
            return \is_array($cached) ? $cached : [];
        }

        $rowsById = [];
        foreach ($this->getWebsiteRows($websiteModel) as $row) {
            $websiteId = $this->canonicalWebsiteIdFromRow(
                $row,
                Website::schema_fields_ID,
                'Website',
            );
            if ($websiteId >= Website::ID_DEFAULT) {
                $rowsById[$websiteId] = $row;
            }
        }

        RequestContext::set($requestKey, $rowsById);
        return $rowsById;
    }
}
