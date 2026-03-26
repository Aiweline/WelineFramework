<?php

declare(strict_types=1);

namespace WeShop\Shipping\Provider;

use WeShop\Shipping\Interface\ShippingProviderInterface;
use Weline\Framework\App\Env;

/**
 * DHL配送提供商
 */
class DHL implements ShippingProviderInterface
{
    private string $siteId;
    private string $password;
    private bool $enabled;
    private bool $sandbox;
    
    public function __construct()
    {
        $this->siteId = Env::getInstance()->getConfig('shipping.dhl.site_id', '');
        $this->password = Env::getInstance()->getConfig('shipping.dhl.password', '');
        $this->enabled = (bool)Env::getInstance()->getConfig('shipping.dhl.enabled', false);
        $this->sandbox = (bool)Env::getInstance()->getConfig('shipping.dhl.sandbox', true);
    }
    
    public function getName(): string
    {
        return __('DHL');
    }
    
    public function getCode(): string
    {
        return 'dhl';
    }
    
    public function isEnabled(): bool
    {
        return $this->enabled && !empty($this->siteId) && !empty($this->password);
    }

    public function calculateShipping(array $shippingData): float
    {
        $rates = $this->calculateRates(
            $this->extractAddress($shippingData),
            $this->extractItems($shippingData)
        );
        $lowestRate = $this->pickLowestRateAmount($rates);
        if ($lowestRate === null) {
            throw new \RuntimeException((string) __('No DHL shipping rate is currently available.'));
        }

        return $lowestRate;
    }
    
    public function calculateRates(array $address, array $items): array
    {
        try {
            $totalWeight = 0;
            foreach ($items as $item) {
                $totalWeight += ($item['weight'] ?? 0) * ($item['qty'] ?? 1);
            }
            
            $baseUrl = $this->sandbox 
                ? 'https://wsbexpress.dhl.com/rest/sndpt/rating' 
                : 'https://wsbexpress.dhl.com/rest/sndpt/rating';
            
            $requestData = [
                'rateRequest' => [
                    'ClientDetails' => [
                        'SiteID' => $this->siteId,
                        'Password' => $this->password,
                    ],
                    'RequestedShipment' => [
                        'DropOffType' => 'REGULAR_PICKUP',
                        'Ship' => [
                            'Shipper' => [
                                'City' => Env::getInstance()->getConfig('shipping.origin.city', ''),
                                'CountryCode' => Env::getInstance()->getConfig('shipping.origin.country', 'CN'),
                            ],
                            'Recipient' => [
                                'City' => $address['city'] ?? '',
                                'CountryCode' => $address['country'] ?? '',
                                'PostalCode' => $address['postcode'] ?? '',
                            ],
                        ],
                        'Packages' => [
                            [
                                'Weight' => $totalWeight,
                                'Dimensions' => [
                                    'Length' => 10,
                                    'Width' => 10,
                                    'Height' => 10,
                                ],
                            ],
                        ],
                    ],
                ],
            ];
            
            $response = $this->httpPost($baseUrl, json_encode($requestData));
            $result = json_decode($response, true);
            
            $rates = [];
            if (isset($result['RateResponse']['Provider'][0]['Service'])) {
                foreach ($result['RateResponse']['Provider'][0]['Service'] as $service) {
                    $rates[] = [
                        'code' => $this->getCode() . '_' . ($service['@type'] ?? 'standard'),
                        'name' => $service['ServiceName'] ?? __('DHL标准配送'),
                        'price' => (float)($service['TotalNet']['Amount'] ?? 0),
                        'currency' => $service['TotalNet']['Currency'] ?? 'USD',
                        'estimated_days' => (int)($service['EstimatedDeliveryDate'] ?? 0),
                    ];
                }
            }
            
            w_log_info('DHL费率计算成功', [
                'address' => $address,
                'rates_count' => count($rates),
            ], 'weshop_shipping');
            
            return $rates;
        } catch (\Exception $e) {
            w_log_error('DHL费率计算失败', [
                'error' => $e->getMessage(),
                'address' => $address,
            ], 'weshop_shipping');
            
            return [];
        }
    }
    
    public function createShipment(array $orderData): array
    {
        try {
            $baseUrl = $this->sandbox 
                ? 'https://wsbexpress.dhl.com/rest/sndpt/ShipmentRequest' 
                : 'https://wsbexpress.dhl.com/rest/sndpt/ShipmentRequest';
            
            $requestData = [
                'ShipmentRequest' => [
                    'ClientDetails' => [
                        'SiteID' => $this->siteId,
                        'Password' => $this->password,
                    ],
                    'RequestedShipment' => [
                        'ShipmentInfo' => [
                            'DropOffType' => 'REGULAR_PICKUP',
                            'ServiceType' => $orderData['service_type'] ?? 'P',
                            'Account' => Env::getInstance()->getConfig('shipping.dhl.account', ''),
                        ],
                        'ShipTimestamp' => date('c'),
                        'Shipper' => [
                            'Contact' => [
                                'PersonName' => Env::getInstance()->getConfig('shipping.origin.name', ''),
                                'PhoneNumber' => Env::getInstance()->getConfig('shipping.origin.phone', ''),
                            ],
                            'Address' => [
                                'StreetLines' => [Env::getInstance()->getConfig('shipping.origin.street', '')],
                                'City' => Env::getInstance()->getConfig('shipping.origin.city', ''),
                                'PostalCode' => Env::getInstance()->getConfig('shipping.origin.postcode', ''),
                                'CountryCode' => Env::getInstance()->getConfig('shipping.origin.country', 'CN'),
                            ],
                        ],
                        'Recipient' => [
                            'Contact' => [
                                'PersonName' => ($orderData['firstname'] ?? '') . ' ' . ($orderData['lastname'] ?? ''),
                                'PhoneNumber' => $orderData['phone'] ?? '',
                            ],
                            'Address' => [
                                'StreetLines' => [$orderData['street'] ?? ''],
                                'City' => $orderData['city'] ?? '',
                                'PostalCode' => $orderData['postcode'] ?? '',
                                'CountryCode' => $orderData['country'] ?? '',
                            ],
                        ],
                        'Packages' => [
                            [
                                'Weight' => $orderData['weight'] ?? 1,
                                'Dimensions' => [
                                    'Length' => $orderData['length'] ?? 10,
                                    'Width' => $orderData['width'] ?? 10,
                                    'Height' => $orderData['height'] ?? 10,
                                ],
                            ],
                        ],
                    ],
                ],
            ];
            
            $response = $this->httpPost($baseUrl, json_encode($requestData));
            $result = json_decode($response, true);
            
            if (isset($result['ShipmentResponse']['ShipmentIdentificationNumber'])) {
                $trackingNumber = $result['ShipmentResponse']['ShipmentIdentificationNumber'];
                
                w_log_info('DHL运单创建成功', [
                    'order_id' => $orderData['order_id'] ?? '',
                    'tracking_number' => $trackingNumber,
                ], 'weshop_shipping');
                
                return [
                    'success' => true,
                    'tracking_number' => $trackingNumber,
                    'label_url' => $result['ShipmentResponse']['LabelImage'][0]['GraphicImage'] ?? '',
                ];
            }
            
            throw new \Exception($result['ShipmentResponse']['Notification'][0]['Message'] ?? __('DHL运单创建失败'));
        } catch (\Exception $e) {
            w_log_error('DHL运单创建失败', [
                'error' => $e->getMessage(),
                'order_data' => $orderData,
            ], 'weshop_shipping');
            
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function createShipping(array $orderData): string
    {
        $shipment = $this->createShipment($orderData);
        if (!($shipment['success'] ?? false) || empty($shipment['tracking_number'])) {
            throw new \RuntimeException((string) ($shipment['message'] ?? __('DHL shipment creation failed.')));
        }

        return (string) $shipment['tracking_number'];
    }
    
    public function trackShipment(string $trackingNumber): array
    {
        try {
            $baseUrl = $this->sandbox 
                ? 'https://wsbexpress.dhl.com/rest/sndpt/TrackingRequest' 
                : 'https://wsbexpress.dhl.com/rest/sndpt/TrackingRequest';
            
            $requestData = [
                'TrackingRequest' => [
                    'ClientDetails' => [
                        'SiteID' => $this->siteId,
                        'Password' => $this->password,
                    ],
                    'AWBNumber' => $trackingNumber,
                ],
            ];
            
            $response = $this->httpPost($baseUrl, json_encode($requestData));
            $result = json_decode($response, true);
            
            $tracking = [
                'tracking_number' => $trackingNumber,
                'status' => 'unknown',
                'events' => [],
            ];
            
            if (isset($result['TrackingResponse']['TrackingResponse']['AWBInfo'])) {
                $awbInfo = $result['TrackingResponse']['TrackingResponse']['AWBInfo'];
                $tracking['status'] = $awbInfo['Status']['ActionStatus'] ?? 'unknown';
                
                if (isset($awbInfo['ShipmentEvent'])) {
                    foreach ($awbInfo['ShipmentEvent'] as $event) {
                        $tracking['events'][] = [
                            'date' => $event['Date'] ?? '',
                            'time' => $event['Time'] ?? '',
                            'location' => $event['ServiceArea']['Description'] ?? '',
                            'description' => $event['ServiceEvent']['Description'] ?? '',
                        ];
                    }
                }
            }
            
            return $tracking;
        } catch (\Exception $e) {
            w_log_error('DHL运单查询失败', [
                'error' => $e->getMessage(),
                'tracking_number' => $trackingNumber,
            ], 'weshop_shipping');
            
            return [
                'tracking_number' => $trackingNumber,
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    public function trackShipping(string $trackingNumber): array
    {
        return $this->trackShipment($trackingNumber);
    }

    /**
     * @param array<string, mixed> $shippingData
     * @return array<string, mixed>
     */
    private function extractAddress(array $shippingData): array
    {
        $address = $shippingData['address'] ?? $shippingData['shipping_address'] ?? [];
        if (is_array($address) && $address !== []) {
            return $address;
        }

        return array_filter([
            'city' => $shippingData['city'] ?? null,
            'country' => $shippingData['country'] ?? $shippingData['country_id'] ?? null,
            'postcode' => $shippingData['postcode'] ?? null,
        ], static fn(mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param array<string, mixed> $shippingData
     * @return array<int, array<string, mixed>>
     */
    private function extractItems(array $shippingData): array
    {
        $items = $shippingData['items'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        return array_values(array_filter($items, static fn(mixed $item): bool => is_array($item)));
    }

    /**
     * @param array<int, array<string, mixed>> $rates
     */
    private function pickLowestRateAmount(array $rates): ?float
    {
        $amounts = [];
        foreach ($rates as $rate) {
            if (!is_array($rate) || !isset($rate['price']) || !is_numeric($rate['price'])) {
                continue;
            }
            $amounts[] = (float) $rate['price'];
        }

        if ($amounts === []) {
            return null;
        }

        return min($amounts);
    }

    private function httpPost(string $url, string $data): string
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
        $response = curl_exec($ch);
        curl_close($ch);
        return $response ?: '';
    }
}
