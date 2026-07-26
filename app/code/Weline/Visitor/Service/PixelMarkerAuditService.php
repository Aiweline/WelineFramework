<?php
declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\Manager\ObjectManager;

/**
 * 一键齐全度巡检：服务端拉已发布页 HTML，对照事件字典。
 */
class PixelMarkerAuditService
{
    public const MAX_URLS = 500;
    public const CACHE_TTL_SECONDS = 86400;

    public function __construct(
        private readonly EventDictionaryService $dictionary,
        private readonly PixelPageTypeClassifier $classifier,
        private readonly PixelMarkerScanner $scanner,
        private readonly PixelJumpResolver $jumpResolver,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function audit(int $websiteId, bool $forceRefresh = false): array
    {
        $websiteId = \max(0, $websiteId);
        $urls = $this->collectUrls($websiteId);
        $dictVersion = $this->dictionary->getVersion();
        $urlHash = \substr(\hash('sha256', \json_encode($urls, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: ''), 0, 16);
        $cacheKey = 'pixel_audit_' . $websiteId . '_' . $dictVersion . '_' . $urlHash;

        if (!$forceRefresh) {
            $cached = $this->readCache($cacheKey);
            if ($cached !== null) {
                $cached['stale'] = false;
                $cached['from_cache'] = true;
                return $cached;
            }
        }

        $events = $this->dictionary->getEvents();
        $pages = [];
        $fetchFailures = [];
        $byEventMissing = [];

        foreach ($events as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $weline = (string)($entry['weline_event'] ?? '');
            if ($weline === '' || !empty($entry['skip_gtm_push'])) {
                // page_view etc. still can require markers if scopes match — include for completeness
            }
            if ($weline === '') {
                continue;
            }
            $byEventMissing[$weline] = [
                'weline_event' => $weline,
                'ga4_event' => (string)($entry['ga4_event'] ?? ''),
                'event_family' => $entry['event_family'] ?? null,
                'missing_pages' => [],
                'applicable_pages' => 0,
                'found_pages' => 0,
                'status' => 'not_applicable',
            ];
        }

        $completeCount = 0;
        $missingCount = 0;

        foreach ($urls as $row) {
            $url = (string)($row['url'] ?? '');
            $pageId = (int)($row['page_id'] ?? 0);
            $pageType = $this->classifier->classify($row);
            $jump = $this->jumpResolver->resolve($websiteId, $pageId, (string)($row['source'] ?? 'pagebuilder'), $url);

            if ($url === '') {
                $fetchFailures[] = [
                    'page_id' => $pageId,
                    'url' => $url,
                    'error' => 'fetch_failed_other',
                    'message' => 'empty_url',
                    'jump' => $jump,
                ];
                $pages[] = [
                    'page_id' => $pageId,
                    'url' => $url,
                    'page_type' => $pageType,
                    'status' => 'fetch_failed',
                    'found_events' => [],
                    'missing_events' => [],
                    'fetch_error' => 'fetch_failed_other',
                    'jump' => $jump,
                ];
                continue;
            }

            $fetch = $this->fetchHtml($url);
            if (!$fetch['ok']) {
                $fetchFailures[] = [
                    'page_id' => $pageId,
                    'url' => $url,
                    'error' => $fetch['error_code'],
                    'message' => $fetch['message'],
                    'jump' => $jump,
                ];
                $pages[] = [
                    'page_id' => $pageId,
                    'url' => $url,
                    'page_type' => $pageType,
                    'status' => 'fetch_failed',
                    'found_events' => [],
                    'missing_events' => [],
                    'fetch_error' => $fetch['error_code'],
                    'jump' => $jump,
                ];
                continue;
            }

            $scan = $this->scanner->scanHtml((string)$fetch['html']);
            $required = $this->requiredEventsForPageType($events, $pageType);
            $missing = [];
            $satisfiedFamilies = [];

            // First pass: mark family satisfaction
            foreach ($required as $entry) {
                $family = (string)($entry['event_family'] ?? '');
                $exact = !empty($entry['require_exact_marker']);
                if ($family === '' || $exact) {
                    continue;
                }
                if ($this->scanner->matchesMarkers((array)($entry['markers'] ?? []), $scan)) {
                    $satisfiedFamilies[$family] = true;
                }
            }

            foreach ($required as $entry) {
                $weline = (string)($entry['weline_event'] ?? '');
                if ($weline === '') {
                    continue;
                }
                if (isset($byEventMissing[$weline])) {
                    $byEventMissing[$weline]['applicable_pages'] = (int)$byEventMissing[$weline]['applicable_pages'] + 1;
                }
                $family = (string)($entry['event_family'] ?? '');
                $exact = !empty($entry['require_exact_marker']);
                $matched = $this->scanner->matchesMarkers((array)($entry['markers'] ?? []), $scan);
                if (!$matched && !$exact && $family !== '' && !empty($satisfiedFamilies[$family])) {
                    $matched = true;
                }
                if ($matched) {
                    if (isset($byEventMissing[$weline])) {
                        $byEventMissing[$weline]['found_pages'] = (int)$byEventMissing[$weline]['found_pages'] + 1;
                    }
                } else {
                    $missing[] = $weline;
                    if (isset($byEventMissing[$weline])) {
                        $byEventMissing[$weline]['missing_pages'][] = [
                            'page_id' => $pageId,
                            'url' => $url,
                            'page_type' => $pageType,
                            'jump' => $jump,
                        ];
                    }
                }
            }

            $status = $missing === [] ? 'complete' : 'missing_marker';
            if ($status === 'complete') {
                $completeCount++;
            } else {
                $missingCount++;
            }

            $pages[] = [
                'page_id' => $pageId,
                'url' => $url,
                'page_type' => $pageType,
                'status' => $status,
                'found_events' => $scan['events'],
                'missing_events' => $missing,
                'fetch_error' => null,
                'jump' => $jump,
                'param_risk_note' => '静态巡检不验证运行时必填参数；缺参为黄灯，不拦 GTM',
            ];
        }

        $generatedAt = \time();
        foreach ($byEventMissing as &$eventRow) {
            $applicable = (int)($eventRow['applicable_pages'] ?? 0);
            $missingPages = $eventRow['missing_pages'] ?? [];
            if ($applicable <= 0) {
                $eventRow['status'] = 'not_applicable';
            } elseif (\is_array($missingPages) && $missingPages !== []) {
                $eventRow['status'] = 'missing_marker';
            } else {
                $eventRow['status'] = 'complete';
            }
        }
        unset($eventRow);

        $report = [
            'website_id' => $websiteId,
            'dict_version' => $dictVersion,
            'generated_at' => $generatedAt,
            'expired_at' => $generatedAt + self::CACHE_TTL_SECONDS,
            'stale' => false,
            'from_cache' => false,
            'url_hash' => $urlHash,
            'summary' => [
                'pages' => \count($pages),
                'complete' => $completeCount,
                'missing_marker' => $missingCount,
                'fetch_failed' => \count($fetchFailures),
                'param_risk_notes' => '黄灯 param_risk 仅运行时可见；本报告为静态标记',
                'disclaimer' => '静态标记巡检；不代表已写入 dataLayer / GTM。客户端后插 DOM 可能漏检。',
            ],
            'pages' => $pages,
            'by_event' => \array_values($byEventMissing),
            'fetch_failures' => $fetchFailures,
            'dictionary' => $this->dictionary->listForPanel(),
        ];

        $this->writeCache($cacheKey, $report);
        $this->writeLatestPointer($websiteId, $cacheKey);

        return $report;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getLatestReport(int $websiteId): ?array
    {
        $pointer = $this->readLatestPointer($websiteId);
        if ($pointer === null) {
            return null;
        }
        $cached = $this->readCache($pointer);
        if ($cached === null) {
            return null;
        }
        $expiredAt = (int)($cached['expired_at'] ?? 0);
        $cached['stale'] = $expiredAt > 0 && \time() > $expiredAt;
        $cached['from_cache'] = true;
        return $cached;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function collectUrls(int $websiteId): array
    {
        $rows = [];
        if (\class_exists(\GuoLaiRen\PageBuilder\Model\Page::class)) {
            try {
                $rows = $this->collectPageBuilderUrls($websiteId);
            } catch (\Throwable $e) {
                if (\function_exists('w_log_warning')) {
                    \w_log_warning('Pixel audit PageBuilder URL collect failed: ' . $e->getMessage(), [
                        'website_id' => $websiteId,
                    ], 'visitor');
                }
            }
        }

        if ($rows === []) {
            $base = $this->websiteBaseUrl($websiteId);
            if ($base !== '') {
                $rows[] = [
                    'page_id' => 0,
                    'url' => $base,
                    'type' => 'home',
                    'handle' => 'home',
                    'is_home' => true,
                    'source' => 'website_home',
                ];
            }
        }

        if (\count($rows) > self::MAX_URLS) {
            $rows = \array_slice($rows, 0, self::MAX_URLS);
        }

        return $rows;
    }

    /**
     * @return array{ok: bool, html: string, error_code: string, message: string}
     */
    public function fetchHtml(string $url): array
    {
        $host = (string)(\parse_url($url, \PHP_URL_HOST) ?: '');
        $isLocalTest = $host !== '' && (
            \str_ends_with(\strtolower($host), '.weline.test')
            || $host === 'localhost'
            || $host === '127.0.0.1'
        );

        $verifyPeer = !$isLocalTest;
        $ctx = \stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 12,
                'follow_location' => 1,
                'max_redirects' => 3,
                'header' => "User-Agent: WelinePixelAudit/1.0\r\nAccept: text/html\r\n",
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => $verifyPeer,
                'verify_peer_name' => $verifyPeer,
            ],
        ]);

        $html = false;
        $errorMessage = '';
        try {
            $html = @\file_get_contents($url, false, $ctx);
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
        }

        $status = 0;
        if (isset($http_response_header) && \is_array($http_response_header)) {
            foreach ($http_response_header as $headerLine) {
                if (\preg_match('#^HTTP/\S+\s+(\d+)#i', (string)$headerLine, $m)) {
                    $status = (int)$m[1];
                }
            }
        }

        if ($html === false) {
            $code = 'fetch_failed_other';
            $msg = $errorMessage !== '' ? $errorMessage : 'request_failed';
            if (\stripos($msg, 'ssl') !== false || \stripos($msg, 'certificate') !== false) {
                $code = 'fetch_failed_ssl';
            } elseif (\stripos($msg, 'timed out') !== false || \stripos($msg, 'timeout') !== false) {
                $code = 'fetch_failed_timeout';
            }
            return ['ok' => false, 'html' => '', 'error_code' => $code, 'message' => $msg];
        }

        if ($status >= 400 && $status < 500) {
            return ['ok' => false, 'html' => '', 'error_code' => 'fetch_failed_http_4xx', 'message' => 'HTTP ' . $status];
        }
        if ($status >= 500) {
            return ['ok' => false, 'html' => '', 'error_code' => 'fetch_failed_http_5xx', 'message' => 'HTTP ' . $status];
        }

        return ['ok' => true, 'html' => (string)$html, 'error_code' => '', 'message' => ''];
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    private function requiredEventsForPageType(array $events, string $pageType): array
    {
        $required = [];
        foreach ($events as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $scopes = $entry['page_scopes'] ?? ['*'];
            if (!\is_array($scopes)) {
                $scopes = ['*'];
            }
            $scopes = \array_map('strval', $scopes);
            if ($pageType === PixelPageTypeClassifier::TYPE_UNKNOWN) {
                if (!\in_array('*', $scopes, true)) {
                    continue;
                }
            } elseif (!\in_array('*', $scopes, true) && !\in_array($pageType, $scopes, true)) {
                continue;
            }
            // page_view / page_enter are skip_gtm_push diagnostics; do not require
            // static markers for completeness (GA4/GTM config owns page_view).
            if (!empty($entry['skip_gtm_push'])) {
                continue;
            }
            $required[] = $entry;
        }
        return $required;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectPageBuilderUrls(int $websiteId): array
    {
        /** @var \GuoLaiRen\PageBuilder\Model\Page $pageModel */
        $pageModel = ObjectManager::getInstance(\GuoLaiRen\PageBuilder\Model\Page::class);
        $items = $pageModel->reset()
            ->where(\GuoLaiRen\PageBuilder\Model\Page::schema_fields_WEBSITE_ID, $websiteId)
            ->where(\GuoLaiRen\PageBuilder\Model\Page::schema_fields_STATUS, \GuoLaiRen\PageBuilder\Model\Page::STATUS_PUBLISHED)
            ->order(\GuoLaiRen\PageBuilder\Model\Page::schema_fields_ID, 'ASC')
            ->select()
            ->fetch()
            ->getItems();

        $base = \rtrim($this->websiteBaseUrl($websiteId), '/');
        if ($base !== '') {
            $base = \rtrim($this->preferLocalWlsUrl($base . '/'), '/');
        }
        $byPageId = [];

        foreach ($items as $page) {
            $pageId = (int)$page->getData(\GuoLaiRen\PageBuilder\Model\Page::schema_fields_ID);
            if ($pageId <= 0 || isset($byPageId[$pageId])) {
                continue;
            }
            $type = (string)$page->getData(\GuoLaiRen\PageBuilder\Model\Page::schema_fields_TYPE);
            $handle = \trim((string)$page->getData(\GuoLaiRen\PageBuilder\Model\Page::schema_fields_HANDLE));
            $isHome = $type === \GuoLaiRen\PageBuilder\Model\Page::TYPE_HOME || $handle === 'home' || $handle === '';
            if ($base === '') {
                continue;
            }
            $url = $isHome ? $base . '/' : $base . '/' . \ltrim($handle, '/');
            $byPageId[$pageId] = [
                'page_id' => $pageId,
                'url' => $url,
                'type' => $type,
                'handle' => $handle,
                'is_home' => $isHome,
                'source' => 'pagebuilder',
            ];
        }

        return \array_values($byPageId);
    }

    /**
     * *.weline.test without explicit port often hits a non-WLS listener (thin HTML).
     * Prefer a live local WLS HTTP port so audit does not false-red.
     */
    private function preferLocalWlsUrl(string $url): string
    {
        $parts = \parse_url($url);
        if (!\is_array($parts)) {
            return $url;
        }
        $host = \strtolower((string)($parts['host'] ?? ''));
        if ($host === '' || !\str_ends_with($host, '.weline.test')) {
            return $url;
        }
        if (!empty($parts['port'])) {
            return $url;
        }
        $scheme = (string)($parts['scheme'] ?? 'http');
        $path = (string)($parts['path'] ?? '/');
        $query = isset($parts['query']) ? ('?' . $parts['query']) : '';
        foreach ($this->candidateLocalWlsPorts() as $port) {
            $candidate = $scheme . '://' . $host . ':' . $port . ($path === '' ? '/' : $path) . $query;
            $probe = $this->fetchHtml($candidate);
            if (!$probe['ok']) {
                continue;
            }
            $html = (string)$probe['html'];
            if (\strlen($html) < 2000) {
                continue;
            }
            if (
                \str_contains($html, 'weline-pixel::')
                || \str_contains($html, '__WelinePixelEnv')
                || \str_contains($html, 'WelinePixel')
                || \str_contains($html, 'pb-c-')
                || \strlen($html) > 20000
            ) {
                return $candidate;
            }
        }
        return $url;
    }

    /**
     * @return list<int>
     */
    private function candidateLocalWlsPorts(): array
    {
        $ports = [];
        $envPort = (int)(\getenv('WELINE_WLS_PORT') ?: 0);
        if ($envPort > 0) {
            $ports[] = $envPort;
        }
        foreach ([9524, 9510, 9513, 9521, 10551, 10571, 10631] as $port) {
            $ports[] = $port;
        }
        return \array_values(\array_unique($ports));
    }

    private function websiteBaseUrl(int $websiteId): string
    {
        try {
            $website = \w_query('websites', 'getWebsiteById', ['website_id' => $websiteId]);
            if (!\is_array($website)) {
                return '';
            }
            $url = \trim((string)($website['url'] ?? $website['domain'] ?? ''));
            if ($url === '') {
                return '';
            }
            if (!\preg_match('#^https?://#i', $url)) {
                $url = 'https://' . \ltrim($url, '/');
            }
            return \rtrim($url, '/');
        } catch (\Throwable) {
            return '';
        }
    }

    private function cacheDir(): string
    {
        $root = \defined('BP') ? (string)BP : \dirname(__DIR__, 5);
        $dir = $root . \DIRECTORY_SEPARATOR . 'var' . \DIRECTORY_SEPARATOR . 'visitor_pixel_audit';
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readCache(string $cacheKey): ?array
    {
        $file = $this->cacheDir() . \DIRECTORY_SEPARATOR . $cacheKey . '.json';
        if (!\is_file($file)) {
            return null;
        }
        $raw = \file_get_contents($file);
        $decoded = \is_string($raw) ? \json_decode($raw, true) : null;
        if (!\is_array($decoded)) {
            return null;
        }
        $expiredAt = (int)($decoded['expired_at'] ?? 0);
        if ($expiredAt > 0 && \time() > $expiredAt) {
            return null;
        }
        return $decoded;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function writeCache(string $cacheKey, array $report): void
    {
        $file = $this->cacheDir() . \DIRECTORY_SEPARATOR . $cacheKey . '.json';
        @\file_put_contents(
            $file,
            \json_encode($report, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)
        );
    }

    private function writeLatestPointer(int $websiteId, string $cacheKey): void
    {
        $file = $this->cacheDir() . \DIRECTORY_SEPARATOR . 'latest_' . $websiteId . '.txt';
        @\file_put_contents($file, $cacheKey);
    }

    private function readLatestPointer(int $websiteId): ?string
    {
        $file = $this->cacheDir() . \DIRECTORY_SEPARATOR . 'latest_' . $websiteId . '.txt';
        if (!\is_file($file)) {
            return null;
        }
        $key = \trim((string)\file_get_contents($file));
        return $key !== '' ? $key : null;
    }
}
