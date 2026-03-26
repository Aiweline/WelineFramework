<?php

declare(strict_types=1);

namespace WeShop\Analytics\Provider;

use WeShop\Analytics\Service\AnalyticsConfigService;
use WeShop\Analytics\Interface\PixelProviderInterface;
use Weline\Framework\App\Env;

class FacebookPixel implements PixelProviderInterface
{
    public function __construct(
        private readonly ?string $pixelId = null,
        private readonly ?string $accessToken = null,
        private readonly ?bool $enabled = null,
        private readonly ?string $testEventCode = null,
        private readonly ?AnalyticsConfigService $analyticsConfigService = null
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->readEnabled() && $this->readPixelId() !== '' && $this->readAccessToken() !== '';
    }

    public function sendEvent(string $eventName, array $eventData): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $payload = [
            'data' => [
                [
                    'event_name' => $this->mapEventName($eventName),
                    'event_time' => (int) ($eventData['event_time'] ?? time()),
                    'action_source' => (string) ($eventData['action_source'] ?? 'website'),
                    'event_source_url' => $this->readEventSourceUrl($eventData),
                    'user_data' => $this->buildUserData($eventData),
                    'custom_data' => $this->buildCustomData($eventName, $eventData),
                ],
            ],
        ];

        $testEventCode = $this->readTestEventCode();
        if ($testEventCode !== '') {
            $payload['test_event_code'] = $testEventCode;
        }

        return $this->postJson($this->buildEndpoint(), $payload);
    }

    public function getPixelCode(): string
    {
        if (!$this->isEnabled()) {
            return '';
        }

        $pixelId = $this->readPixelId();

        $headSnippet = $this->buildHeadSnippet($pixelId);
        $bodySnippet = $this->buildBodySnippet($pixelId);

        return trim($headSnippet . "\n" . $bodySnippet);
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

        $pixelId = $this->readPixelId();

        return [
            'head' => $this->buildHeadSnippet($pixelId),
            'body' => $this->buildBodySnippet($pixelId),
            'footer' => '',
        ];
    }

    private function buildHeadSnippet(string $pixelId): string
    {
        return <<<HTML
<!-- Facebook Pixel Code -->
<script>
  !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
  n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
  n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
  t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window, document,'script',
  'https://connect.facebook.net/en_US/fbevents.js');
  fbq('init', '{$pixelId}');
  fbq('track', 'PageView');
</script>
HTML;
    }

    private function buildBodySnippet(string $pixelId): string
    {
        return <<<HTML
<noscript><img height="1" width="1" style="display:none"
  src="https://www.facebook.com/tr?id={$pixelId}&ev=PageView&noscript=1"/></noscript>
HTML;
    }

    protected function mapEventName(string $eventName): string
    {
        return match ($eventName) {
            'add_to_cart' => 'AddToCart',
            'add_to_wishlist' => 'AddToWishlist',
            'purchase' => 'Purchase',
            'begin_checkout' => 'InitiateCheckout',
            'view_item' => 'ViewContent',
            'register' => 'CompleteRegistration',
            'login' => 'Login',
            default => $eventName,
        };
    }

    /**
     * @param array<string, mixed> $eventData
     * @return array<string, mixed>
     */
    protected function buildCustomData(string $eventName, array $eventData): array
    {
        $payload = [
            'currency' => (string) ($eventData['currency'] ?? 'USD'),
            'value' => (float) ($eventData['value'] ?? 0),
        ];

        if (!empty($eventData['transaction_id'])) {
            $payload['order_id'] = (string) $eventData['transaction_id'];
        }

        if (!empty($eventData['items']) && is_array($eventData['items'])) {
            $payload['contents'] = array_map(
                static fn(array $item): array => [
                    'id' => (string) ($item['product_id'] ?? $item['item_id'] ?? ''),
                    'quantity' => (int) ($item['qty'] ?? $item['quantity'] ?? 1),
                    'item_price' => (float) ($item['price'] ?? 0),
                ],
                array_values(array_filter($eventData['items'], 'is_array'))
            );
            $payload['content_type'] = 'product';
        }

        if ($eventName === 'view_item' && isset($eventData['content_name'])) {
            $payload['content_name'] = (string) $eventData['content_name'];
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $eventData
     * @return array<string, string>
     */
    protected function buildUserData(array $eventData): array
    {
        $userData = [];
        $email = trim(strtolower((string) ($eventData['email'] ?? '')));
        if ($email !== '') {
            $userData['em'] = hash('sha256', $email);
        }

        $customerId = trim((string) ($eventData['customer_id'] ?? $eventData['user_id'] ?? ''));
        if ($customerId !== '') {
            $userData['external_id'] = hash('sha256', $customerId);
        }

        return $userData;
    }

    /**
     * @param array<string, mixed> $eventData
     */
    protected function readEventSourceUrl(array $eventData): string
    {
        $url = trim((string) ($eventData['event_source_url'] ?? ''));
        if ($url !== '') {
            return $url;
        }

        if (!empty($_SERVER['HTTP_HOST']) && !empty($_SERVER['REQUEST_URI'])) {
            $scheme = (string) ($_SERVER['REQUEST_SCHEME'] ?? 'https');
            return $scheme . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        }

        return '';
    }

    protected function buildEndpoint(): string
    {
        return 'https://graph.facebook.com/v18.0/' . rawurlencode($this->readPixelId()) . '/events'
            . '?access_token=' . rawurlencode($this->readAccessToken());
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function postJson(string $url, array $payload): bool
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
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $statusCode === 0 || ($statusCode >= 200 && $statusCode < 300);
    }

    protected function readEnabled(): bool
    {
        return $this->enabled ?? (bool) $this->getConfigValue('enabled', false);
    }

    protected function readPixelId(): string
    {
        return trim((string) ($this->pixelId ?? $this->getConfigValue('pixel_id', '')));
    }

    protected function readAccessToken(): string
    {
        return trim((string) ($this->accessToken ?? $this->getConfigValue('access_token', '')));
    }

    protected function readTestEventCode(): string
    {
        return trim((string) ($this->testEventCode ?? $this->getConfigValue('test_event_code', '')));
    }

    private function getConfigValue(string $field, mixed $default): mixed
    {
        if ($this->analyticsConfigService) {
            $config = $this->analyticsConfigService->getProviderConfig(AnalyticsConfigService::PROVIDER_FACEBOOK);
            if (array_key_exists($field, $config)) {
                return $config[$field];
            }
        }

        return Env::getInstance()->getConfig('analytics.facebook.' . $field, $default);
    }
}
