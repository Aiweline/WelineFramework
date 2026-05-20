<?php
declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\App\Exception;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Model\Pixel;
use Weline\Visitor\Model\PixelAdditional;

class PixelEventService
{
    private const PASSIVE_EVENTS_WITH_BROWSER_INFO = [
        'page_view' => true,
        'page_load' => true,
        'homepage' => true,
        'blog' => true,
        'category' => true,
        'search_result_view' => true,
    ];

    public function __construct(
        private readonly Request $request
    ) {
    }

    /**
     * @param array<string, mixed> $post
     */
    private function shouldPersistAdditional(array $post): bool
    {
        foreach (['testId', 'variant', 'test_id', 'testVariant', 'items', 'product_id', 'order_id', 'transaction_id'] as $key) {
            if (isset($post[$key]) && $post[$key] !== '' && $post[$key] !== []) {
                return true;
            }
        }

        $event = (string)($post['eventName'] ?? $post['event'] ?? '');
        if (\str_starts_with($event, 'account_')) {
            return false;
        }

        return !isset(self::PASSIVE_EVENTS_WITH_BROWSER_INFO[$event]);
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

        $data = [
            'url' => $url,
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

        /** @var Pixel $pixel */
        $pixel = ObjectManager::make(Pixel::class);
        try {
            $pixel->save($data);
        } catch (Exception $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        $pixelId = $pixel->getId();
        $pixelAdditionalId = null;
        $additionalData = $post;

        if ($pixelId && $this->shouldPersistAdditional($post)) {
            try {
                $this->normalizeAbTestFields($post, $additionalData);

                /** @var PixelAdditional $pixelAdditional */
                $pixelAdditional = ObjectManager::make(PixelAdditional::class);
                $pixelAdditional->setPixelId((int)$pixelId)
                    ->setTotalEventData(json_encode($additionalData, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}')
                    ->save();

                $pixelAdditionalId = $pixelAdditional->getId() ?: null;
            } catch (Exception $e) {
                w_log_error('Pixel Additional Save Error: ' . $e->getMessage());
            }
        }

        $responseData = [
            'pixel_id' => $pixelId,
            'pixel_additional_id' => $pixelAdditionalId,
        ];

        if (isset($additionalData['testId']) || isset($additionalData['variant'])) {
            $responseData['ab_test'] = [
                'testId' => $additionalData['testId'] ?? null,
                'variant' => $additionalData['variant'] ?? null,
            ];
        }

        return [
            'success' => true,
            'error' => false,
            'code' => 200,
            'msg' => (string)__('请求成功！'),
            'message' => (string)__('请求成功！'),
            'data' => $responseData,
        ];
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
     * @param array<string, mixed> $additionalData
     */
    private function normalizeAbTestFields(array $post, array &$additionalData): void
    {
        if (!isset($post['testId']) && !isset($post['variant']) && !isset($post['test_id']) && !isset($post['testVariant'])) {
            return;
        }
        if (isset($post['test_id']) && !isset($additionalData['testId'])) {
            $additionalData['testId'] = substr((string)$post['test_id'], 0, 255);
        } elseif (isset($post['testId'])) {
            $additionalData['testId'] = substr((string)$post['testId'], 0, 255);
        }

        if (isset($post['testVariant']) && !isset($additionalData['variant'])) {
            $additionalData['variant'] = substr((string)$post['testVariant'], 0, 10);
        } elseif (isset($post['variant'])) {
            $additionalData['variant'] = substr((string)$post['variant'], 0, 10);
        }
    }
}
