<?php

namespace Weline\Websites\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Data\WebsiteData;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteDomain;

class DetectWebsite implements ObserverInterface
{

    /**
     * 网站表尚未创建时（如未执行 setup:upgrade）直接返回，避免 42P01 导致请求崩溃
     */
    private function ensureWebsiteTableExists(Website $websiteModel): bool
    {
        try {
            $websiteModel->clearQuery()->limit(1)->select()->fetchArray();
            return true;
        } catch (\PDOException $e) {
            $code = $e->getCode();
            $msg = $e->getMessage();
            // PostgreSQL 42P01 / MySQL 42S02 表不存在
            if ($code === '42P01' || $code === '42S02' || str_contains($msg, 'does not exist')) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        /** @var Website $website_model */
        $website_model = w_obj(Website::class);
        if (!$this->ensureWebsiteTableExists($website_model)) {
            $event->setData('sites', []);
            return;
        }
        
        $get_sites = $event->getData('get_sites');
        if ($get_sites) {
            $event->setData('sites', $this->expandSitesWithDomains($website_model->select()->fetchArray()));
            return;
        }

        $url1 = (string) ($event->getData('url') ?? '');
        /** @var Website $site */
        $site = $website_model->reset();
        $currentHost = \parse_url($url1, PHP_URL_HOST);
        if (\is_string($currentHost) && $currentHost !== '') {
            $matchedSite = $this->findSiteByWebsiteDomain($url1, $currentHost, $website_model);
            if ($matchedSite !== null) {
                $site->setData($matchedSite);
            }
        }

        // 兼容旧数据：若未配置 website_domain，则退回到主表 url 最长匹配。
        if (!$site->getId()) {
            $matchedSite = $this->findSiteByWebsiteUrl($url1, $website_model);
            if ($matchedSite !== null) {
                $site->setData($matchedSite);
            }
        }

        // 如果查不到站点，检查是否禁止未匹配的域名访问
        if (!$site->getId()) {
            // 检查配置：是否禁止未匹配的域名访问
            $banUnmatchedDomain = Env::module_env('Weline_Websites', 'ban_unmatched_domain') ?? false;
            
            if ($banUnmatchedDomain) {
                // 如果配置了禁止未匹配的域名，返回404
                $response = ObjectManager::getInstance(\Weline\Framework\Http\Response::class);
                $response->noRouter(404, 'Website Not Found');
                return;
            }
            
            // 默认情况下，查不到站点也没关系，直接返回
            return;
        }
        
        /** @var DataObject $data */
        $this->processSite($event, $site);
    }

    /**
     * @param Event $event
     * @param Website $site
     * @return void
     */
    public function processSite(Event &$event, Website $site): void
    {
        /** @var DataObject $data */
        $data = $event->getData();
        // getData('url') 可能已被 findSiteByWebsiteDomain/findSiteByWebsiteUrl 改写为
        // 带当前请求端口的值，优先使用它；否则回退 getUrl()（数据库原始值）
        $websiteUrl = $site->getData('url') ?: $site->getUrl();
        $data->setData('website_url', $websiteUrl);
        $data->setData('website_id', $site->getWebsiteId());
        $data->setData('code', $site->getCode());
        $data->setData('default_currency', $site->getDefaultCurrency());
        $data->setData('default_language', $site->getDefaultLanguage());
        $data->setData('default_timezone', $site->getDefaultTimezone());
        
        # 设置默认时区
        date_default_timezone_set($site->getDefaultTimezone());
        
        # 设置静态网站数据类，供其他模块使用
        WebsiteData::setWebsite($site);
    }

    /**
     * 检查两个主机名是否匹配（处理 www 和非 www 的情况）
     * 
     * @param string $host1
     * @param string $host2
     * @return bool
     */
    private function isHostMatch(string $host1, string $host2): bool
    {
        if ($host1 === $host2) {
            return true;
        }
        
        // 处理 www 和非 www 的情况
        $host1WithoutWww = preg_replace('/^www\./', '', $host1);
        $host2WithoutWww = preg_replace('/^www\./', '', $host2);
        
        return $host1WithoutWww === $host2WithoutWww;
    }

    /**
     * 为站点候选 URL 增加 www/裸域兼容别名。
     *
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
        $host = \strtolower(\trim((string) ($parsed['host'] ?? '')));
        if ($host === '') {
            return;
        }
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $path = (string) ($parsed['path'] ?? '');

        $hosts = [$host];
        if (!\filter_var($host, FILTER_VALIDATE_IP) && \str_contains($host, '.')) {
            if (\str_starts_with($host, 'www.')) {
                $hosts[] = (string) \substr($host, 4);
            } else {
                $hosts[] = 'www.' . $host;
            }
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
     * 兼容旧站点数据：按 website.url 做最长匹配。
     *
     * @return array<string, mixed>|null
     */
    private function findSiteByWebsiteUrl(string $requestUrl, Website $websiteModel): ?array
    {
        $sites = $websiteModel->reset()->select()->fetchArray();
        $matchedSite = null;
        $maxLength = 0;
        $parsedRequestUrl = \parse_url($requestUrl);
        $requestScheme = (($parsedRequestUrl['scheme'] ?? '') === 'http') ? 'http' : 'https';
        $requestHost = \strtolower(\trim((string) ($parsedRequestUrl['host'] ?? '')));
        $requestPort = isset($parsedRequestUrl['port']) ? ':' . $parsedRequestUrl['port'] : '';
        $requestPath = Url::parse_url($requestUrl, 'path') ?? '/';
        $requestPath = '/' . \trim((string) $requestPath, '/');
        if ($requestPath === '//') {
            $requestPath = '/';
        }
        foreach ($sites as $siteData) {
            $siteUrl = (string) ($siteData['url'] ?? '');
            if ($siteUrl === '') {
                continue;
            }
            $parsedSiteUrl = \parse_url($siteUrl);
            if (!\is_array($parsedSiteUrl)) {
                continue;
            }
            $siteHost = \strtolower(\trim((string) ($parsedSiteUrl['host'] ?? '')));
            if ($siteHost === '' || !$this->isHostMatch($requestHost, $siteHost)) {
                continue;
            }
            $sitePath = (string) ($parsedSiteUrl['path'] ?? '');
            $sitePath = '/' . \trim($sitePath, '/');
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
     * 多域名支持：为每个站点的主 URL 及 website_domain 中绑定的每个域名生成一条 base URL 记录。
     * 供 Framework Url 解析与本站点探测做最长匹配，使不同域名能正确归属到对应站点。
     *
     * @param array<int, array<string, mixed>> $sites 来自 Website 表的站点列表（每项含 url, website_id 等）
     * @return array<int, array<string, mixed>> 展开后的站点列表（同一站点可能有多条，url 不同）
     */
    private function expandSitesWithDomains(array $sites): array
    {
        $expanded = [];
        $seen = [];
        try {
            /** @var WebsiteDomain $domainModel */
            $domainModel = w_obj(WebsiteDomain::class);
            $domainModel->clearQuery()->limit(1)->select()->fetchArray();
        } catch (\Throwable $e) {
            return $sites;
        }
        /** @var WebsiteDomain $domainModel */
        $domainModel = w_obj(WebsiteDomain::class);
        $domains = $domainModel->clearQuery()
            ->where(WebsiteDomain::schema_fields_STATUS, WebsiteDomain::STATUS_ACTIVE)
            ->select()
            ->fetchArray();
        $domainsByWebsite = [];
        foreach ($domains as $d) {
            $wid = (int) ($d[WebsiteDomain::schema_fields_WEBSITE_ID] ?? 0);
            if ($wid > 0) {
                $domainsByWebsite[$wid][] = $d;
            }
        }
        foreach ($sites as $site) {
            $siteUrl = (string) ($site['url'] ?? '');
            $this->addExpandedSiteUrls($expanded, $seen, $site, $siteUrl);
            $websiteId = (int) ($site['website_id'] ?? 0);
            $list = $domainsByWebsite[$websiteId] ?? [];
            $parsedSiteUrl = \parse_url((string) $siteUrl);
            $scheme = (($parsedSiteUrl['scheme'] ?? '') === 'http') ? 'http' : 'https';
            $port = isset($parsedSiteUrl['port']) ? ':' . $parsedSiteUrl['port'] : '';
            foreach ($list as $d) {
                $domain = $d[WebsiteDomain::schema_fields_DOMAIN] ?? '';
                $subPath = $d[WebsiteDomain::schema_fields_SUB_PATH] ?? '';
                $domain = trim((string) $domain);
                if ($domain === '') {
                    continue;
                }
                $base = $scheme . '://' . $domain . $port;
                if ($subPath !== '' && $subPath !== null) {
                    $base .= '/' . trim((string) $subPath, '/');
                }
                $this->addExpandedSiteUrls($expanded, $seen, $site, $base);
            }
        }
        return $expanded;
    }

    /**
     * 根据请求 URL 的 host（及路径）在 website_domain 表中查找站点。
     * 用于多域名场景：请求来自绑定域名而非主表 url 时仍能命中正确站点。
     *
     * @param string $requestUrl 当前请求完整 URL
     * @param string $currentHost 已解析的 host
     * @param Website $websiteModel 用于按 website_id 加载的模型
     * @return array<string, mixed>|null 匹配到的站点数据，未命中返回 null
     */
    private function findSiteByWebsiteDomain(string $requestUrl, string $currentHost, Website $websiteModel): ?array
    {
        $parsedRequestUrl = \parse_url($requestUrl);
        $requestScheme = (($parsedRequestUrl['scheme'] ?? '') === 'http') ? 'http' : 'https';
        $requestPort = isset($parsedRequestUrl['port']) ? ':' . $parsedRequestUrl['port'] : '';
        $path = Url::parse_url($requestUrl, 'path') ?? '';
        $path = '/' . trim($path, '/');
        if ($path === '') {
            $path = '/';
        }
        try {
            /** @var WebsiteDomain $domainModel */
            $domainModel = w_obj(WebsiteDomain::class);
            $domainModel->clearQuery()->limit(1)->select()->fetchArray();
        } catch (\Throwable $e) {
            return null;
        }
        /** @var WebsiteDomain $domainModel */
        $domainModel = w_obj(WebsiteDomain::class);
        $rows = $domainModel->clearQuery()
            ->where(WebsiteDomain::schema_fields_STATUS, WebsiteDomain::STATUS_ACTIVE)
            ->select()
            ->fetchArray();
        $candidates = [];
        $hostNorm = strtolower(trim($currentHost));
        foreach ($rows as $r) {
            $d = $r[WebsiteDomain::schema_fields_DOMAIN] ?? '';
            $d = strtolower(trim((string) $d));
            if ($d === '') {
                continue;
            }
            if (!$this->isHostMatch($hostNorm, $d)) {
                continue;
            }
            $subPath = $r[WebsiteDomain::schema_fields_SUB_PATH] ?? '';
            $subPath = trim((string) $subPath);
            if ($subPath !== '' && !str_starts_with($subPath, '/')) {
                $subPath = '/' . $subPath;
            }
            if ($subPath !== '' && $subPath !== '/') {
                if (!str_starts_with($path, $subPath) && $path !== $subPath) {
                    continue;
                }
            }
            $candidates[] = [
                'sub_path' => $subPath,
                'website_id' => (int) ($r[WebsiteDomain::schema_fields_WEBSITE_ID] ?? 0),
                'host_exact' => $hostNorm === $d,
                'row' => $r
            ];
        }
        if ($candidates === []) {
            return null;
        }
        usort($candidates, function ($a, $b) {
            if (($a['host_exact'] ?? false) !== ($b['host_exact'] ?? false)) {
                return ($b['host_exact'] ?? false) <=> ($a['host_exact'] ?? false);
            }
            return strlen($b['sub_path']) <=> strlen($a['sub_path']);
        });
        $chosen = $candidates[0];
        $websiteId = $chosen['website_id'];
        if ($websiteId <= 0) {
            return null;
        }
        $websiteModel->reset()->load($websiteId);
        if (!$websiteModel->getWebsiteId()) {
            return null;
        }
        $matchedBaseUrl = $requestScheme . '://' . $hostNorm . $requestPort;
        $matchedSubPath = (string) ($chosen['sub_path'] ?? '');
        if ($matchedSubPath !== '' && $matchedSubPath !== '/') {
            $matchedBaseUrl .= $matchedSubPath;
        }
        $data = $websiteModel->getData();
        $data['url'] = $matchedBaseUrl;
        return $data;
    }
}