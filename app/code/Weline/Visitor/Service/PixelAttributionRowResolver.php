<?php

declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Model\Pixel;

/**
 * 查询侧归因解析（工程计划 A15）：扁平列优先，空则回退 browser_info/url/referer。
 * 不查 pixel_channel；供 Statistics/Insight 在回填完成前避免空窗。
 */
class PixelAttributionRowResolver
{
    public function __construct(
        private ?PixelTrafficAttributionService $attribution = null
    ) {
    }

    /**
     * @param array<string, mixed> $row w_pixel 行（可含扁平列与 browser_info）
     * @return array{
     *   session_id: string,
     *   channel_code: string,
     *   channel_name: string,
     *   traffic_type: string,
     *   utm_source: string,
     *   utm_medium: string,
     *   utm_campaign: string,
     *   source_label: string,
     *   origin: 'flat'|'browser'|'legacy'|'empty'
     * }
     */
    public function resolve(array $row): array
    {
        $flat = $this->readFlat($row);
        $sessionId = $flat['session_id'] !== ''
            ? $flat['session_id']
            : $this->sessionFromBrowser($row);

        if ($this->flatHasMarketingSignals($flat)) {
            $pack = [
                'session_id' => $sessionId,
                'channel_code' => $flat['channel_code'],
                'channel_name' => $flat['channel_name'],
                'traffic_type' => $flat['traffic_type'],
                'utm_source' => $flat['utm_source'],
                'utm_medium' => $flat['utm_medium'],
                'utm_campaign' => $flat['utm_campaign'],
                'origin' => 'flat',
            ];
            $pack['source_label'] = $this->buildSourceLabel($pack, $row);

            return $pack;
        }

        $browserPack = $this->resolveFromBrowserAndUrl($row);
        if ($sessionId !== '') {
            $browserPack['session_id'] = $sessionId;
        }
        if ($this->packHasStrongMarketingSignals($browserPack)) {
            $browserPack['origin'] = 'browser';
            $browserPack['source_label'] = $this->buildSourceLabel($browserPack, $row);

            return $browserPack;
        }

        $legacy = trim((string)($row[Pixel::schema_fields_SOURCE] ?? $row['source'] ?? ''));
        if ($legacy !== '' && strtolower($legacy) !== 'worker') {
            return [
                'session_id' => $sessionId !== '' ? $sessionId : (string)($browserPack['session_id'] ?? ''),
                'channel_code' => '',
                'channel_name' => '',
                'traffic_type' => (string)($browserPack['traffic_type'] ?? ''),
                'utm_source' => '',
                'utm_medium' => '',
                'utm_campaign' => '',
                'source_label' => $legacy,
                'origin' => 'legacy',
            ];
        }

        $referer = trim((string)($row[Pixel::schema_fields_REFERER] ?? $row['referer'] ?? ''));
        $host = $this->hostFromUrl($referer);

        return [
            'session_id' => $sessionId !== '' ? $sessionId : (string)($browserPack['session_id'] ?? ''),
            'channel_code' => '',
            'channel_name' => '',
            'traffic_type' => $host !== '' ? 'referral' : ((string)($browserPack['traffic_type'] ?? '') ?: 'direct'),
            'utm_source' => '',
            'utm_medium' => '',
            'utm_campaign' => '',
            'source_label' => $host !== '' ? $host : 'direct',
            'origin' => 'empty',
        ];
    }

    /**
     * @param array<string, mixed> $pack
     * @param array<string, mixed> $row
     */
    public function buildSourceLabel(array $pack, array $row = []): string
    {
        $utmSource = trim((string)($pack['utm_source'] ?? ''));
        $utmMedium = trim((string)($pack['utm_medium'] ?? ''));
        if ($utmSource !== '') {
            return $utmSource . ($utmMedium !== '' ? '/' . $utmMedium : '');
        }

        $channel = trim((string)($pack['channel_code'] ?? ''));
        if ($channel !== '') {
            return $channel;
        }

        $legacy = trim((string)($row[Pixel::schema_fields_SOURCE] ?? $row['source'] ?? ''));
        if ($legacy !== '' && strtolower($legacy) !== 'worker') {
            return $legacy;
        }

        $referer = trim((string)($row[Pixel::schema_fields_REFERER] ?? $row['referer'] ?? ''));
        $host = $this->hostFromUrl($referer);

        return $host !== '' ? $host : 'direct';
    }

    /**
     * @param array<string, mixed> $flat
     */
    public function flatHasMarketingSignals(array $flat): bool
    {
        return trim((string)($flat['channel_code'] ?? '')) !== ''
            || trim((string)($flat['utm_source'] ?? '')) !== ''
            || trim((string)($flat['utm_medium'] ?? '')) !== ''
            || trim((string)($flat['utm_campaign'] ?? '')) !== ''
            || trim((string)($flat['traffic_type'] ?? '')) !== '';
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *   session_id: string,
     *   channel_code: string,
     *   channel_name: string,
     *   traffic_type: string,
     *   utm_source: string,
     *   utm_medium: string,
     *   utm_campaign: string
     * }
     */
    public function readFlat(array $row): array
    {
        return [
            'session_id' => trim((string)($row['session_id'] ?? '')),
            'channel_code' => trim((string)($row['channel_code'] ?? '')),
            'channel_name' => trim((string)($row['channel_name'] ?? '')),
            'traffic_type' => trim((string)($row['traffic_type'] ?? '')),
            'utm_source' => trim((string)($row['utm_source'] ?? '')),
            'utm_medium' => trim((string)($row['utm_medium'] ?? '')),
            'utm_campaign' => trim((string)($row['utm_campaign'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *   session_id: string,
     *   channel_code: string,
     *   channel_name: string,
     *   traffic_type: string,
     *   utm_source: string,
     *   utm_medium: string,
     *   utm_campaign: string
     * }
     */
    public function resolveFromBrowserAndUrl(array $row): array
    {
        $browser = $this->decodeBrowserInfo($row[Pixel::schema_fields_BROWSER_INFO] ?? $row['browser_info'] ?? null);
        $additional = \is_array($browser['additionalInfo'] ?? null) ? $browser['additionalInfo'] : [];
        $url = (string)($row[Pixel::schema_fields_URL] ?? $row['url'] ?? '');
        $referer = (string)($row[Pixel::schema_fields_REFERER] ?? $row['referer'] ?? '');
        $sticky = $this->extractSticky($browser, $additional);

        // browser_info.additionalInfo.utm 作为弱 sticky（历史行常见）
        if ($sticky === null) {
            $utm = \is_array($additional['utm'] ?? null) ? $additional['utm'] : [];
            if ($utm !== []) {
                $sticky = [
                    'utm_source' => $utm['source'] ?? $utm['utm_source'] ?? '',
                    'utm_medium' => $utm['medium'] ?? $utm['utm_medium'] ?? '',
                    'utm_campaign' => $utm['campaign'] ?? $utm['utm_campaign'] ?? '',
                    'utm_content' => $utm['content'] ?? $utm['utm_content'] ?? '',
                    'utm_term' => $utm['term'] ?? $utm['utm_term'] ?? '',
                    'wch' => $utm['wch'] ?? $utm['channel_code'] ?? '',
                ];
            }
        }

        $attribution = $this->attribution()->resolve([
            'url' => $url,
            'referer' => $referer,
            'sticky' => $sticky,
        ]);

        return [
            'session_id' => $this->sessionFromBrowser($row, $browser, $additional),
            'channel_code' => substr((string)($attribution['channel_code'] ?? ''), 0, 64),
            'channel_name' => substr((string)($attribution['channel_name'] ?? ''), 0, 255),
            'traffic_type' => substr((string)($attribution['traffic_type'] ?? ''), 0, 32),
            'utm_source' => substr((string)($attribution['utm_source'] ?? ''), 0, 255),
            'utm_medium' => substr((string)($attribution['utm_medium'] ?? ''), 0, 255),
            'utm_campaign' => substr((string)($attribution['utm_campaign'] ?? ''), 0, 255),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $browser
     * @param array<string, mixed>|null $additional
     */
    public function sessionFromBrowser(array $row, ?array $browser = null, ?array $additional = null): string
    {
        if ($browser === null) {
            $browser = $this->decodeBrowserInfo($row[Pixel::schema_fields_BROWSER_INFO] ?? $row['browser_info'] ?? null);
        }
        if ($additional === null) {
            $additional = \is_array($browser['additionalInfo'] ?? null) ? $browser['additionalInfo'] : [];
        }

        foreach ([
            (string)($browser['session_id'] ?? ''),
            (string)($additional['environment']['session_id'] ?? ''),
            (string)($additional['funnel']['session_id'] ?? ''),
        ] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '') {
                return substr($candidate, 0, 64);
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $pack
     */
    private function packHasStrongMarketingSignals(array $pack): bool
    {
        // traffic_type  alone 可由 referer 推断，不算「有营销回退」
        return trim((string)($pack['channel_code'] ?? '')) !== ''
            || trim((string)($pack['utm_source'] ?? '')) !== ''
            || trim((string)($pack['utm_medium'] ?? '')) !== ''
            || trim((string)($pack['utm_campaign'] ?? '')) !== '';
    }

    /**
     * @param mixed $raw
     * @return array<string, mixed>
     */
    private function decodeBrowserInfo(mixed $raw): array
    {
        if (\is_array($raw)) {
            return $raw;
        }
        if (!\is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $browser
     * @param array<string, mixed> $additional
     * @return array<string, mixed>|null
     */
    private function extractSticky(array $browser, array $additional): ?array
    {
        $attribution = \is_array($additional['attribution'] ?? null) ? $additional['attribution'] : [];
        foreach ([
            $browser['sticky'] ?? null,
            $additional['sticky'] ?? null,
            $additional['sticky_utm'] ?? null,
            $attribution['sticky'] ?? null,
        ] as $candidate) {
            if (\is_array($candidate) && $candidate !== []) {
                return $candidate;
            }
        }

        return null;
    }

    private function hostFromUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $host = parse_url($url, PHP_URL_HOST);

        return \is_string($host) ? strtolower($host) : '';
    }

    private function attribution(): PixelTrafficAttributionService
    {
        if (!$this->attribution) {
            /** @var PixelTrafficAttributionService $service */
            $service = ObjectManager::getInstance(PixelTrafficAttributionService::class);
            $this->attribution = $service;
        }

        return $this->attribution;
    }
}
