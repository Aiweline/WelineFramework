<?php

declare(strict_types=1);

namespace Weline\Visitor\Service;

/**
 * D00：landing / device 派生纯函数（不查库）。
 *
 * 口径锁定：
 * - page_path：去 query/fragment，保证 leading `/`，空→`/`，截断 160（与入库一致）
 * - device_category：显式 category → UA → screen_width；统一 mobile|tablet|desktop|''
 * - landing_page：会话内首条 page_view/page_enter 的 path；否则首事件 path；皆无则 ''
 * - §2.5：device_category 可进默认维；page_path 高基不进默认小时全维（本类仅派生，不写维表）
 */
class PixelLandingDeviceDerivation
{
    public const PATH_MAX_LEN = 160;

    /** @var list<string> */
    public const LANDING_EVENTS = ['page_view', 'page_enter'];

    /** @var list<string> */
    public const DEVICE_CATEGORIES = ['mobile', 'tablet', 'desktop'];

    /**
     * 规范化路径或 URL 的 path 部分。
     */
    public function normalizePagePath(string $pathOrUrl): string
    {
        $raw = trim($pathOrUrl);
        if ($raw === '') {
            return '/';
        }

        $path = $raw;
        if (str_contains($raw, '://') || str_starts_with($raw, '//')) {
            $parsed = parse_url($raw, PHP_URL_PATH);
            $path = \is_string($parsed) ? $parsed : '';
        } else {
            // 相对路径可能带 ?query#hash
            $qPos = strpos($path, '?');
            if ($qPos !== false) {
                $path = substr($path, 0, $qPos);
            }
            $hPos = strpos($path, '#');
            if ($hPos !== false) {
                $path = substr($path, 0, $hPos);
            }
        }

        $path = trim((string)$path);
        if ($path === '') {
            return '/';
        }
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }
        // 折叠重复斜杠（保留根）；去掉非根尾斜杠
        $path = (string)preg_replace('#/+#', '/', $path);
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }
        if (\strlen($path) > self::PATH_MAX_LEN) {
            $path = substr($path, 0, self::PATH_MAX_LEN);
        }

        return $path === '' ? '/' : $path;
    }

    /**
     * 从 browser_info 行/解码数组 + 可选 url 解析 page_path（与 Insight 优先级对齐，并规范化）。
     *
     * @param array<string, mixed> $browserInfo 已解码 browser_info，或含 browser_info 键的行
     */
    public function resolvePagePath(array $browserInfo, string $url = ''): string
    {
        $info = $browserInfo;
        if (isset($browserInfo['browser_info']) && !isset($browserInfo['page_path']) && !isset($browserInfo['additionalInfo'])) {
            $raw = $browserInfo['browser_info'];
            if (\is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                $info = \is_array($decoded) ? $decoded : [];
            } elseif (\is_array($raw)) {
                $info = $raw;
            } else {
                $info = [];
            }
        }

        $additional = \is_array($info['additionalInfo'] ?? null) ? $info['additionalInfo'] : [];
        $environment = \is_array($additional['environment'] ?? null) ? $additional['environment'] : [];
        $navigation = \is_array($additional['navigation'] ?? null) ? $additional['navigation'] : [];

        $candidates = [
            (string)($info['page_path'] ?? ''),
            (string)($environment['page_path'] ?? ''),
            (string)($navigation['current_path'] ?? ''),
        ];
        foreach ($candidates as $candidate) {
            if (trim($candidate) !== '') {
                return $this->normalizePagePath($candidate);
            }
        }

        if (trim($url) !== '') {
            return $this->normalizePagePath($url);
        }

        return '/';
    }

    /**
     * 统一设备类别派生。
     *
     * @param array<string, mixed> $device 可含 category / screen_width
     */
    public function deriveDeviceCategory(string $ua = '', array $device = []): string
    {
        $explicit = strtolower(trim((string)($device['category'] ?? $device['device_category'] ?? '')));
        if (\in_array($explicit, self::DEVICE_CATEGORIES, true)) {
            return $explicit;
        }

        $fromUa = $this->guessDeviceFromUa($ua);
        if ($fromUa !== '') {
            // UA 已能区分 mobile/tablet；desktop 再允许屏宽覆盖（窄屏桌面浏览器）
            if ($fromUa !== 'desktop') {
                return $fromUa;
            }
        }

        $width = (int)($device['screen_width'] ?? $device['width'] ?? 0);
        if ($width > 0 && $width < 768) {
            return 'mobile';
        }
        if ($width >= 768 && $width < 1024) {
            return 'tablet';
        }
        if ($fromUa === 'desktop' || $width >= 1024 || $ua !== '') {
            return 'desktop';
        }

        return '';
    }

    /**
     * 会话落地页：时间升序中首条 landing 事件的 path，否则首事件 path。
     *
     * @param list<array<string, mixed>> $events
     *   每项可含 event / page_path / path / url / created_at / browser_info
     */
    public function deriveLandingPage(array $events): string
    {
        if ($events === []) {
            return '';
        }

        $indexed = [];
        foreach ($events as $i => $event) {
            if (!\is_array($event)) {
                continue;
            }
            $indexed[] = [
                'i' => $i,
                'event' => $event,
                'at' => $this->eventSortKey($event),
            ];
        }
        if ($indexed === []) {
            return '';
        }

        usort($indexed, static function (array $a, array $b): int {
            if ($a['at'] === $b['at']) {
                return $a['i'] <=> $b['i'];
            }

            return $a['at'] <=> $b['at'];
        });

        foreach ($indexed as $item) {
            $eventName = strtolower(trim((string)($item['event']['event'] ?? '')));
            if (!\in_array($eventName, self::LANDING_EVENTS, true)) {
                continue;
            }
            $path = $this->pathFromEvent($item['event']);
            if ($path !== '') {
                return $path;
            }
        }

        return $this->pathFromEvent($indexed[0]['event']);
    }

    /**
     * @param array<string, mixed> $event
     */
    private function pathFromEvent(array $event): string
    {
        foreach (['page_path', 'path'] as $key) {
            $raw = trim((string)($event[$key] ?? ''));
            if ($raw !== '') {
                return $this->normalizePagePath($raw);
            }
        }

        if (isset($event['browser_info'])) {
            $info = $event['browser_info'];
            if (\is_string($info) && $info !== '') {
                $decoded = json_decode($info, true);
                $info = \is_array($decoded) ? $decoded : [];
            }
            if (\is_array($info) && $info !== []) {
                return $this->resolvePagePath($info, (string)($event['url'] ?? ''));
            }
        }

        $url = trim((string)($event['url'] ?? ''));
        if ($url !== '') {
            return $this->normalizePagePath($url);
        }

        return '';
    }

    /**
     * @param array<string, mixed> $event
     */
    private function eventSortKey(array $event): string
    {
        $at = trim((string)($event['created_at'] ?? $event['create_time'] ?? ''));
        if ($at !== '') {
            return $at;
        }

        return sprintf('%020d', (int)($event['pixel_id'] ?? $event['id'] ?? 0));
    }

    private function guessDeviceFromUa(string $ua): string
    {
        $ua = trim($ua);
        if ($ua === '') {
            return '';
        }
        $uaLower = strtolower($ua);
        if (preg_match('/mobile|iphone|android(?!.*tablet)|windows phone/', $uaLower)) {
            return 'mobile';
        }
        if (preg_match('/ipad|tablet|kindle|silk/', $uaLower)) {
            return 'tablet';
        }

        return 'desktop';
    }
}
