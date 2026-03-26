<?php

declare(strict_types=1);

namespace WeShop\Analytics\Provider;

use WeShop\Analytics\Interface\PixelProviderInterface;
use WeShop\Analytics\Service\AnalyticsConfigService;
use Weline\Framework\App\Env;

class TikTokPixel implements PixelProviderInterface
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
        return $this->readEnabled() && $this->readPixelId() !== '';
    }

    public function sendEvent(string $eventName, array $eventData): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $token = $this->readAccessToken();
        if ($token === '') {
            return false;
        }

        $payload = [
            'pixel_code' => $this->readPixelId(),
            'event' => $this->mapEventName($eventName),
            'event_id' => $this->resolveEventId($eventData),
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z', (int) ($eventData['event_time'] ?? time())),
            'event_source' => 'web',
            'context' => $this->buildContext($eventData),
            'properties' => $this->buildProperties($eventData),
        ];

        $testEventCode = $this->readTestEventCode();
        if ($testEventCode !== '') {
            $payload['test_event_code'] = $testEventCode;
        }

        return $this->postJson(
            'https://business-api.tiktok.com/open_api/v1.3/pixel/track/',
            $payload,
            ['Access-Token: ' . $token]
        );
    }

    public function getPixelCode(): string
    {
        if (!$this->isEnabled()) {
            return '';
        }

        return $this->buildHeadSnippet($this->readPixelId());
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

        return [
            'head' => $this->buildHeadSnippet($this->readPixelId()),
            'body' => '',
            'footer' => '',
        ];
    }

    private function buildHeadSnippet(string $pixelId): string
    {
        return <<<HTML
<!-- TikTok Pixel Code -->
<script>
!function (w, d, t) {
  w.TiktokAnalyticsObject = t;
  var ttq = w[t] = w[t] || [];
  ttq.methods = ['page', 'track', 'identify', 'instances', 'debug', 'on', 'off', 'once', 'ready', 'alias', 'group', 'enableCookie', 'disableCookie'];
  ttq.setAndDefer = function (obj, method) {
    obj[method] = function () {
      obj.push([method].concat(Array.prototype.slice.call(arguments, 0)));
    };
  };
  for (var i = 0; i < ttq.methods.length; i++) {
    ttq.setAndDefer(ttq, ttq.methods[i]);
  }
  ttq.load = function (id) {
    var src = 'https://analytics.tiktok.com/i18n/pixel/events.js';
    ttq._i = ttq._i || {};
    ttq._i[id] = [];
    ttq._i[id]._u = src;
    ttq._t = ttq._t || {};
    ttq._t[id] = +new Date();
    ttq._o = ttq._o || {};
    ttq._o[id] = {};
    var script = d.createElement('script');
    script.type = 'text/javascript';
    script.async = true;
    script.src = src + '?sdkid=' + encodeURIComponent(id) + '&lib=' + encodeURIComponent(t);
    var firstScript = d.getElementsByTagName('script')[0];
    firstScript.parentNode.insertBefore(script, firstScript);
  };
  ttq.load('{$pixelId}');
  ttq.page();
}(window, document, 'ttq');
</script>
HTML;
    }

    private function readEnabled(): bool
    {
        return $this->enabled ?? (bool) $this->getConfigValue('enabled', false);
    }

    private function readPixelId(): string
    {
        return trim((string) ($this->pixelId ?? $this->getConfigValue('pixel_id', '')));
    }

    private function readAccessToken(): string
    {
        return trim((string) ($this->accessToken ?? $this->getConfigValue('access_token', '')));
    }

    private function readTestEventCode(): string
    {
        return trim((string) ($this->testEventCode ?? $this->getConfigValue('test_event_code', '')));
    }

    private function mapEventName(string $eventName): string
    {
        $eventName = strtolower(trim($eventName));

        return match ($eventName) {
            'page_view' => 'PageView',
            'view_item' => 'ViewContent',
            'add_to_cart' => 'AddToCart',
            'add_to_wishlist' => 'AddToWishlist',
            'begin_checkout' => 'InitiateCheckout',
            'purchase' => 'CompletePayment',
            'register' => 'CompleteRegistration',
            'login' => 'Login',
            default => ucfirst(str_replace('_', '', $eventName)),
        };
    }

    /**
     * @param array<string, mixed> $eventData
     */
    private function resolveEventId(array $eventData): string
    {
        $eventId = trim((string) ($eventData['event_id'] ?? ''));
        if ($eventId !== '') {
            return $eventId;
        }

        return 'weshop_' . md5((string) microtime(true) . random_int(1, PHP_INT_MAX));
    }

    /**
     * @param array<string, mixed> $eventData
     * @return array<string, mixed>
     */
    private function buildContext(array $eventData): array
    {
        $email = trim(strtolower((string) ($eventData['email'] ?? '')));
        $phone = preg_replace('/\D+/', '', (string) ($eventData['phone'] ?? $eventData['phone_number'] ?? '')) ?? '';
        $externalId = trim((string) ($eventData['customer_id'] ?? $eventData['user_id'] ?? ''));

        $user = [];
        if ($email !== '') {
            $user['email'] = $this->hashSha256($email);
        }
        if ($phone !== '') {
            $user['phone_number'] = $this->hashSha256($phone);
        }
        if ($externalId !== '') {
            $user['external_id'] = $this->hashSha256($externalId);
        }

        $ttclid = trim((string) ($eventData['ttclid'] ?? ''));
        if ($ttclid !== '') {
            $user['ttclid'] = $ttclid;
        }

        $ttp = trim((string) ($eventData['ttp'] ?? ''));
        if ($ttp !== '') {
            $user['ttp'] = $ttp;
        }

        return [
            'ad' => [
                'callback' => (string) ($eventData['callback'] ?? ''),
            ],
            'page' => [
                'url' => (string) ($eventData['event_source_url'] ?? ''),
                'referrer' => (string) ($eventData['referrer_url'] ?? ''),
            ],
            'user' => $user,
            'user_agent' => (string) ($eventData['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? 'cli'),
            'ip' => (string) ($eventData['ip'] ?? $eventData['client_ip'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
        ];
    }

    /**
     * @param array<string, mixed> $eventData
     * @return array<string, mixed>
     */
    private function buildProperties(array $eventData): array
    {
        $properties = [
            'currency' => (string) ($eventData['currency'] ?? 'USD'),
            'value' => (float) ($eventData['value'] ?? 0),
        ];

        $items = is_array($eventData['items'] ?? null) ? $eventData['items'] : [];
        if ($items !== []) {
            $properties['contents'] = array_values(array_filter(array_map(function (mixed $item): array {
                if (!is_array($item)) {
                    return [];
                }

                $contentId = (string) ($item['product_id'] ?? $item['item_id'] ?? $item['id'] ?? '');
                if ($contentId === '') {
                    return [];
                }

                return [
                    'content_id' => $contentId,
                    'content_name' => (string) ($item['name'] ?? ''),
                    'quantity' => (int) ($item['qty'] ?? $item['quantity'] ?? 1),
                    'price' => (float) ($item['price'] ?? 0),
                ];
            }, $items), static fn(array $item): bool => $item !== []));
            $properties['content_type'] = 'product';
        }

        return $properties;
    }

    private function hashSha256(string $value): string
    {
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
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(['Content-Type: application/json'], $headers));
        $response = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($statusCode === 0 || ($statusCode >= 200 && $statusCode < 300)) {
            if (is_string($response) && $response !== '') {
                $decoded = json_decode($response, true);
                if (is_array($decoded) && array_key_exists('code', $decoded)) {
                    return (int) $decoded['code'] === 0;
                }
            }

            return true;
        }

        return false;
    }

    private function getConfigValue(string $field, mixed $default): mixed
    {
        if ($this->analyticsConfigService) {
            $config = $this->analyticsConfigService->getProviderConfig(AnalyticsConfigService::PROVIDER_TIKTOK);
            if (array_key_exists($field, $config)) {
                return $config[$field];
            }
        }

        return Env::getInstance()->getConfig('analytics.tiktok.' . $field, $default);
    }
}
