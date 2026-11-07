<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Warmup;

/**
 * 店面 FPC 预热：按站点 Host 依次请求热门页，把冷渲染结果写进整页缓存。
 *
 * 实测（本机）：冷渲染 `/products` 约 230–380 ms，预热后首访 31–63 ms。
 * 只在「回环 + Host 头」下发请求，不依赖 DNS、证书链与 CDN。
 */
final class StorefrontFpcPrewarmer
{
    /** @var list<string> */
    public const DEFAULT_PATHS = ['/', '/products'];

    /** 默认顺带预热的热度 PDP 条数（再按语种展开前）。 */
    public const DEFAULT_PRODUCT_LIMIT = 6;

    /**
     * 自动生成预热路径：首页 + 商品列表 + Top N 一级分类 + Top M 热度 PDP，
     * 并按需铺到站点已启用的语种。
     *
     * @return list<string>
     */
    public function discoverPaths(
        int $websiteId = 0,
        int $limitCategories = 8,
        array $locales = [],
        bool $allLocales = false,
        int $limitProducts = self::DEFAULT_PRODUCT_LIMIT,
    ): array {
        $base = ['/', '/products'];
        try {
            /** @var \Weline\Product\Service\StorefrontCategoryTreeIndex $index */
            $index = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Product\Service\StorefrontCategoryTreeIndex::class
            );
            $tree = $index->forWebsite(max(0, $websiteId), '');
            $count = 0;
            foreach ((array)($tree['by_id'] ?? []) as $node) {
                if (!\is_array($node) || (int)($node['parent_id'] ?? -1) !== 0) {
                    continue;
                }
                $path = \trim((string)($node['path'] ?? ''));
                if ($path === '') {
                    continue;
                }
                $base[] = '/category/' . \ltrim($path, '/');
                if (++$count >= \max(0, $limitCategories)) {
                    break;
                }
            }
        } catch (\Throwable) {
            // 分类取不到就只预热首页与列表页。
        }

        foreach ($this->discoverProductDetailPaths(max(0, $websiteId), max(0, $limitProducts)) as $productPath) {
            $base[] = $productPath;
        }

        $locales = $this->normalizeLocales($locales, $websiteId, $allLocales);
        if ($locales === []) {
            return \array_values(\array_unique($base));
        }
        $defaultLocale = '';
        try {
            // 站点默认语言才是「不带语种前缀」的那一档；Env::get('locale') 在 CLI 下
            // 常取不到或与站点默认不一致，会把默认语言也铺成 /zh_Hans_CN/ 这类带前缀
            // 的 URL，导致规范 URL `/` 没被预热、首个真实请求仍现场渲染。
            $defaultLocale = \strtolower(\trim(
                (string)\Weline\Websites\Data\WebsiteData::defaultLanguageForWebsite(\max(0, $websiteId))
            ));
        } catch (\Throwable) {
            $defaultLocale = '';
        }
        if ($defaultLocale === '') {
            try {
                $defaultLocale = \strtolower(\trim((string)\Weline\Framework\App\Env::get('locale', '')));
            } catch (\Throwable) {
                $defaultLocale = '';
            }
        }

        $paths = [];
        foreach ($locales as $locale) {
            $prefix = ($defaultLocale !== '' && \strtolower($locale) === $defaultLocale) ? '' : '/' . $locale;
            foreach ($base as $path) {
                $paths[] = $prefix . $path;
            }
        }

        return \array_values(\array_unique($paths === [] ? $base : $paths));
    }

    /**
     * 热度 Top N 商品详情路径（/product/{slug}）。失败则跳过，不影响分类预热。
     *
     * @return list<string>
     */
    private function discoverProductDetailPaths(int $websiteId, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }
        try {
            /** @var \Weline\Product\Service\StorefrontProductWidgetCatalog $catalog */
            $catalog = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Product\Service\StorefrontProductWidgetCatalog::class
            );
            $cards = $catalog->bestSellerCards($limit);
        } catch (\Throwable) {
            return [];
        }
        if (!\is_array($cards) || $cards === []) {
            return [];
        }

        $paths = [];
        foreach ($cards as $card) {
            if (!\is_array($card)) {
                continue;
            }
            $slug = \strtolower(\trim((string)($card['slug'] ?? '')));
            $productId = \max(0, (int)($card['product_id'] ?? $card['id'] ?? 0));
            $path = $slug !== '' ? '/product/' . $slug : ($productId > 0 ? '/product/' . $productId : '');
            if ($path === '') {
                continue;
            }
            $paths[$path] = $path;
            if (\count($paths) >= $limit) {
                break;
            }
        }

        return \array_values($paths);
    }

    /**
     * 语种清单：显式传入优先；否则默认语言一档；只有 $allLocales 才铺满站点全部语种
     * （铺满会变成上百条路径，实测会把 Worker 打到 503）。
     *
     * @param list<string> $locales
     * @return list<string>
     */
    private function normalizeLocales(array $locales, int $websiteId, bool $allLocales = false): array
    {
        $out = [];
        foreach ($locales as $locale) {
            $locale = \trim((string)$locale);
            if ($locale !== '') {
                $out[$locale] = true;
            }
        }
        if ($out !== []) {
            return \array_keys($out);
        }
        if (!$allLocales) {
            $default = '';
            try {
                $default = \trim((string)\Weline\Framework\App\Env::get('locale', ''));
            } catch (\Throwable) {
                $default = '';
            }

            return $default === '' ? [] : [$default];
        }
        try {
            $codes = \Weline\Websites\Data\WebsiteData::languageCodesForWebsite(max(0, $websiteId));
            foreach ((array)$codes as $code) {
                $code = \trim((string)$code);
                if ($code !== '') {
                    $out[$code] = true;
                }
            }
        } catch (\Throwable) {
            // 站点语言取不到：只预热默认语言。
        }

        return \array_keys($out);
    }

    /**
     * 当前站点对外 Host（用于回环请求的 Host 头）；取不到返回空串。
     *
     * 回环地址（localhost / 127.0.0.1 / ::1）一律不算有效结果：用它预热只会打到
     * 错误的 Host 上，等于没预热（实测 CLI 下 `website.url` 可能解析成 localhost，
     * 于是主题更新后的店面预热全部落空，页面仍是首次请求现场构建）。
     */
    public function resolveHost(): string
    {
        $candidates = [];
        try {
            $candidates[] = (string)\Weline\Framework\App\Env::get('website.url', '');
        } catch (\Throwable) {
            // fall through
        }
        foreach (['wls.public_host', 'wls.host', 'server.public_host', 'server.host'] as $key) {
            try {
                $candidates[] = (string)\Weline\Framework\App\Env::get($key, '');
            } catch (\Throwable) {
                // ignore
            }
        }
        try {
            $candidates[] = (string)(\w_env('server.HTTP_HOST', '') ?: '');
        } catch (\Throwable) {
            // ignore
        }
        // 站点柜里默认站绑定的域名（多站点时预热当前默认站点）。
        try {
            $website = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Websites\Model\Website::class);
            $row = $website->select()
                ->where('code', \Weline\Websites\Model\Website::CODE_DEFAULT)
                ->limit(1)
                ->fetchArray();
            $candidates[] = (string)($row[0]['url'] ?? '');
        } catch (\Throwable) {
            // 放弃
        }
        // 站点柜绑定的主域名。每个站独立库，主域就是该站对外的 Host；
        // 有些站没配 wls.public_host，此时这是唯一可用的预热目标。
        try {
            $domainModel = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Websites\Model\WebsiteDomain::class
            );
            $domainRows = $domainModel->select()
                ->where(\Weline\Websites\Model\WebsiteDomain::schema_fields_IS_PRIMARY, 1)
                ->limit(1)
                ->fetchArray();
            $candidates[] = (string)($domainRows[0][\Weline\Websites\Model\WebsiteDomain::schema_fields_DOMAIN] ?? '');
        } catch (\Throwable) {
            // 放弃
        }

        foreach ($candidates as $candidate) {
            $candidate = \trim($candidate);
            if ($candidate === '') {
                continue;
            }
            $host = (string)(\parse_url($candidate, PHP_URL_HOST) ?: $candidate);
            if (self::isWarmupHostUsable($host)) {
                return $host;
            }
        }

        return '';
    }

    /** 回环地址/空值不能当预热目标 Host。 */
    private static function isWarmupHostUsable(string $host): bool
    {
        $host = \strtolower(\trim($host));
        if ($host === '') {
            return false;
        }

        return !\in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]', '0.0.0.0'], true);
    }

    /**
     * @param list<string> $paths
     * @return array{ok:int,total:int,failed:list<string>,details:list<array{path:string,status:int,ms:float}>}
     */
    public function run(
        string $host,
        array $paths = self::DEFAULT_PATHS,
        int $port = 443,
        string $scheme = 'https',
        int $timeout = 60,
        int $waitReadySeconds = 0,
        int $concurrency = 1,
    ): array {
        $host = \trim($host);
        $paths = $this->normalizePaths($paths);
        $result = ['ok' => 0, 'total' => \count($paths), 'failed' => [], 'details' => [], 'maintenance' => false];
        if ($host === '' || $paths === []) {
            return $result;
        }
        // 维护模式下的 503 不是「预热失败」，是站点根本不在服务：先识别出来，
        // 否则会把整份清单都跑成 FAIL（本机多次踩到，浪费一轮预热）。
        if ($this->isInMaintenance($host, $port, $scheme)) {
            $result['maintenance'] = true;
            $result['failed'] = ['maintenance_mode'];

            return $result;
        }
        if ($waitReadySeconds > 0) {
            $this->waitUntilReady($host, $port, $scheme, $waitReadySeconds);
        }
        if ($concurrency > 1) {
            return $this->runConcurrent($host, $paths, $port, $scheme, $timeout, $concurrency);
        }

        foreach ($paths as $index => $path) {
            $url = $scheme . '://127.0.0.1:' . $port . $path;
            [$status, $ms] = $this->request($url, $host, $timeout);
            if ($status === 503 || $status === 0) {
                // 突发预热会把 Worker 打到 503（准入/背压）：退避后重试一次。
                \sleep(1);
                [$status, $ms] = $this->request($url, $host, $timeout);
            }
            $result['details'][] = ['path' => $path, 'status' => $status, 'ms' => $ms];
            if ($status === 200) {
                $result['ok']++;
            } else {
                $result['failed'][] = $path . '(' . $status . ')';
            }
            // 轻微节奏控制：避免连续冷渲染把两个 Worker 同时压满。
            if ($index < \count($paths) - 1) {
                \usleep(200_000);
            }
        }

        return $result;
    }

    /**
     * 并发预热：冷渲染单页 0.2–1.9s，串行铺完整个清单很慢；这里按批并发（默认 2–3 个在飞），
     * 失败的条目在批结束后串行补一次，避免一次性把 Worker 压到 503。
     *
     * @param list<string> $paths
     * @return array{ok:int,total:int,failed:list<string>,details:list<array{path:string,status:int,ms:float}>}
     */
    private function runConcurrent(string $host, array $paths, int $port, string $scheme, int $timeout, int $concurrency): array
    {
        $result = ['ok' => 0, 'total' => \count($paths), 'failed' => [], 'details' => []];
        $chunks = \array_chunk($paths, \max(1, $concurrency));
        foreach ($chunks as $chunk) {
            $handles = [];
            $started = [];
            $multi = \curl_multi_init();
            if ($multi === false) {
                return $this->run($host, $paths, $port, $scheme, $timeout, 0, 1);
            }
            foreach ($chunk as $path) {
                $url = $scheme . '://127.0.0.1:' . $port . $path;
                $handle = \curl_init($url);
                if ($handle === false) {
                    continue;
                }
                \curl_setopt_array($handle, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => ['Host: ' . $host],
                    CURLOPT_TIMEOUT => \max(1, $timeout),
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_ENCODING => '',
                ]);
                \curl_multi_add_handle($multi, $handle);
                $handles[$path] = $handle;
                $started[$path] = \microtime(true);
            }

            do {
                $status = \curl_multi_exec($multi, $running);
                if ($running > 0) {
                    \curl_multi_select($multi, 1.0);
                }
            } while ($running > 0 && $status === CURLM_OK);

            foreach ($handles as $path => $handle) {
                $code = (int)\curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                $ms = (\microtime(true) - ($started[$path] ?? \microtime(true))) * 1000;
                $result['details'][] = ['path' => $path, 'status' => $code, 'ms' => $ms];
                if ($code === 200) {
                    $result['ok']++;
                } else {
                    $result['failed'][] = $path . '(' . $code . ')';
                }
                \curl_multi_remove_handle($multi, $handle);
                \curl_close($handle);
            }
            \curl_multi_close($multi);
        }

        // 失败的条目补一次（串行，退避 1s）：并发批里偶发 503 通常是一次性背压。
        if ($result['failed'] !== []) {
            $retryPaths = [];
            foreach ($result['failed'] as $entry) {
                $retryPaths[] = (string)\preg_replace('/\(\d+\)$/', '', $entry);
            }
            \sleep(1);
            foreach ($retryPaths as $path) {
                $url = $scheme . '://127.0.0.1:' . $port . $path;
                [$code, $ms] = $this->request($url, $host, $timeout);
                if ($code !== 200) {
                    continue;
                }
                $result['ok']++;
                $result['failed'] = \array_values(\array_filter(
                    $result['failed'],
                    static fn(string $entry): bool => !\str_starts_with($entry, $path . '(')
                ));
                $result['details'][] = ['path' => $path, 'status' => $code, 'ms' => $ms];
            }
        }

        return $result;
    }

    /** 站点当前是否处于维护模式（响应头 `x-weline-maintenance: 1`）。 */
    private function isInMaintenance(string $host, int $port, string $scheme): bool
    {
        $handle = \curl_init($scheme . '://127.0.0.1:' . $port . '/');
        if ($handle === false) {
            return false;
        }
        \curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => ['Host: ' . $host],
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $raw = (string)\curl_exec($handle);
        \curl_close($handle);

        return \preg_match('/^x-weline-maintenance:\s*1/mi', $raw) === 1;
    }

    /**
     * 轮询直到 Worker 真正可用（预热必须在代码重载/Worker 重启之后，否则写进去的缓存会被清掉）。
     * 判断依据：HTTP 200 且响应头 `x-wls-warmup-status: ready`。
     */
    private function waitUntilReady(string $host, int $port, string $scheme, int $maxSeconds): void
    {
        $deadline = \microtime(true) + \max(1, $maxSeconds);
        $url = $scheme . '://127.0.0.1:' . $port . '/';
        while (\microtime(true) < $deadline) {
            $handle = \curl_init($url);
            if ($handle === false) {
                return;
            }
            \curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_NOBODY => false,
                CURLOPT_HTTPHEADER => ['Host: ' . $host],
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
            ]);
            $raw = (string)\curl_exec($handle);
            $status = (int)\curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            \curl_close($handle);

            if ($status === 200) {
                $warmup = '';
                if (\preg_match('/^x-wls-warmup-status:\s*(\S+)/mi', $raw, $m) === 1) {
                    $warmup = \strtolower(\trim($m[1]));
                }
                if ($warmup === '' || $warmup === 'ready') {
                    return;
                }
            }
            \sleep(1);
        }
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function normalizePaths(array $paths): array
    {
        $out = [];
        foreach ($paths as $path) {
            $path = \trim((string)$path);
            if ($path === '') {
                continue;
            }
            $out[] = \str_starts_with($path, '/') ? $path : '/' . $path;
        }

        return \array_values(\array_unique($out));
    }

    /** @return array{0:int,1:float} */
    private function request(string $url, string $host, int $timeout): array
    {
        $handle = \curl_init($url);
        if ($handle === false) {
            return [0, 0.0];
        }
        \curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Host: ' . $host],
            CURLOPT_TIMEOUT => \max(1, $timeout),
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_ENCODING => '',
        ]);
        $started = \microtime(true);
        \curl_exec($handle);
        $status = (int)\curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        \curl_close($handle);

        return [$status, (\microtime(true) - $started) * 1000];
    }
}
