<?php

declare(strict_types=1);

namespace WeShop\Analytics\Provider;

use WeShop\Analytics\Interface\PixelProviderInterface;
use WeShop\Analytics\Service\AnalyticsConfigService;
use Weline\Framework\App\Env;

class BingAds implements PixelProviderInterface
{
    public function __construct(
        private readonly ?string $uetTagId = null,
        private readonly ?string $apiToken = null,
        private readonly ?bool $enabled = null,
        private readonly ?AnalyticsConfigService $analyticsConfigService = null
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->readEnabled() && $this->readUetTagId() !== '';
    }

    public function sendEvent(string $eventName, array $eventData): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $apiToken = $this->readApiToken();
        if ($apiToken === '') {
            return false;
        }

        $payload = [
            'data' => [
                $this->buildEventPayload($eventName, $eventData),
            ],
        ];

        $endpoint = 'https://capi.uet.microsoft.com/v1/' . rawurlencode($this->readUetTagId()) . '/events';

        return $this->postJson($endpoint, $payload, ['Authorization: Bearer ' . $apiToken]);
    }

    public function getPixelCode(): string
    {
        if (!$this->isEnabled()) {
            return '';
        }

        $uetTagId = $this->sanitizeSnippetToken($this->readUetTagId());
        if ($uetTagId === '') {
            return '';
        }

        return $this->buildHeadSnippet($uetTagId);
    }

    /**
     * @return array{head:string,body:string,footer:string}
     */
    public function getPixelHookSnippets(): array
    {
        if (!$this->isEnabled()) {
            return [
                'head' => '',
                'body' => '',
                'footer' => '',
            ];
        }

        $uetTagId = $this->sanitizeSnippetToken($this->readUetTagId());
        if ($uetTagId === '') {
            return [
                'head' => '',
                'body' => '',
                'footer' => '',
            ];
        }

        return [
            'head' => $this->buildHeadSnippet($uetTagId),
            'body' => '',
            'footer' => '',
        ];
    }

    private function buildHeadSnippet(string $uetTagId): string
    {
        return <<<HTML
<!-- Microsoft Advertising UET -->
<script>
(function (w, d, t, r, u) {
  var f, n, i;
  w[u] = w[u] || [];
  f = function () {
    var config = { ti: "{$uetTagId}" };
    config.q = w[u];
    w[u] = new UET(config);
    w[u].push('pageLoad');
  };
  n = d.createElement(t);
  n.src = r;
  n.async = 1;
  n.onload = n.onreadystatechange = function () {
    var ready = this.readyState;
    if (!ready || ready === 'loaded' || ready === 'complete') {
      f();
      n.onload = n.onreadystatechange = null;
    }
  };
  i = d.getElementsByTagName(t)[0];
  i.parentNode.insertBefore(n, i);
}(window, document, 'script', 'https://bat.bing.com/bat.js', 'uetq'));
</script>
HTML;
    }

    private function readEnabled(): bool
    {
        return $this->enabled ?? (bool) $this->getConfigValue('enabled', false);
    }

    private function readUetTagId(): string
    {
        return trim((string) ($this->uetTagId ?? $this->getConfigValue('uet_tag_id', '')));
    }

    private function readApiToken(): string
    {
        return trim((string) ($this->apiToken ?? $this->getConfigValue('api_token', '')));
    }

    private function sanitizeSnippetToken(string $value): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9._-]/', '', $value);
        return trim((string) $sanitized);
    }

    /**
     * @param array<string, mixed> $eventData
     * @return array<string, mixed>
     */
    private function buildEventPayload(string $eventName, array $eventData): array
    {
        $normalizedEvent = strtolower(trim($eventName));
        $eventId = trim((string) ($eventData['event_id'] ?? ''));
        if ($eventId === '') {
            $eventId = 'weshop_' . md5((string) microtime(true) . random_int(1, PHP_INT_MAX));
        }

        $sourceUrl = trim((string) ($eventData['event_source_url'] ?? ''));
        $anonymousId = trim((string) ($eventData['anonymous_id'] ?? ''));
        if ($anonymousId === '') {
            $anonymousId = md5(
                (string) ($eventData['ip'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1')
                . '|'
                . (string) ($eventData['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? 'cli')
            );
        }

        $payload = [
            'eventType' => $normalizedEvent === 'page_view' ? 'pageLoad' : 'custom',
            'eventId' => $eventId,
            'eventTime' => (int) ($eventData['event_time'] ?? time()),
            'eventSource' => 'web',
            'eventSourceUrl' => $sourceUrl,
            'userData' => array_filter([
                'anonymousId' => $anonymousId,
                'clientUserAgent' => (string) ($eventData['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? 'cli'),
                'clientIpAddress' => (string) ($eventData['ip'] ?? $eventData['client_ip'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
                'email' => $this->normalizeHash((string) ($eventData['email'] ?? '')),
                'phone' => $this->normalizeHash((string) ($eventData['phone'] ?? $eventData['phone_number'] ?? '')),
                'externalId' => $this->normalizeHash((string) ($eventData['customer_id'] ?? $eventData['user_id'] ?? '')),
                'msclkid' => trim((string) ($eventData['msclkid'] ?? '')),
            ], static fn(mixed $value): bool => is_string($value) ? trim($value) !== '' : $value !== null),
        ];

        if ($payload['eventType'] === 'custom') {
            $payload['eventName'] = $normalizedEvent;
            $payload['customData'] = $this->buildCustomData($eventData);
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $eventData
     * @return array<string, mixed>
     */
    private function buildCustomData(array $eventData): array
    {
        $customData = [
            'currency' => (string) ($eventData['currency'] ?? 'USD'),
            'value' => (float) ($eventData['value'] ?? 0),
        ];

        $transactionId = trim((string) ($eventData['transaction_id'] ?? ''));
        if ($transactionId !== '') {
            $customData['transactionId'] = $transactionId;
        }

        $items = is_array($eventData['items'] ?? null) ? $eventData['items'] : [];
        if ($items !== []) {
            $customData['items'] = array_values(array_filter(array_map(function (mixed $item): array {
                if (!is_array($item)) {
                    return [];
                }

                $itemId = (string) ($item['item_id'] ?? $item['product_id'] ?? '');
                if ($itemId === '') {
                    return [];
                }

                return [
                    'id' => $itemId,
                    'name' => (string) ($item['name'] ?? ''),
                    'quantity' => (int) ($item['quantity'] ?? $item['qty'] ?? 1),
                    'price' => (float) ($item['price'] ?? 0),
                ];
            }, $items), static fn(array $item): bool => $item !== []));
        }

        return $customData;
    }

    private function normalizeHash(string $value): string
    {
        $value = trim(strtolower($value));
        if ($value === '') {
            return '';
        }

        if (preg_match('/^[a-f0-9]{64}$/', $value) === 1) {
            return $value;
        }

        return hash('sha256', $value);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $headers
     */
    protected function postJson(string $url, array $payload, array $headers = []): bool
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(['Content-Type: application/json'], $headers));
        $response = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hasError = $response === false || curl_errno($ch) !== 0;
        curl_close($ch);

        if ($hasError) {
            return false;
        }

        return $statusCode >= 200 && $statusCode < 300;
    }

    private function getConfigValue(string $field, mixed $default): mixed
    {
        if ($this->analyticsConfigService) {
            $config = $this->analyticsConfigService->getProviderConfig(AnalyticsConfigService::PROVIDER_BING);
            if (array_key_exists($field, $config)) {
                return $config[$field];
            }
        }

        return Env::getInstance()->getConfig('analytics.bing.' . $field, $default);
    }
}
