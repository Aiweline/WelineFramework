<?php
declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

class PixelEventService
{
    public function __construct(
        private readonly Request $request,
        private ?PixelEventPersistenceService $persistenceService = null,
        private ?PixelHotBufferService $hotBufferService = null,
        private ?PixelTrafficAttributionService $attributionService = null,
        private ?PixelSessionFirstTouchBackfillService $sessionFirstTouchBackfillService = null,
        private ?PixelChannelLookupService $channelLookupService = null
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function compactPayload(array $payload): array
    {
        foreach (['url', 'referrer', 'referer', 'userAgent'] as $key) {
            if (isset($payload[$key])) {
                $payload[$key] = $this->truncateScalar($payload[$key], 512);
            }
        }
        foreach (['module', 'name', 'eventName', 'event'] as $key) {
            if (isset($payload[$key])) {
                $payload[$key] = $this->truncateScalar($payload[$key], 128);
            }
        }
        foreach (['userLang', 'lang', 'currency'] as $key) {
            if (isset($payload[$key])) {
                $payload[$key] = $this->truncateScalar($payload[$key], 64);
            }
        }

        if (isset($payload['elementInfo']) && \is_array($payload['elementInfo'])) {
            $payload['elementInfo'] = $this->compactElementInfo($payload['elementInfo']);
        }
        if (isset($payload['additionalInfo']) && \is_array($payload['additionalInfo'])) {
            $payload['additionalInfo'] = $this->compactAdditionalInfo($payload['additionalInfo']);
        }
        if (isset($payload['screen']) && \is_array($payload['screen'])) {
            $payload['screen'] = $this->compactLooseArray($payload['screen'], 1);
        }
        if (isset($payload['sticky']) && \is_array($payload['sticky'])) {
            $payload['sticky'] = $this->compactStickyPack($payload['sticky']) ?? [];
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $elementInfo
     * @return array<string, mixed>
     */
    private function compactElementInfo(array $elementInfo): array
    {
        return [
            'tagName' => $this->truncateScalar($elementInfo['tagName'] ?? '', 32),
            'className' => $this->truncateScalar($elementInfo['className'] ?? '', 120),
            'id' => $this->truncateScalar($elementInfo['id'] ?? '', 80),
            'name' => $this->truncateScalar($elementInfo['name'] ?? '', 80),
            'type' => $this->truncateScalar($elementInfo['type'] ?? '', 32),
            'href' => $this->truncateScalar($elementInfo['href'] ?? '', 255),
            'text' => $this->truncateScalar($elementInfo['text'] ?? '', 120),
            'eventType' => $this->truncateScalar($elementInfo['eventType'] ?? '', 32),
        ];
    }

    /**
     * @param array<string, mixed> $info
     * @return array<string, mixed>
     */
    private function compactAdditionalInfo(array $info): array
    {
        $navigation = \is_array($info['navigation'] ?? null) ? $info['navigation'] : [];
        $environment = \is_array($info['environment'] ?? null) ? $info['environment'] : [];
        $device = \is_array($info['device'] ?? null) ? $info['device'] : [];
        $utm = \is_array($info['utm'] ?? null) ? $info['utm'] : [];
        $engagement = \is_array($info['engagement'] ?? null) ? $info['engagement'] : [];
        $meta = \is_array($info['meta'] ?? null) ? $info['meta'] : [];
        $source = \is_array($info['source'] ?? null) ? $info['source'] : [];

        return [
            'schema' => $this->truncateScalar($info['schema'] ?? 'weline_behavior_timing_v2', 64),
            'time' => \is_array($info['time'] ?? null) ? $this->compactLooseArray($info['time'], 2) : [],
            'performance' => \is_array($info['performance'] ?? null) ? $this->compactPerformance($info['performance']) : [],
            'funnel' => \is_array($info['funnel'] ?? null) ? $this->compactFunnel($info['funnel']) : [],
            'environment' => [
                'page_location' => $this->truncateScalar($environment['page_location'] ?? '', 512),
                'page_path' => $this->truncateScalar($environment['page_path'] ?? '', 160),
                'page_title' => $this->truncateScalar($environment['page_title'] ?? '', 160),
                'page_referrer' => $this->truncateScalar($environment['page_referrer'] ?? '', 512),
                'page_hostname' => $this->truncateScalar($environment['page_hostname'] ?? '', 120),
                'page_search' => $this->truncateScalar($environment['page_search'] ?? '', 160),
                'website_id' => $this->truncateScalar($environment['website_id'] ?? '', 32),
                'website_code' => $this->truncateScalar($environment['website_code'] ?? '', 64),
                'language' => $this->truncateScalar($environment['language'] ?? '', 64),
                'currency' => $this->truncateScalar($environment['currency'] ?? '', 16),
                'session_id' => $this->truncateScalar($environment['session_id'] ?? '', 64),
                'page_id' => $this->truncateScalar($environment['page_id'] ?? '', 48),
                'engagement_target' => $this->truncateScalar($environment['engagement_target'] ?? '', 64),
            ],
            'navigation' => [
                'current_url' => $this->truncateScalar($navigation['current_url'] ?? '', 512),
                'current_path' => $this->truncateScalar($navigation['current_path'] ?? '', 160),
                'current_search' => $this->truncateScalar($navigation['current_search'] ?? '', 160),
                'current_hash' => $this->truncateScalar($navigation['current_hash'] ?? '', 80),
                'referrer' => $this->truncateScalar($navigation['referrer'] ?? '', 512),
                'last_location' => $this->truncateScalar($navigation['last_location'] ?? '', 512),
                'website_id' => $this->truncateScalar($navigation['website_id'] ?? '', 32),
                'website_code' => $this->truncateScalar($navigation['website_code'] ?? '', 64),
                'language' => $this->truncateScalar($navigation['language'] ?? '', 64),
            ],
            'device' => [
                'category' => $this->truncateScalar($device['category'] ?? '', 32),
                'platform' => $this->truncateScalar($device['platform'] ?? '', 64),
                'language' => $this->truncateScalar($device['language'] ?? '', 32),
                'screen_width' => (int)($device['screen_width'] ?? 0),
                'screen_height' => (int)($device['screen_height'] ?? 0),
                'color_depth' => (int)($device['color_depth'] ?? 0),
                'timezone_offset' => (int)($device['timezone_offset'] ?? 0),
                'touch' => !empty($device['touch']),
            ],
            'utm' => [
                'source' => $this->truncateScalar($utm['source'] ?? '', 64),
                'medium' => $this->truncateScalar($utm['medium'] ?? '', 64),
                'campaign' => $this->truncateScalar($utm['campaign'] ?? '', 120),
                'content' => $this->truncateScalar($utm['content'] ?? '', 120),
                'term' => $this->truncateScalar($utm['term'] ?? '', 120),
                'gclid' => $this->truncateScalar($utm['gclid'] ?? '', 120),
                'fbclid' => $this->truncateScalar($utm['fbclid'] ?? '', 120),
            ],
            'sticky' => $this->compactStickyPack(\is_array($info['sticky'] ?? null) ? $info['sticky'] : null),
            'engagement' => [
                'engaged' => !empty($engagement['engaged']),
                'dwell_ms' => (int)($engagement['dwell_ms'] ?? $meta['duration_ms'] ?? 0),
                'page_elapsed_ms' => (int)($engagement['page_elapsed_ms'] ?? 0),
            ],
            'source' => [
                'section_code' => $this->truncateScalar($source['section_code'] ?? '', 128),
                'section_event_key' => $this->truncateScalar($source['section_event_key'] ?? '', 160),
                'section_source_status' => $this->truncateScalar($source['section_source_status'] ?? 'n/a', 32),
            ],
            'viewport' => \is_array($info['viewport'] ?? null) ? $this->compactLooseArray($info['viewport'], 1) : [],
            'meta' => $this->compactLooseArray($meta, 3),
            // F03：电商 items 保留在 additionalInfo，供商品表现展开
            'ecommerce' => $this->compactEcommerce(
                \is_array($info['ecommerce'] ?? null) ? $info['ecommerce'] : [],
                $info,
                $meta
            ),
        ];
    }

    /**
     * F03：压缩电商 items（最多 20 行；字段对齐 GA4 item）。
     *
     * @param array<string, mixed> $ecommerce
     * @param array<string, mixed> $info
     * @param array<string, mixed> $meta
     * @return array{
     *   items: list<array<string, mixed>>,
     *   item_id: string,
     *   product_id: string,
     *   sku: string,
     *   transaction_id: string
     * }
     */
    private function compactEcommerce(array $ecommerce, array $info = [], array $meta = []): array
    {
        $rawItems = $ecommerce['items']
            ?? $info['items']
            ?? $meta['items']
            ?? [];
        if (!\is_array($rawItems)) {
            $rawItems = [];
        }

        $items = [];
        foreach (\array_slice($rawItems, 0, 20) as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $itemId = $this->truncateScalar(
                $entry['item_id'] ?? $entry['product_id'] ?? $entry['sku'] ?? '',
                120
            );
            $name = $this->truncateScalar(
                $entry['item_name'] ?? $entry['name'] ?? $entry['content_name'] ?? '',
                160
            );
            if ($itemId === '' && $name === '') {
                continue;
            }
            $qty = (float)($entry['quantity'] ?? $entry['qty'] ?? 1);
            if ($qty <= 0) {
                $qty = 1.0;
            }
            $price = isset($entry['price']) ? (float)$entry['price'] : null;
            $items[] = [
                'item_id' => (string)$itemId,
                'product_id' => (string)$this->truncateScalar($entry['product_id'] ?? $itemId, 120),
                'sku' => (string)$this->truncateScalar($entry['sku'] ?? '', 120),
                'item_name' => (string)$name,
                'price' => $price,
                'quantity' => $qty,
            ];
        }

        return [
            'items' => $items,
            'item_id' => (string)$this->truncateScalar(
                $ecommerce['item_id'] ?? $info['item_id'] ?? $meta['item_id'] ?? ($items[0]['item_id'] ?? ''),
                120
            ),
            'product_id' => (string)$this->truncateScalar(
                $ecommerce['product_id'] ?? $info['product_id'] ?? $meta['product_id'] ?? ($items[0]['product_id'] ?? ''),
                120
            ),
            'sku' => (string)$this->truncateScalar(
                $ecommerce['sku'] ?? $info['sku'] ?? $meta['sku'] ?? ($items[0]['sku'] ?? ''),
                120
            ),
            'transaction_id' => (string)$this->truncateScalar(
                $ecommerce['transaction_id'] ?? $info['transaction_id'] ?? $meta['transaction_id'] ?? '',
                120
            ),
        ];
    }

    /**
     * @param array<string, mixed> $performance
     * @return array<string, mixed>
     */
    private function compactPerformance(array $performance): array
    {
        $resourceSummary = \is_array($performance['resource_summary'] ?? null) ? $performance['resource_summary'] : [];
        $slowest = \is_array($resourceSummary['slowest'] ?? null) ? \array_slice($resourceSummary['slowest'], 0, 3) : [];
        $slowest = \array_map(function (mixed $entry): array {
            $entry = \is_array($entry) ? $entry : [];
            return [
                'name' => $this->truncateScalar($entry['name'] ?? '', 96),
                'initiator_type' => $this->truncateScalar($entry['initiator_type'] ?? '', 32),
                'duration_ms' => (int)($entry['duration_ms'] ?? 0),
                'transfer_size' => (int)($entry['transfer_size'] ?? 0),
            ];
        }, $slowest);

        return [
            'page_started_at_ms' => (int)($performance['page_started_at_ms'] ?? 0),
            'page_age_ms' => (int)($performance['page_age_ms'] ?? 0),
            'perf_now_ms' => isset($performance['perf_now_ms']) ? (int)$performance['perf_now_ms'] : null,
            'time_origin_ms' => isset($performance['time_origin_ms']) ? (int)$performance['time_origin_ms'] : null,
            'navigation' => \is_array($performance['navigation'] ?? null) ? $this->compactLooseArray($performance['navigation'], 2) : null,
            'paint' => \is_array($performance['paint'] ?? null) ? $this->compactLooseArray($performance['paint'], 1) : [],
            'resource_summary' => [
                'count' => (int)($resourceSummary['count'] ?? 0),
                'slowest' => $slowest,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $funnel
     * @return array<string, mixed>
     */
    private function compactFunnel(array $funnel): array
    {
        $chain = \is_array($funnel['chain'] ?? null) ? \array_slice($funnel['chain'], -8) : [];
        $chain = \array_map(function (mixed $item): array {
            $item = \is_array($item) ? $item : [];
            return [
                'event' => $this->truncateScalar($item['event'] ?? '', 64),
                'step' => (int)($item['step'] ?? 0),
                'path' => $this->truncateScalar($item['path'] ?? '', 160),
                'page_id' => $this->truncateScalar($item['page_id'] ?? '', 48),
                'timestamp_ms' => (int)($item['timestamp_ms'] ?? 0),
                'since_previous_ms' => isset($item['since_previous_ms']) ? (int)$item['since_previous_ms'] : null,
            ];
        }, $chain);

        return [
            'session_id' => $this->truncateScalar($funnel['session_id'] ?? '', 64),
            'page_id' => $this->truncateScalar($funnel['page_id'] ?? '', 48),
            'step' => (int)($funnel['step'] ?? 0),
            'step_index' => (int)($funnel['step_index'] ?? 0),
            'previous_event' => $this->truncateScalar($funnel['previous_event'] ?? '', 64),
            'since_previous_ms' => isset($funnel['since_previous_ms']) ? (int)$funnel['since_previous_ms'] : null,
            'chain' => $chain,
        ];
    }

    /**
     * @param array<string, mixed>|null $sticky
     * @return array<string, mixed>|null
     */
    private function compactStickyPack(?array $sticky): ?array
    {
        if ($sticky === null || $sticky === []) {
            return null;
        }

        $pack = [
            'wch' => $this->truncateScalar($sticky['wch'] ?? $sticky['channel_code'] ?? '', 64),
            'utm_source' => $this->truncateScalar($sticky['utm_source'] ?? $sticky['source'] ?? '', 255),
            'utm_medium' => $this->truncateScalar($sticky['utm_medium'] ?? $sticky['medium'] ?? '', 255),
            'utm_campaign' => $this->truncateScalar($sticky['utm_campaign'] ?? $sticky['campaign'] ?? '', 255),
            'utm_content' => $this->truncateScalar($sticky['utm_content'] ?? $sticky['content'] ?? '', 255),
            'utm_term' => $this->truncateScalar($sticky['utm_term'] ?? $sticky['term'] ?? '', 255),
            'gclid' => $this->truncateScalar($sticky['gclid'] ?? '', 255),
            'fbclid' => $this->truncateScalar($sticky['fbclid'] ?? '', 255),
            'msclkid' => $this->truncateScalar($sticky['msclkid'] ?? '', 255),
            'locked_at' => (int)($sticky['locked_at'] ?? 0),
            'locked_at_iso' => $this->truncateScalar($sticky['locked_at_iso'] ?? '', 64),
        ];

        foreach (['wch', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'gclid', 'fbclid', 'msclkid'] as $key) {
            if ($pack[$key] !== '') {
                return $pack;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private function compactLooseArray(array $data, int $depth): array
    {
        if ($depth <= 0) {
            return [];
        }

        $result = [];
        $count = 0;
        foreach ($data as $key => $value) {
            if (++$count > 32) {
                break;
            }

            if (\is_array($value)) {
                $result[$key] = $this->compactLooseArray($value, $depth - 1);
                continue;
            }

            $result[$key] = \is_scalar($value) || $value === null
                ? $this->truncateScalar($value, 512)
                : null;
        }

        return $result;
    }

    private function truncateScalar(mixed $value, int $length): string|int|float|bool|null
    {
        if ($value === null || \is_int($value) || \is_float($value) || \is_bool($value)) {
            return $value;
        }

        return \mb_substr((string)$value, 0, $length);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function track(array $payload): array
    {
        $prepared = $this->prepare($payload);
        $buffer = $this->hotBuffer()->buffer($prepared);
        if ($buffer) {
            return $this->successResponse([
                'pixel_id' => null,
                'pixel_additional_id' => null,
                'buffered' => true,
                'event_id' => $prepared['event_id'],
                'event' => $prepared['data']['event'] ?? '',
                'hot_buffer' => $buffer,
            ]);
        }

        $responseData = $this->persistence()->persistPrepared($prepared['post'], $prepared['data']);
        $responseData['buffered'] = false;
        $responseData['event_id'] = $prepared['event_id'];

        return $this->successResponse($responseData);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{post: array<string, mixed>, data: array<string, mixed>, event_id: string, received_at: int}
     */
    private function prepare(array $payload): array
    {
        $post = $this->compactPayload($this->normalizePayload($payload));
        $post['source'] = $post['source'] ?? 'worker';

        $ip = $post['ip'] ?? $this->request->clientIP();
        if (!empty($ip) && !filter_var((string)$ip, FILTER_VALIDATE_IP)) {
            $ip = $this->request->clientIP();
        }

        $websiteId = $this->resolveWebsiteId($post);
        if (empty($post['eventName']) && empty($post['event'])) {
            $post['eventName'] = 'click';
        }

        $url = (string)($post['url'] ?? '');
        if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
            if (!str_starts_with($url, 'http') && !str_starts_with($url, '//')) {
                $url = '';
            }
        }

        $eventId = $this->resolveEventId($post);
        $post['event_id'] = $eventId;
        if (isset($post['additionalInfo']) && \is_array($post['additionalInfo'])) {
            $post['additionalInfo'] = $this->enrichAdditionalInfo($post);
        }
        $additional = \is_array($post['additionalInfo'] ?? null) ? $post['additionalInfo'] : [];
        $screen = \is_array($post['screen'] ?? null) ? $post['screen'] : [];
        if ($screen === [] && isset($additional['device']) && \is_array($additional['device'])) {
            $screen = [
                'width' => (int)($additional['device']['screen_width'] ?? 0),
                'height' => (int)($additional['device']['screen_height'] ?? 0),
            ];
        }

        $referer = substr((string)($post['referer'] ?? $post['referrer'] ?? ''), 0, 255);
        $sessionId = (string)$this->truncateScalar(
            $this->firstNonEmptyString(
                (string)($additional['environment']['session_id'] ?? ''),
                (string)($additional['funnel']['session_id'] ?? ''),
                (string)($post['session_id'] ?? '')
            ),
            64
        );

        $data = [
            'url' => (string)$this->truncateScalar($url, 255),
            'module' => substr((string)($post['module'] ?? ''), 0, 255),
            'name' => substr((string)($post['name'] ?? ''), 0, 255),
            'event' => substr((string)($post['eventName'] ?? $post['event'] ?? 'click'), 0, 255),
            'value' => max(0, (int)($post['value'] ?? 0)),
            'lang' => substr((string)($post['userLang'] ?? $post['lang'] ?? ''), 0, 255),
            'currency' => substr($this->normalizeCurrency((string)($post['currency'] ?? '')), 0, 255),
            'website_id' => max(0, $websiteId),
            'referer' => $referer,
            'user_id' => max(0, (int)($post['userId'] ?? 0)),
            'user_agent' => substr((string)($post['userAgent'] ?? ''), 0, 255),
            'ip' => (string)$ip,
            'created_at' => $this->resolveCreatedAt($post),
            'session_id' => $sessionId,
            'browser_info' => json_encode([
                'schema' => 'weline_pixel_browser_v2',
                'additionalInfo' => $additional,
                'screen' => $this->compactLooseArray($screen, 1),
                'session_id' => $sessionId,
                'page_path' => $this->truncateScalar(
                    $additional['environment']['page_path']
                        ?? $additional['navigation']['current_path']
                        ?? (parse_url($url, PHP_URL_PATH) ?: '/'),
                    160
                ),
                'device_category' => $this->truncateScalar($additional['device']['category'] ?? '', 32),
                'dwell_ms' => (int)($additional['engagement']['dwell_ms'] ?? $additional['meta']['duration_ms'] ?? 0),
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ];

        $data = $this->hydratePreparedAttribution($post, $data);

        return [
            'post' => $post,
            'data' => $data,
            'event_id' => $eventId,
            'received_at' => \time(),
        ];
    }

    /**
     * prepare / 热缓冲 flush 共用：写入归因扁平列 + 同会话首触回填（A04/A05）。
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function hydratePreparedAttribution(array $post, array $data): array
    {
        $additional = \is_array($post['additionalInfo'] ?? null) ? $post['additionalInfo'] : [];
        $url = (string)($data['url'] ?? $post['url'] ?? '');
        $referer = (string)($data['referer'] ?? $post['referer'] ?? $post['referrer'] ?? '');

        if (trim((string)($data['session_id'] ?? '')) === '') {
            $data['session_id'] = (string)$this->truncateScalar(
                $this->firstNonEmptyString(
                    (string)($additional['environment']['session_id'] ?? ''),
                    (string)($additional['funnel']['session_id'] ?? ''),
                    (string)($post['session_id'] ?? '')
                ),
                64
            );
        }

        $attribution = $this->attribution()->resolve([
            'url' => $url,
            'referer' => $referer,
            'sticky' => $this->resolveStickyPack($post, $additional),
        ]);

        // B07 S2：查 campaign 写 code/name（A03 仍纯函数）；表缺失时降级为 S4「未登记」
        $websiteId = (int)($data['website_id'] ?? $post['websiteId'] ?? $post['website_id'] ?? 0);
        $attribution = $this->channelLookup()->applyCampaignBinding($attribution, $websiteId);
        // B09 S3：仍无 code 时按 rule 匹配 referer/utm/click_id
        $attribution = $this->channelLookup()->applyRuleBinding($attribution, $websiteId);

        $data['channel_code'] = substr((string)($attribution['channel_code'] ?? ''), 0, 64);
        $data['channel_name'] = substr((string)($attribution['channel_name'] ?? ''), 0, 255);
        $data['traffic_type'] = substr((string)($attribution['traffic_type'] ?? ''), 0, 32);
        $data['utm_source'] = substr((string)($attribution['utm_source'] ?? ''), 0, 255);
        $data['utm_medium'] = substr((string)($attribution['utm_medium'] ?? ''), 0, 255);
        $data['utm_campaign'] = substr((string)($attribution['utm_campaign'] ?? ''), 0, 255);

        return $this->sessionFirstTouch()->backfill($data);
    }

    /**
     * 读取客户端 sticky 营销包（A07 上报后生效；A04 仅消费，不查库）。
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $additional
     * @return array<string, mixed>|null
     */
    private function resolveStickyPack(array $post, array $additional): ?array
    {
        $attribution = \is_array($additional['attribution'] ?? null) ? $additional['attribution'] : [];
        foreach ([
            $post['sticky'] ?? null,
            $post['sticky_utm'] ?? null,
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

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function enrichAdditionalInfo(array $post): array
    {
        $info = \is_array($post['additionalInfo'] ?? null) ? $post['additionalInfo'] : [];
        $ua = (string)($post['userAgent'] ?? '');
        $device = \is_array($info['device'] ?? null) ? $info['device'] : [];
        if (($device['category'] ?? '') === '' && $ua !== '') {
            $device['category'] = $this->guessDeviceCategory($ua, $device);
        }
        if (($device['platform'] ?? '') === '' && $ua !== '') {
            $device['platform'] = $this->guessPlatform($ua);
        }
        $info['device'] = $device;

        if (!isset($info['utm']) || !\is_array($info['utm'])) {
            $info['utm'] = $this->extractUtmFromUrl((string)($post['url'] ?? ''));
        }

        $meta = \is_array($info['meta'] ?? null) ? $info['meta'] : [];
        $engagement = \is_array($info['engagement'] ?? null) ? $info['engagement'] : [];
        $dwell = (int)($engagement['dwell_ms'] ?? $meta['duration_ms'] ?? 0);
        $pageElapsed = (int)($engagement['page_elapsed_ms']
            ?? ($info['time']['page_elapsed_ms'] ?? 0)
            ?? ($info['performance']['page_age_ms'] ?? 0));
        $eventName = (string)($post['eventName'] ?? $post['event'] ?? '');
        $engagedEvents = ['cta_click', 'contact_click', 'lead_submit', 'add_to_cart', 'begin_checkout', 'purchase', 'search_submit', 'login', 'register'];
        $info['engagement'] = [
            'engaged' => !empty($engagement['engaged']) || \in_array($eventName, $engagedEvents, true) || $dwell >= 10000,
            'dwell_ms' => $dwell,
            'page_elapsed_ms' => $pageElapsed,
        ];

        $source = \is_array($info['source'] ?? null) ? $info['source'] : [];
        $info['source'] = [
            'section_code' => $this->truncateScalar(
                (string)($source['section_code'] ?? $post['section_code'] ?? ''),
                128
            ),
            'section_event_key' => $this->truncateScalar(
                (string)($source['section_event_key'] ?? $post['section_event_key'] ?? ''),
                160
            ),
            'section_source_status' => $this->truncateScalar(
                (string)($source['section_source_status'] ?? $post['section_source_status'] ?? 'n/a'),
                32
            ),
        ];

        // F03：顶层 / meta 的 items 并入 ecommerce，避免 compact 丢商品维
        $metaItems = \is_array($meta['items'] ?? null) ? $meta['items'] : [];
        $topItems = \is_array($post['items'] ?? null) ? $post['items'] : [];
        $existing = \is_array($info['ecommerce'] ?? null) ? $info['ecommerce'] : [];
        $existingItems = \is_array($existing['items'] ?? null) ? $existing['items'] : [];
        if ($existingItems === []) {
            $existingItems = $topItems !== [] ? $topItems : $metaItems;
        }
        $info['ecommerce'] = [
            'items' => $existingItems,
            'item_id' => $post['item_id'] ?? $existing['item_id'] ?? $meta['item_id'] ?? '',
            'product_id' => $post['product_id'] ?? $existing['product_id'] ?? $meta['product_id'] ?? '',
            'sku' => $post['sku'] ?? $existing['sku'] ?? $meta['sku'] ?? '',
            'transaction_id' => $post['transaction_id'] ?? $existing['transaction_id'] ?? $meta['transaction_id'] ?? '',
        ];

        return $this->compactAdditionalInfo($info);
    }

    /**
     * @param array<string, mixed> $device
     */
    private function guessDeviceCategory(string $ua, array $device): string
    {
        $uaLower = \strtolower($ua);
        if (\preg_match('/mobile|iphone|android(?!.*tablet)|windows phone/', $uaLower)) {
            return 'mobile';
        }
        if (\preg_match('/ipad|tablet|kindle|silk/', $uaLower)) {
            return 'tablet';
        }
        $width = (int)($device['screen_width'] ?? 0);
        if ($width > 0 && $width < 768) {
            return 'mobile';
        }
        if ($width >= 768 && $width < 1024) {
            return 'tablet';
        }
        return 'desktop';
    }

    private function guessPlatform(string $ua): string
    {
        $uaLower = \strtolower($ua);
        return match (true) {
            str_contains($uaLower, 'android') => 'Android',
            str_contains($uaLower, 'iphone'), str_contains($uaLower, 'ipad'), str_contains($uaLower, 'ios') => 'iOS',
            str_contains($uaLower, 'mac os'), str_contains($uaLower, 'macintosh') => 'macOS',
            str_contains($uaLower, 'windows') => 'Windows',
            str_contains($uaLower, 'linux') => 'Linux',
            default => 'Other',
        };
    }

    /**
     * @return array<string, string>
     */
    private function extractUtmFromUrl(string $url): array
    {
        $query = [];
        $parts = \parse_url($url);
        if (!empty($parts['query'])) {
            \parse_str((string)$parts['query'], $query);
        }
        return [
            'source' => (string)($query['utm_source'] ?? ''),
            'medium' => (string)($query['utm_medium'] ?? ''),
            'campaign' => (string)($query['utm_campaign'] ?? ''),
            'content' => (string)($query['utm_content'] ?? ''),
            'term' => (string)($query['utm_term'] ?? ''),
            'gclid' => (string)($query['gclid'] ?? ''),
            'fbclid' => (string)($query['fbclid'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $responseData
     * @return array<string, mixed>
     */
    private function successResponse(array $responseData): array
    {
        return [
            'success' => true,
            'error' => false,
            'code' => 200,
            'msg' => (string)__('请求成功！'),
            'message' => (string)__('请求成功！'),
            'data' => $responseData,
        ];
    }

    private function persistence(): PixelEventPersistenceService
    {
        if (!$this->persistenceService) {
            $this->persistenceService = ObjectManager::getInstance(PixelEventPersistenceService::class);
        }

        return $this->persistenceService;
    }

    private function hotBuffer(): PixelHotBufferService
    {
        if (!$this->hotBufferService) {
            $this->hotBufferService = ObjectManager::getInstance(PixelHotBufferService::class);
        }

        return $this->hotBufferService;
    }

    private function attribution(): PixelTrafficAttributionService
    {
        if (!$this->attributionService) {
            $this->attributionService = ObjectManager::getInstance(PixelTrafficAttributionService::class);
        }

        return $this->attributionService;
    }

    private function sessionFirstTouch(): PixelSessionFirstTouchBackfillService
    {
        if (!$this->sessionFirstTouchBackfillService) {
            $this->sessionFirstTouchBackfillService = ObjectManager::getInstance(PixelSessionFirstTouchBackfillService::class);
        }

        return $this->sessionFirstTouchBackfillService;
    }

    private function channelLookup(): PixelChannelLookupService
    {
        if (!$this->channelLookupService) {
            $this->channelLookupService = ObjectManager::getInstance(PixelChannelLookupService::class);
        }

        return $this->channelLookupService;
    }

    private function firstNonEmptyString(string ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload): array
    {
        if (isset($payload['encrypted'], $payload['version'])) {
            /** @var PixelEncryptionService $encryptionService */
            $encryptionService = ObjectManager::getInstance(PixelEncryptionService::class);
            $decoded = $encryptionService->decrypt($payload['encrypted'], $payload['version']);
            if (!is_array($decoded)) {
                throw new \InvalidArgumentException((string)__('解密后的数据格式错误'));
            }
            return $decoded;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $post
     */
    private function resolveWebsiteId(array $post): int
    {
        if (isset($post['websiteId']) && $post['websiteId'] !== '') {
            return (int)$post['websiteId'];
        }
        if (isset($post['siteId']) && $post['siteId'] !== '') {
            return (int)$post['siteId'];
        }

        return (int)(\Weline\Framework\Env\WelineEnv::getWebsiteId() ?? 0);
    }

    /**
     * @param array<string, mixed> $post
     */
    private function resolveEventId(array $post): string
    {
        $eventId = (string)($post['event_id'] ?? $post['eventId'] ?? '');
        if ($eventId !== '') {
            return \substr($eventId, 0, 80);
        }

        return 'wv-server-' . \substr(\sha1(\json_encode([$post, \microtime(true)], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: \uniqid('', true)), 0, 24);
    }

    private function normalizeCurrency(string $currency): string
    {
        $currency = \strtoupper(\trim($currency));
        if ($currency === 'RMB') {
            return 'CNY';
        }
        return $currency;
    }

    /**
     * @param array<string, mixed> $post
     */
    private function resolveCreatedAt(array $post): string
    {
        foreach (['created_at', 'createdAt', 'timestamp', 'event_time'] as $key) {
            $raw = trim((string)($post[$key] ?? ''));
            if ($raw === '') {
                continue;
            }
            if (ctype_digit($raw)) {
                $ts = (int)$raw;
                if ($ts > 2000000000000) {
                    $ts = (int)floor($ts / 1000);
                }
                if ($ts > 0) {
                    return date('Y-m-d H:i:s', $ts);
                }
                continue;
            }
            $ts = strtotime($raw);
            if ($ts !== false) {
                return date('Y-m-d H:i:s', $ts);
            }
        }

        return date('Y-m-d H:i:s');
    }
}
