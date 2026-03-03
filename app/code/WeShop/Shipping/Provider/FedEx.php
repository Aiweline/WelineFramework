<?php

declare(strict_types=1);

namespace WeShop\Shipping\Provider;

use WeShop\Shipping\Interface\ShippingProviderInterface;
use Weline\Framework\App\Env;

/**
 * FedEx配送提供商
 */
class FedEx implements ShippingProviderInterface
{
    private string $apiKey;
    private string $apiSecret;
    private bool $enabled;
    private bool $sandbox;
    
    public function __construct()
    {
        $this->apiKey = Env::getInstance()->getConfig('shipping.fedex.api_key', '');
        $this->apiSecret = Env::getInstance()->getConfig('shipping.fedex.api_secret', '');
        $this->enabled = (bool)Env::getInstance()->getConfig('shipping.fedex.enabled', false);
        $this->sandbox = (bool)Env::getInstance()->getConfig('shipping.fedex.sandbox', true);
    }
    
    public function getName(): string
    {
        return __('FedEx');
    }
    
    public function getCode(): string
    {
        return 'fedex';
    }
    
    public function isEnabled(): bool
    {
        return $this->enabled && !empty($this->apiKey) && !empty($this->apiSecret);
    }
    
    public function calculateRates(array $address, array $items): array
    {
        try {
            $accessToken = $this->getAccessToken();
            if (!$accessToken) {
                throw new \Exception(__('获取FedEx访问令牌失败'));
            }
            
            $totalWeight = 0;
            foreach ($items as $item) {
                $totalWeight += ($item['weight'] ?? 0) * ($item['qty'] ?? 1);
            }
            
            $baseUrl = $this->sandbox 
                ? 'https://apis-sandbox.fedex.com' 
                : 'https://apis.fedex.com';
            
            $requestData = [
                'accountNumber' => [
                    'value' => Env::getInstance()->getConfig('shipping.fedex.account_number', ''),
                ],
                'requestedShipment' => [
                    'shipper' => [
                        'address' => [
                            'city' => Env::getInstance()->getConfig('shipping.origin.city', ''),
                            'countryCode' => Env::getInstance()->getConfig('shipping.origin.country', 'CN'),
                            'postalCode' => Env::getInstance()->getConfig('shipping.origin.postcode', ''),
                        ],
                    ],
                    'recipients' => [
                        [
                            'address' => [
                                'city' => $address['city'] ?? '',
                                'countryCode' => $address['country'] ?? '',
                                'postalCode' => $address['postcode'] ?? '',
                            ],
                        ],
                    ],
                    'rateRequestType' => ['ACCOUNT', 'LIST'],
                    'requestedPackageLineItems' => [
                        [
                            'weight' => [
                                'units' => 'KG',
                                'value' => $totalWeight,
                            ],
                        ],
                    ],
                ],
            ];
            
            $response = $this->httpPost(
                $baseUrl . '/rate/v1/rates/quotes',
                json_encode($requestData),
                ['Authorization: Bearer ' . $accessToken]
            );
            
            $result = json_decode($response, true);
            
            $rates = [];
            if (isset($result['output']['rateReplyDetails'])) {
                foreach ($result['output']['rateReplyDetails'] as $detail) {
                    $rates[] = [
                        'code' => $this->getCode() . '_' . ($detail['serviceType'] ?? 'standard'),
                        'name' => $detail['serviceName'] ?? __('FedEx标准配送'),
                        'price' => (float)($detail['ratedShipmentDetails'][0]['totalNetCharge']['amount'] ?? 0),
                        'currency' => $detail['ratedShipmentDetails'][0]['totalNetCharge']['currency'] ?? 'USD',
                        'estimated_days' => (int)($detail['commit']['dateDetail']['dayOfWeek'] ?? 0),
                    ];
                }
            }
            
            return $rates;
        } catch (\Exception $e) {
            w_log_error('FedEx费率计算失败', [
                'error' => $e->getMessage(),
                'address' => $address,
            ], 'weshop_shipping');
            
            return [];
        }
    }
    
    public function createShipment(array $orderData): array
    {
        // 简化实现
        return [
            'success' => true,
            'tracking_number' => 'FEDEX' . time(),
        ];
    }
    
    public function trackShipment(string $trackingNumber): array
    {
        // 简化实现
        return [
            'tracking_number' => $trackingNumber,
            'status' => 'in_transit',
            'events' => [],
        ];
    }
    
    private function getAccessToken(): ?string
    {
        $baseUrl = $this->sandbox 
            ? 'https://apis-sandbox.fedex.com' 
            : 'https://apis.fedex.com';
        
        $response = $this->httpPost(
            $baseUrl . '/oauth/token',
            'grant_type=client_credentials&client_id=' . $this->apiKey . '&client_secret=' . $this->apiSecret,
            ['Content-Type: application/x-www-form-urlencoded']
        );
        
        $result = json_decode($response, true);
        return $result['access_token'] ?? null;
    }
    
    private function httpPost(string $url, string $data, array $headers = []): string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        } else {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }
        
        $response = curl_exec($ch);
        curl_close($ch);
        return $response ?: '';
    }
}
