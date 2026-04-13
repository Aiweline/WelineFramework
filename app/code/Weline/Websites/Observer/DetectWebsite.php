<?php

namespace Weline\Websites\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Websites\Data\WebsiteData;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteDomain;

class DetectWebsite implements ObserverInterface
{
    private const CACHE_TTL = 300;
    private const REQUEST_CACHE_PREFIX = 'websites.detect.';
    private const CACHE_KEY_WEBSITE_ROWS = 'websites.detect.website_rows.v1';
    private const CACHE_KEY_WEBSITE_DOMAINS = 'websites.detect.website_domains.v1';
    private const CACHE_KEY_EXPANDED_SITES = 'websites.detect.expanded_sites.v1';
    private const CACHE_KEY_MATCHED_SITE_PREFIX = 'websites.detect.matched_site.';

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

        $matchedSite = $this->resolveMatchedSite($requestUrl, $websiteModel);
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
    }

    public function processSite(Event &$event, Website $site): void
    {
        /** @var DataObject $data */
        $data = $event->getData();
        $websiteUrl = $site->getData('url') ?: $site->getUrl();
        $data->setData('website_url', $websiteUrl);
        $data->setData('website_id', $site->getWebsiteId());
        $data->setData('code', $site->getCode());
        $data->setData('default_currency', $site->getDefaultCurrency());
        $data->setData('default_language', $site->getDefaultLanguage());
        $data->setData('default_timezone', $site->getDefaultTimezone());

        date_default_timezone_set($site->getDefaultTimezone());
        WebsiteData::setWebsite($site);
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
        $maxLength = 0;
        $parsedRequestUrl = \parse_url($requestUrl);
        $requestScheme = (($parsedRequestUrl['scheme'] ?? '') === 'http') ? 'http' : 'https';
        $requestHost = \strtolower(\trim((string)($parsedRequestUrl['host'] ?? '')));
        $requestPort = isset($parsedRequestUrl['port']) ? ':' . $parsedRequestUrl['port'] : '';
        $requestPath = Url::parse_url($requestUrl, 'path') ?: '/';
        $requestPath = '/' . \trim((string)$requestPath, '/');
        if ($requestPath === '//') {
            $requestPath = '/';
        }

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
            if ($sitePath !== '/' && !\str_starts_with($requestPath, $sitePath)) {
                continue;
            }

            $length = \strlen($sitePath);
            if ($length > $maxLength) {
                $maxLength = $length;
                $matchedSite = $siteData;
                $matchedSite['url'] = $requestScheme . '://' . $requestHost . $requestPort . ($sitePath === '/' ? '' : $sitePath);
            }
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
            $websiteId = (int)($domainRow[WebsiteDomain::schema_fields_WEBSITE_ID] ?? 0);
            if ($websiteId > 0) {
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

        $hostNorm = \strtolower(\trim($currentHost));
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
            if ($subPath !== '' && $subPath !== '/' && !\str_starts_with($path, $subPath) && $path !== $subPath) {
                continue;
            }

            $candidates[] = [
                'sub_path' => $subPath,
                'website_id' => (int)($domainRow[WebsiteDomain::schema_fields_WEBSITE_ID] ?? 0),
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
        $websiteId = (int)($chosen['website_id'] ?? 0);
        if ($websiteId <= 0 || !isset($websiteRowsById[$websiteId])) {
            return null;
        }

        $matchedBaseUrl = $requestScheme . '://' . $hostNorm . $requestPort;
        $matchedSubPath = (string)($chosen['sub_path'] ?? '');
        if ($matchedSubPath !== '' && $matchedSubPath !== '/') {
            $matchedBaseUrl .= $matchedSubPath;
        }

        $data = $websiteRowsById[$websiteId];
        $data['url'] = $matchedBaseUrl;
        return $data;
    }

    private function getCache(): CachePoolInterface
    {
        if ($this->cache === null) {
            $this->cache = w_cache('website_detect');
        }

        return $this->cache;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveMatchedSite(string $requestUrl, Website $websiteModel): ?array
    {
        $requestKey = self::REQUEST_CACHE_PREFIX . 'match.' . sha1($requestUrl);
        if (RequestContext::has($requestKey)) {
            $cached = RequestContext::get($requestKey);
            if (\is_array($cached)) {
                return $cached;
            }
            RequestContext::remove($requestKey);
        }

        $processKey = self::CACHE_KEY_MATCHED_SITE_PREFIX . sha1($requestUrl);
        $processCached = $this->getProcessValueCache($processKey);
        if ($processCached !== null || $this->hasProcessValueCache($processKey)) {
            if (\is_array($processCached)) {
                RequestContext::set($requestKey, $processCached);
                return $processCached;
            }
            unset(self::$processValueCache[$processKey], self::$processValueCacheExpiresAt[$processKey]);
        }

        $matchedSite = null;
        $currentHost = \parse_url($requestUrl, PHP_URL_HOST);
        if (\is_string($currentHost) && $currentHost !== '') {
            $matchedSite = $this->findSiteByWebsiteDomain($requestUrl, $currentHost, $websiteModel);
            if ($matchedSite === null) {
                $this->invalidateDetectionCachesForRetry($requestUrl);
                $matchedSite = $this->findSiteByWebsiteDomain($requestUrl, $currentHost, $websiteModel);
            }
            if ($matchedSite === null) {
                $matchedSite = $this->findSiteByWebsiteDomainDirect($requestUrl, $currentHost, $websiteModel);
            }
        }
        if ($matchedSite === null) {
            $matchedSite = $this->findSiteByWebsiteUrl($requestUrl, $websiteModel);
        }

        $cachedValue = $matchedSite ?? false;
        RequestContext::set($requestKey, $cachedValue);
        $this->setProcessValueCache($processKey, $cachedValue);

        return $matchedSite;
    }

    private function invalidateDetectionCachesForRetry(string $requestUrl): void
    {
        foreach ([
            self::REQUEST_CACHE_PREFIX . 'website_rows',
            self::REQUEST_CACHE_PREFIX . 'website_domains',
            self::REQUEST_CACHE_PREFIX . 'expanded_sites',
            self::REQUEST_CACHE_PREFIX . 'website_rows_by_id',
            self::REQUEST_CACHE_PREFIX . 'match.' . sha1($requestUrl),
        ] as $requestKey) {
            RequestContext::remove($requestKey);
        }

        self::clearProcessCache();

        try {
            $this->getCache()->clear();
        } catch (\Throwable) {
        }
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
        $parsedRequestUrl = \parse_url($requestUrl);
        $requestScheme = (($parsedRequestUrl['scheme'] ?? '') === 'http') ? 'http' : 'https';
        $requestPort = isset($parsedRequestUrl['port']) ? ':' . $parsedRequestUrl['port'] : '';

        /** @var WebsiteDomain $baseDomainModel */
        $baseDomainModel = w_obj(WebsiteDomain::class);
        foreach (\array_values(\array_unique($candidateHosts)) as $candidateHost) {
            /** @var WebsiteDomain $domainModel */
            $domainModel = clone $baseDomainModel;
            $domainModel->clearData()->clearQuery()->loadByDomain($candidateHost);
            if ($domainModel->getDomainId() <= 0 || $domainModel->getStatus() !== WebsiteDomain::STATUS_ACTIVE) {
                continue;
            }

            $subPath = \trim($domainModel->getSubPath());
            if ($subPath !== '' && !\str_starts_with($subPath, '/')) {
                $subPath = '/' . $subPath;
            }
            if ($subPath !== '' && $subPath !== '/' && !\str_starts_with($path, $subPath) && $path !== $subPath) {
                continue;
            }

            /** @var Website $website */
            $website = clone $websiteModel;
            $website->clearData()->clearQuery()->load($domainModel->getWebsiteId());
            if ($website->getWebsiteId() <= 0) {
                continue;
            }

            $matchedBaseUrl = $requestScheme . '://' . $hostNorm . $requestPort;
            if ($subPath !== '' && $subPath !== '/') {
                $matchedBaseUrl .= $subPath;
            }

            $data = $website->getData();
            $data['url'] = $matchedBaseUrl;
            return $data;
        }

        return null;
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
                    return $websiteModel->reset()->clearQuery()->select()->fetchArray();
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
                    return $domainModel->clearQuery()
                        ->where(WebsiteDomain::schema_fields_STATUS, WebsiteDomain::STATUS_ACTIVE)
                        ->select()
                        ->fetchArray();
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
            $websiteId = (int)($row['website_id'] ?? 0);
            if ($websiteId > 0) {
                $rowsById[$websiteId] = $row;
            }
        }

        RequestContext::set($requestKey, $rowsById);
        return $rowsById;
    }
}
