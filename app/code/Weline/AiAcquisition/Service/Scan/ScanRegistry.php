<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Service\Scan;

use Weline\AiAcquisition\Model\Lead;
use Weline\AiAcquisition\Model\Scan;

/**
 * 已扫描页面台账：把「看过的页面」沉淀到站点级，让后续运行跳过，避免重复检索与重复抓取。
 *
 * 归一化口径（必须与扩展 scan-dedupe.js 保持一致）：
 * - 只认 http/https；
 * - 域名小写并去 www.；
 * - 去掉查询串与锚点（同页不同参数视为同一页）；
 * - 路径去尾部斜杠，空路径记 /。
 */
final class ScanRegistry
{
    public const SEEN_URL_LIMIT = 400;
    public const SEEN_DOMAIN_LIMIT = 200;

    public function __construct(
        private readonly Scan $scanModel,
    ) {
    }

    /**
     * 归一化地址：host + path。非法地址返回空串。
     */
    public static function normalize(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return '';
        }
        $host = strtolower((string)$parts['host']);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        $path = rtrim((string)($parts['path'] ?? '/'), '/');
        if ($path === '') {
            $path = '/';
        }

        return $host . $path;
    }

    public static function domain(string $url): string
    {
        $normalized = self::normalize($url);
        if ($normalized === '') {
            return '';
        }
        $slash = strpos($normalized, '/');

        return $slash === false ? $normalized : substr($normalized, 0, $slash);
    }

    public static function hash(string $url): string
    {
        $normalized = self::normalize($url);

        return $normalized === '' ? '' : hash('sha256', $normalized);
    }

    /**
     * 纯函数过滤：给定已看清单，返回可继续与应跳过两组。
     *
     * @param list<string> $urls
     * @param array<string,true>|list<string> $seenUrls 归一化地址或原始地址均可
     * @param array<string,true>|list<string> $seenDomains
     * @return array{allowed:list<string>,skipped:list<string>}
     */
    public static function filterBySeen(array $urls, array $seenUrls, array $seenDomains = []): array
    {
        $urlSet = [];
        foreach ($seenUrls as $key => $value) {
            $candidate = is_string($key) ? $key : (string)$value;
            $normalized = self::normalize($candidate);
            if ($normalized !== '') {
                $urlSet[$normalized] = true;
            }
        }
        $domainSet = [];
        foreach ($seenDomains as $key => $value) {
            $candidate = is_string($key) ? $key : (string)$value;
            $candidate = strtolower(trim($candidate));
            if (str_starts_with($candidate, 'www.')) {
                $candidate = substr($candidate, 4);
            }
            if ($candidate !== '') {
                $domainSet[$candidate] = true;
            }
        }

        $allowed = [];
        $skipped = [];
        foreach ($urls as $url) {
            $url = (string)$url;
            $normalized = self::normalize($url);
            if ($normalized === '') {
                continue;
            }
            $domain = strpos($normalized, '/') === false
                ? $normalized
                : substr($normalized, 0, (int)strpos($normalized, '/'));
            if (isset($urlSet[$normalized]) || self::domainMatches($domain, $domainSet)) {
                $skipped[] = $url;
                continue;
            }
            $allowed[] = $url;
        }

        return ['allowed' => $allowed, 'skipped' => $skipped];
    }

    /**
     * 域名命中：精确匹配或子域（shop.example.com 命中 example.com）。
     *
     * @param array<string,true> $domainSet
     */
    private static function domainMatches(string $domain, array $domainSet): bool
    {
        if ($domain === '') {
            return false;
        }
        if (isset($domainSet[$domain])) {
            return true;
        }
        $parts = explode('.', $domain);
        for ($i = 1, $count = count($parts); $i < $count; $i++) {
            $suffix = implode('.', array_slice($parts, $i));
            if (isset($domainSet[$suffix])) {
                return true;
            }
        }

        return false;
    }

    /**
     * 过滤候选地址：命中台账的直接跳过。
     *
     * @param list<string> $urls
     * @return array{allowed:list<string>,skipped:list<string>}
     */
    public function filterUnseen(int $websiteId, array $urls): array
    {
        $urls = array_values(array_filter(array_map('strval', $urls), static fn(string $u): bool => trim($u) !== ''));
        if ($urls === []) {
            return ['allowed' => [], 'skipped' => []];
        }
        $hashes = [];
        foreach ($urls as $url) {
            $hash = self::hash($url);
            if ($hash !== '') {
                $hashes[$hash] = true;
            }
        }
        if ($hashes === []) {
            return ['allowed' => [], 'skipped' => $urls];
        }

        $known = [];
        try {
            $rows = $this->scanModel->clearQuery()
                ->where(Scan::schema_fields_WEBSITE_ID, $websiteId)
                ->where(Scan::schema_fields_URL_HASH, array_keys($hashes), 'in')
                ->select(Scan::schema_fields_URL_HASH)
                ->fetchArray();
            foreach ($rows ?: [] as $row) {
                $hash = (string)($row[Scan::schema_fields_URL_HASH] ?? '');
                if ($hash !== '') {
                    $known[$hash] = true;
                }
            }
        } catch (\Throwable) {
            $known = [];
        }

        $allowed = [];
        $skipped = [];
        foreach ($urls as $url) {
            if (isset($known[self::hash($url)])) {
                $skipped[] = $url;
            } else {
                $allowed[] = $url;
            }
        }

        return ['allowed' => $allowed, 'skipped' => $skipped];
    }

    /**
     * 记录已扫描地址（幂等，唯一索引兜底）。
     *
     * @param list<string> $urls
     * @return int 新增条数
     */
    public function mark(int $websiteId, array $urls, int $runId = 0): int
    {
        $now = time();
        $saved = 0;
        foreach ($urls as $url) {
            $url = trim((string)$url);
            $hash = self::hash($url);
            if ($hash === '') {
                continue;
            }
            try {
                $exists = $this->scanModel->clearQuery()
                    ->where(Scan::schema_fields_WEBSITE_ID, $websiteId)
                    ->where(Scan::schema_fields_URL_HASH, $hash)
                    ->find()
                    ->fetch();
                if ($exists && (int)($exists[Scan::schema_fields_ID] ?? 0) > 0) {
                    continue;
                }
                $model = $this->scanModel->clearData();
                $model->setData([
                    Scan::schema_fields_WEBSITE_ID => $websiteId,
                    Scan::schema_fields_URL_HASH => $hash,
                    Scan::schema_fields_URL => mb_substr($url, 0, 500),
                    Scan::schema_fields_DOMAIN => mb_substr(self::domain($url), 0, 200),
                    Scan::schema_fields_RUN_ID => $runId,
                    Scan::schema_fields_CREATED_AT => $now,
                    Scan::schema_fields_UPDATED_AT => $now,
                ])->save();
                $saved += 1;
            } catch (\Throwable) {
                // 唯一索引冲突（并发标记）按已存在处理，不打断运行。
            }
        }

        return $saved;
    }

    /**
     * @return list<string> 归一化地址（host+path）
     */
    public function recentUrls(int $websiteId, int $limit = self::SEEN_URL_LIMIT): array
    {
        try {
            $rows = $this->scanModel->clearQuery()
                ->where(Scan::schema_fields_WEBSITE_ID, $websiteId)
                ->order(Scan::schema_fields_ID, 'DESC')
                ->limit($limit)
                ->select(Scan::schema_fields_URL)
                ->fetchArray();
        } catch (\Throwable) {
            return [];
        }

        $urls = [];
        foreach ($rows ?: [] as $row) {
            $normalized = self::normalize((string)($row[Scan::schema_fields_URL] ?? ''));
            if ($normalized !== '') {
                $urls[$normalized] = true;
            }
        }

        return array_keys($urls);
    }

    /**
     * @return list<string> 已扫描过的域名（去 www.）
     */
    public function recentDomains(int $websiteId, int $limit = self::SEEN_DOMAIN_LIMIT): array
    {
        try {
            $rows = $this->scanModel->clearQuery()
                ->where(Scan::schema_fields_WEBSITE_ID, $websiteId)
                ->order(Scan::schema_fields_ID, 'DESC')
                ->limit($limit)
                ->select(Scan::schema_fields_DOMAIN)
                ->fetchArray();
        } catch (\Throwable) {
            return [];
        }

        $domains = [];
        foreach ($rows ?: [] as $row) {
            $domain = strtolower(trim((string)($row[Scan::schema_fields_DOMAIN] ?? '')));
            if ($domain !== '') {
                $domains[$domain] = true;
            }
        }

        return array_keys($domains);
    }

    /**
     * 已有线索的来源域名：这些公司已经入过库，不再重复抓取。
     *
     * @return list<string>
     */
    public function leadDomains(int $websiteId, int $limit = self::SEEN_DOMAIN_LIMIT): array
    {
        try {
            $query = (new Lead())->clearQuery();
            // website_id=0 表示「当前站点」，历史上线索可能落在默认站点名下，这里不做隔离。
            if ($websiteId > 0) {
                $query->where(Lead::schema_fields_WEBSITE_ID, $websiteId);
            }
            $rows = $query
                ->limit($limit)
                ->select(Lead::schema_fields_COMPANY_DOMAIN . ',' . Lead::schema_fields_SOURCE_URL)
                ->fetchArray();
        } catch (\Throwable) {
            return [];
        }

        $domains = [];
        foreach ($rows ?: [] as $row) {
            // 以来源 URL 为准（历史 company_domain 存在截断/带 $q$ 包裹的脏值）。
            $domain = self::domain(self::cleanUrlValue((string)($row[Lead::schema_fields_SOURCE_URL] ?? '')));
            if ($domain === '') {
                $domain = strtolower(trim(self::cleanUrlValue((string)($row[Lead::schema_fields_COMPANY_DOMAIN] ?? ''))));
                if (str_starts_with($domain, 'www.')) {
                    $domain = substr($domain, 4);
                }
                if (str_contains($domain, '/')) {
                    $domain = (string)strstr($domain, '/', true);
                }
            }
            if ($domain !== '' && str_contains($domain, '.')) {
                $domains[$domain] = true;
            }
        }

        return array_keys($domains);
    }

    /**
     * 去掉历史脏值里的 PostgreSQL dollar-quote 包裹（`$q$example.com$q$`）。
     */
    private static function cleanUrlValue(string $value): string
    {
        $value = trim($value);
        if (str_starts_with($value, '$q$')) {
            $value = substr($value, 3);
        }
        if (str_ends_with($value, '$q$')) {
            $value = substr($value, 0, -3);
        }

        return trim($value);
    }

    /**
     * 给浏览器扩展 / 工具的排除上下文。
     *
     * @return array{seen_urls:list<string>,seen_domains:list<string>}
     */
    public function exclusionContext(int $websiteId): array
    {
        $domains = array_values(array_unique(array_merge(
            $this->recentDomains($websiteId),
            $this->leadDomains($websiteId),
        )));

        return [
            'seen_urls' => $this->recentUrls($websiteId),
            'seen_domains' => $domains,
        ];
    }
}
