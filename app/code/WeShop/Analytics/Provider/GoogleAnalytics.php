<?php

declare(strict_types=1);

namespace WeShop\Analytics\Provider;

use WeShop\Analytics\Service\AnalyticsConfigService;
use WeShop\Analytics\Interface\PixelProviderInterface;
use Weline\Framework\App\Env;

class GoogleAnalytics implements PixelProviderInterface
{
    public function __construct(
        private readonly ?string $measurementId = null,
        private readonly ?string $apiSecret = null,
        private readonly ?bool $enabled = null,
        private readonly ?AnalyticsConfigService $analyticsConfigService = null
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->readEnabled() && $this->readMeasurementId() !== '' && $this->readApiSecret() !== '';
    }

    public function sendEvent(string $eventName, array $eventData): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        try {
            $payload = [
                'client_id' => (string) ($eventData['client_id'] ?? $this->generateClientId()),
                'events' => [
                    [
                        'name' => $this->mapEventName($eventName),
                        'params' => $this->mapEventParams($eventName, $eventData),
                    ],
                ],
            ];

            $success = $this->postJson($this->buildEndpoint(), $payload);

            w_log_info('Google Analytics event tracked', [
                'event' => $eventName,
                'measurement_id' => $this->readMeasurementId(),
                'success' => $success,
            ], 'weshop_analytics');

            return $success;
        } catch (\Throwable $e) {
            w_log_error('Google Analytics tracking failed', [
                'error' => $e->getMessage(),
                'event' => $eventName,
                'data' => $eventData,
            ], 'weshop_analytics');

            return false;
        }
    }

    public function track(string $eventName, array $eventData): void
    {
        $this->sendEvent($eventName, $eventData);
    }

    public function getPixelCode(): string
    {
        if (!$this->isEnabled()) {
            return '';
        }

        $measurementId = $this->sanitizeSnippetToken($this->readMeasurementId());
        if ($measurementId === '') {
            return '';
        }

        return $this->buildHeadSnippet($measurementId);
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

        $measurementId = $this->sanitizeSnippetToken($this->readMeasurementId());
        if ($measurementId === '') {
            return [
                'head' => '',
                'body' => '',
                'footer' => '',
            ];
        }

        return [
            'head' => $this->buildHeadSnippet($measurementId),
            'body' => '',
            'footer' => '',
        ];
    }

    private function buildHeadSnippet(string $measurementId): string
    {
        return <<<HTML
<!-- Google Analytics -->
<script async src="https://www.googletagmanager.com/gtag/js?id={$measurementId}"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', '{$measurementId}');
</script>
HTML;
    }

    public function getFrontendCode(): string
    {
        return $this->getPixelCode();
    }

    private function mapEventName(string $eventName): string
    {
        return match ($eventName) {
            'register' => 'sign_up',
            'login' => 'login',
            default => $eventName,
        };
    }

    /**
     * @param array<string, mixed> $eventData
     * @return array<string, mixed>
     */
    private function mapEventParams(string $eventName, array $eventData): array
    {
        $params = [
            'currency' => (string) ($eventData['currency'] ?? 'USD'),
            'value' => (float) ($eventData['value'] ?? 0),
        ];

        if (!empty($eventData['transaction_id'])) {
            $params['transaction_id'] = (string) $eventData['transaction_id'];
        }

        if (!empty($eventData['items']) && is_array($eventData['items'])) {
            $params['items'] = $this->formatItems($eventData['items']);
        }

        if ($eventName === 'view_item' && !empty($eventData['item_list_name'])) {
            $params['item_list_name'] = (string) $eventData['item_list_name'];
        }

        return $params;
    }

    /**
     * @param array<int, mixed> $items
     * @return array<int, array<string, mixed>>
     */
    private function formatItems(array $items): array
    {
        $formatted = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $formatted[] = [
                'item_id' => (string) ($item['product_id'] ?? $item['item_id'] ?? ''),
                'item_name' => (string) ($item['name'] ?? ''),
                'price' => (float) ($item['price'] ?? 0),
                'quantity' => (int) ($item['qty'] ?? $item['quantity'] ?? 1),
            ];
        }

        return $formatted;
    }

    private function generateClientId(): string
    {
        $ip = (string) (\Weline\Framework\Env\WelineEnv::server('REMOTE_ADDR', '127.0.0.1'));
        $userAgent = (string) (\Weline\Framework\Env\WelineEnv::server('HTTP_USER_AGENT', 'cli'));

        return md5($ip . '|' . $userAgent);
    }

    protected function buildEndpoint(): string
    {
        return 'https://www.google-analytics.com/mp/collect'
            . '?measurement_id=' . rawurlencode($this->readMeasurementId())
            . '&api_secret=' . rawurlencode($this->readApiSecret());
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function postJson(string $url, array $payload): bool
    {
        $data = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($data === false) {
            return false;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        $response = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hasError = $response === false || curl_errno($ch) !== 0;
        curl_close($ch);

        if ($hasError) {
            return false;
        }

        return $statusCode >= 200 && $statusCode < 300;
    }

    protected function readMeasurementId(): string
    {
        return trim((string) ($this->measurementId ?? $this->getConfigValue('measurement_id', '')));
    }

    protected function readApiSecret(): string
    {
        return trim((string) ($this->apiSecret ?? $this->getConfigValue('api_secret', '')));
    }

    protected function readEnabled(): bool
    {
        return $this->enabled ?? (bool) $this->getConfigValue('enabled', false);
    }

    private function sanitizeSnippetToken(string $value): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9._-]/', '', $value);
        return trim((string) $sanitized);
    }

    private function getConfigValue(string $field, mixed $default): mixed
    {
        if ($this->analyticsConfigService) {
            $config = $this->analyticsConfigService->getProviderConfig(AnalyticsConfigService::PROVIDER_GOOGLE);
            if (array_key_exists($field, $config)) {
                return $config[$field];
            }
        }

        return Env::getInstance()->getConfig('analytics.google.' . $field, $default);
    }
}
