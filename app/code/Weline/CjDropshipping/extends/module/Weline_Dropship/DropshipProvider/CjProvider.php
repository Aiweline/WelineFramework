<?php

declare(strict_types=1);

namespace Weline\CjDropshipping\Extends\Module\Weline_Dropship\DropshipProvider;

use Weline\CjDropshipping\Service\CjApiClient;
use Weline\Dropship\Api\Data\DropshipCatalogSnapshot;
use Weline\Dropship\Interface\DropshipCatalogProviderInterface;
use Weline\Dropship\Interface\DropshipFreightProviderInterface;
use Weline\Dropship\Interface\DropshipFulfillmentProviderInterface;
use Weline\Dropship\Interface\DropshipWebhookProviderInterface;
use Weline\Framework\Manager\ObjectManager;

class CjProvider implements
    DropshipCatalogProviderInterface,
    DropshipFulfillmentProviderInterface,
    DropshipFreightProviderInterface,
    DropshipWebhookProviderInterface
{
    public function getCode(): string
    {
        return 'cj';
    }

    public function getCapabilities(): array
    {
        return [
            'catalog' => true,
            'fulfillment' => true,
            'freight' => true,
            'webhook' => true,
        ];
    }

    public function getDisplayMetadata(): array
    {
        return [
            'title' => 'CJ Dropshipping',
            'module' => 'Weline_CjDropshipping',
            'sort_order' => 10,
            'description' => '默认货源供应商',
        ];
    }

    public function getConfigSchema(): array
    {
        return [
            'fields' => ['email', 'api_key', 'access_token'],
            'config_center' => [
                'module' => 'Weline_CjDropshipping',
                'area' => 'backend',
                'group' => 'dropship_channel_cj',
                'guide_key' => 'dropship/channel/cj/email',
                'guide_title' => 'CJ 凭证',
                'guide_summary' => '填写 CJ Email 与 API Key 后可探活/拉品/推单。',
            ],
        ];
    }

    public function probeConnection(array $context = []): array
    {
        return $this->client()->probe();
    }

    public function searchProducts(array $query): array
    {
        try {
            $resp = $this->client()->get('/product/listV2', [
                'keyWord' => (string)($query['keyword'] ?? ''),
                'countryCode' => (string)($query['country_code'] ?? ''),
                'page' => 1,
                'size' => 20,
            ]);
            $list = $resp['data']['list'] ?? $resp['data'] ?? [];
            if (!is_array($list)) {
                return [];
            }
            $out = [];
            foreach ($list as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $out[] = DropshipCatalogSnapshot::fromArray([
                    'provider_code' => 'cj',
                    'external_spu' => (string)($row['pid'] ?? $row['id'] ?? ''),
                    'external_sku' => (string)($row['productSku'] ?? ''),
                    'title' => (string)($row['productNameEn'] ?? $row['productName'] ?? ''),
                    'origin_currency' => 'USD',
                    'origin_price_minor' => (int)round(((float)($row['sellPrice'] ?? $row['nowPrice'] ?? 0)) * 100),
                    'qty' => (int)($row['warehouseInventoryNum'] ?? $row['listedNum'] ?? 0),
                    'shelf_status' => 'active',
                    'country_code' => (string)($query['country_code'] ?? ''),
                    'raw' => $row,
                    'suggested_eav' => ['dropship_source' => 'cj'],
                ]);
            }

            return $out;
        } catch (\Throwable) {
            // Offline / missing credentials: return empty rather than crash admin.
            return [];
        }
    }

    public function getProduct(array $identity): ?DropshipCatalogSnapshot
    {
        try {
            $resp = $this->client()->get('/product/query', [
                'pid' => (string)($identity['pid'] ?? $identity['external_spu'] ?? ''),
            ]);
            $row = $resp['data'] ?? null;
            if (!is_array($row)) {
                return null;
            }

            return DropshipCatalogSnapshot::fromArray([
                'provider_code' => 'cj',
                'external_spu' => (string)($row['pid'] ?? ''),
                'title' => (string)($row['productNameEn'] ?? $row['productName'] ?? ''),
                'origin_currency' => 'USD',
                'origin_price_minor' => (int)round(((float)($row['sellPrice'] ?? 0)) * 100),
                'qty' => (int)($row['warehouseInventoryNum'] ?? 0),
                'shelf_status' => 'active',
                'raw' => $row,
                'suggested_eav' => ['dropship_source' => 'cj'],
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    public function syncCatalogSnapshot(array $listingRow): ?DropshipCatalogSnapshot
    {
        return $this->getProduct([
            'external_spu' => $listingRow['external_spu'] ?? '',
            'pid' => $listingRow['external_spu'] ?? '',
        ]);
    }

    public function createFulfillment(array $command): array
    {
        try {
            $products = [];
            foreach ((array)($command['lines'] ?? []) as $line) {
                $products[] = [
                    'vid' => (string)($line['external_sku'] ?? ''),
                    'quantity' => (int)($line['qty'] ?? 1),
                    'storeLineItemId' => (string)($line['line_key'] ?? ''),
                ];
            }
            $shipping = (array)($command['shipping'] ?? []);
            $resp = $this->client()->post('/shopping/order/createOrderV3', [
                'orderNumber' => (string)($command['order_uuid'] ?? ''),
                'shippingCountryCode' => (string)($shipping['country_code'] ?? $shipping['country'] ?? ''),
                'shippingProvince' => (string)($shipping['province'] ?? ''),
                'shippingCity' => (string)($shipping['city'] ?? ''),
                'shippingAddress' => (string)($shipping['street'] ?? $shipping['address'] ?? ''),
                'shippingCustomerName' => (string)($shipping['name'] ?? ''),
                'shippingPhone' => (string)($shipping['phone'] ?? ''),
                'shippingZip' => (string)($shipping['postcode'] ?? $shipping['zip'] ?? ''),
                'fromCountryCode' => (string)($command['from_country_code'] ?? 'CN'),
                'storageId' => (string)($command['storage_id'] ?? ''),
                'products' => $products,
            ]);
            $ok = (int)($resp['code'] ?? 0) === 200 || ($resp['success'] ?? false);
            return [
                'ok' => (bool)$ok,
                'external_order_id' => (string)($resp['data']['orderId'] ?? $resp['data']['orderNumber'] ?? ''),
                'message' => (string)($resp['message'] ?? ''),
                'raw' => $resp,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function cancelFulfillment(array $command): array
    {
        return ['ok' => false, 'skipped' => true, 'message' => 'skipped:unsupported'];
    }

    public function queryFulfillment(array $query): array
    {
        try {
            $resp = $this->client()->get('/shopping/order/getOrderDetail', [
                'orderId' => (string)($query['external_order_id'] ?? ''),
            ]);
            $data = $resp['data'] ?? [];
            return [
                'ok' => true,
                'status' => (string)($data['orderStatus'] ?? 'unknown'),
                'tracking' => [
                    'number' => (string)($data['trackNumber'] ?? ''),
                    'carrier' => (string)($data['logisticName'] ?? ''),
                ],
                'raw' => is_array($data) ? $data : [],
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function quoteFreight(array $request): array
    {
        try {
            $resp = $this->client()->post('/logistic/freightCalculate', $request);
            $list = $resp['data'] ?? [];
            $out = [];
            foreach ((array)$list as $i => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $out[] = [
                    'code' => 'cj_' . $i,
                    'title' => (string)($row['logisticName'] ?? 'CJ Freight'),
                    'amount_minor' => (int)round(((float)($row['freight'] ?? 0)) * 100),
                    'currency' => 'USD',
                    'meta' => $row,
                ];
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    public function parseWebhook(array $headers, string $body): array
    {
        $payload = json_decode($body, true) ?: [];
        return [
            'ok' => true,
            'event' => (string)($payload['type'] ?? $payload['event'] ?? 'cj.event'),
            'external_id' => (string)($payload['id'] ?? $payload['orderId'] ?? md5($body)),
            'payload' => $payload,
        ];
    }

    private function client(): CjApiClient
    {
        return ObjectManager::getInstance(CjApiClient::class);
    }
}
