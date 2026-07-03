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
        private ?PixelHotBufferService $hotBufferService = null
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

        return [
            'schema' => $this->truncateScalar($info['schema'] ?? 'weline_behavior_timing_v1', 64),
            'time' => \is_array($info['time'] ?? null) ? $this->compactLooseArray($info['time'], 2) : [],
            'performance' => \is_array($info['performance'] ?? null) ? $this->compactPerformance($info['performance']) : [],
            'funnel' => \is_array($info['funnel'] ?? null) ? $this->compactFunnel($info['funnel']) : [],
            'navigation' => [
                'current_url' => $this->truncateScalar($navigation['current_url'] ?? '', 512),
                'current_path' => $this->truncateScalar($navigation['current_path'] ?? '', 160),
                'current_search' => $this->truncateScalar($navigation['current_search'] ?? '', 160),
                'current_hash' => $this->truncateScalar($navigation['current_hash'] ?? '', 80),
                'referrer' => $this->truncateScalar($navigation['referrer'] ?? '', 512),
                'last_location' => $this->truncateScalar($navigation['last_location'] ?? '', 512),
            ],
            'viewport' => \is_array($info['viewport'] ?? null) ? $this->compactLooseArray($info['viewport'], 1) : [],
            'meta' => \is_array($info['meta'] ?? null) ? $this->compactLooseArray($info['meta'], 3) : [],
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
        $data = [
            'url' => (string)$this->truncateScalar($url, 255),
            'module' => substr((string)($post['module'] ?? ''), 0, 255),
            'name' => substr((string)($post['name'] ?? ''), 0, 255),
            'event' => substr((string)($post['eventName'] ?? $post['event'] ?? 'click'), 0, 255),
            'value' => max(0, (int)($post['value'] ?? 0)),
            'lang' => substr((string)($post['userLang'] ?? $post['lang'] ?? ''), 0, 255),
            'currency' => substr((string)($post['currency'] ?? ''), 0, 255),
            'website_id' => max(0, $websiteId),
            'referer' => substr((string)($post['referer'] ?? ''), 0, 255),
            'user_id' => max(0, (int)($post['userId'] ?? 0)),
            'user_agent' => substr((string)($post['userAgent'] ?? ''), 0, 255),
            'ip' => (string)$ip,
            'browser_info' => json_encode([
                'additionalInfo' => is_array($post['additionalInfo'] ?? null) ? $post['additionalInfo'] : [],
                'screen' => is_array($post['screen'] ?? null) ? $post['screen'] : [],
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ];

        return [
            'post' => $post,
            'data' => $data,
            'event_id' => $eventId,
            'received_at' => \time(),
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
}
