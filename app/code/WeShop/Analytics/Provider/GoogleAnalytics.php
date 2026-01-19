<?php

declare(strict_types=1);

namespace WeShop\Analytics\Provider;

use WeShop\Analytics\Interface\PixelProviderInterface;
use Weline\Framework\App\Env;

/**
 * Google Analytics像素统计提供商
 */
class GoogleAnalytics implements PixelProviderInterface
{
    private string $measurementId;
    private bool $enabled;
    
    public function __construct()
    {
        $this->measurementId = Env::getInstance()->getConfig('analytics.google.measurement_id', '');
        $this->enabled = (bool)Env::getInstance()->getConfig('analytics.google.enabled', false);
    }
    
    public function isEnabled(): bool
    {
        return $this->enabled && !empty($this->measurementId);
    }
    
    public function track(string $event, array $data): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        
        try {
            // Google Analytics 4 (GA4) 事件追踪
            $eventData = [
                'client_id' => $data['client_id'] ?? $this->generateClientId(),
                'events' => [
                    [
                        'name' => $this->mapEventName($event),
                        'params' => $this->mapEventParams($event, $data),
                    ],
                ],
            ];
            
            $endpoint = 'https://www.google-analytics.com/mp/collect';
            $endpoint .= '?measurement_id=' . $this->measurementId;
            $endpoint .= '&api_secret=' . Env::getInstance()->getConfig('analytics.google.api_secret', '');
            
            $this->httpPost($endpoint, json_encode($eventData));
            
            Env::log_info('Google Analytics事件追踪', [
                'event' => $event,
                'measurement_id' => $this->measurementId,
            ], 'weshop_analytics');
        } catch (\Exception $e) {
            Env::log_error('Google Analytics追踪失败', [
                'error' => $e->getMessage(),
                'event' => $event,
                'data' => $data,
            ], 'weshop_analytics');
        }
    }
    
    public function getName(): string
    {
        return __('Google Analytics');
    }
    
    /**
     * 获取前端追踪代码
     */
    public function getFrontendCode(): string
    {
        if (!$this->isEnabled()) {
            return '';
        }
        
        return <<<HTML
<!-- Google Analytics -->
<script async src="https://www.googletagmanager.com/gtag/js?id={$this->measurementId}"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', '{$this->measurementId}');
</script>
HTML;
    }
    
    private function mapEventName(string $event): string
    {
        $eventMap = [
            'add_to_cart' => 'add_to_cart',
            'remove_from_cart' => 'remove_from_cart',
            'view_item' => 'view_item',
            'begin_checkout' => 'begin_checkout',
            'purchase' => 'purchase',
            'add_to_wishlist' => 'add_to_wishlist',
        ];
        
        return $eventMap[$event] ?? $event;
    }
    
    private function mapEventParams(string $event, array $data): array
    {
        $params = [];
        
        switch ($event) {
            case 'add_to_cart':
            case 'remove_from_cart':
                $params = [
                    'currency' => $data['currency'] ?? 'USD',
                    'value' => (float)($data['value'] ?? 0),
                    'items' => $this->formatItems($data['items'] ?? []),
                ];
                break;
                
            case 'view_item':
                $params = [
                    'currency' => $data['currency'] ?? 'USD',
                    'value' => (float)($data['value'] ?? 0),
                    'items' => $this->formatItems($data['items'] ?? []),
                ];
                break;
                
            case 'begin_checkout':
                $params = [
                    'currency' => $data['currency'] ?? 'USD',
                    'value' => (float)($data['value'] ?? 0),
                    'items' => $this->formatItems($data['items'] ?? []),
                ];
                break;
                
            case 'purchase':
                $params = [
                    'transaction_id' => $data['transaction_id'] ?? '',
                    'value' => (float)($data['value'] ?? 0),
                    'currency' => $data['currency'] ?? 'USD',
                    'items' => $this->formatItems($data['items'] ?? []),
                ];
                break;
                
            case 'add_to_wishlist':
                $params = [
                    'currency' => $data['currency'] ?? 'USD',
                    'value' => (float)($data['value'] ?? 0),
                    'items' => $this->formatItems($data['items'] ?? []),
                ];
                break;
        }
        
        return $params;
    }
    
    private function formatItems(array $items): array
    {
        $formatted = [];
        foreach ($items as $item) {
            $formatted[] = [
                'item_id' => (string)($item['product_id'] ?? ''),
                'item_name' => $item['name'] ?? '',
                'price' => (float)($item['price'] ?? 0),
                'quantity' => (int)($item['qty'] ?? 1),
            ];
        }
        return $formatted;
    }
    
    private function generateClientId(): string
    {
        return md5($_SERVER['REMOTE_ADDR'] . $_SERVER['HTTP_USER_AGENT'] . time());
    }
    
    private function httpPost(string $url, string $data): void
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}
