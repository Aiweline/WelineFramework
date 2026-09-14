<?php

declare(strict_types=1);

namespace Weline\Dropship\Extends\Module\Weline_Dropship\DropshipProvider;

use Weline\Dropship\Api\Data\DropshipCatalogSnapshot;
use Weline\Dropship\Interface\DropshipCatalogBrowseProviderInterface;
use Weline\Dropship\Interface\DropshipCatalogProviderInterface;
use Weline\Dropship\Interface\DropshipFreightProviderInterface;
use Weline\Dropship\Interface\DropshipFulfillmentProviderInterface;
use Weline\Dropship\Interface\DropshipWarehouseProviderInterface;
use Weline\Dropship\Interface\DropshipWebhookProviderInterface;
use Weline\Dropship\Model\FakeProviderWarehouse;
use Weline\Framework\Manager\ObjectManager;

/**
 * Dev/test fake provider (Payment FakeCard analogue).
 */
class FakeDropshipProvider implements
    DropshipCatalogBrowseProviderInterface,
    DropshipCatalogProviderInterface,
    DropshipFulfillmentProviderInterface,
    DropshipFreightProviderInterface,
    DropshipWebhookProviderInterface,
    DropshipWarehouseProviderInterface
{
    public function getCode(): string
    {
        return 'fake';
    }

    public function getCapabilities(): array
    {
        return [
            'catalog' => true,
            'browse' => true,
            'browse_country_filter' => true,
            'fulfillment' => true,
            'freight' => true,
            'webhook' => true,
            'webhook_order' => true,
            'webhook_product' => true,
            'webhook_stock' => true,
            'webhook_logistics' => true,
            'webhook_makeup' => true,
            'webhook_private_order' => true,
            'webhook_dispute' => true,
            'warehouse' => true,
        ];
    }

    public function getDisplayMetadata(): array
    {
        return [
            'title' => 'Fake 货源',
            'module' => 'Weline_Dropship',
            'sort_order' => 999,
            'description' => '壳内置演示供应商',
        ];
    }

    public function getConfigSchema(): array
    {
        return ['fields' => []];
    }

    public function probeConnection(array $context = []): array
    {
        return ['ok' => true, 'message' => 'fake_ok'];
    }

    public function searchProducts(array $query): array
    {
        $country = (string)($query['country_code'] ?? 'US');
        $categoryId = trim((string)($query['category_id'] ?? ''));
        $keyword = strtolower(trim((string)($query['keyword'] ?? '')));
        $zh = self::localePrefersZh((string)($query['locale'] ?? ''));
        $all = [
            DropshipCatalogSnapshot::fromArray([
                'provider_code' => 'fake',
                'external_spu' => 'FAKE-SPU-SIMPLE',
                'external_sku' => 'FAKE-SKU-SIMPLE',
                'title' => $zh ? '万能分销演示·简单T恤' : 'Dropship Demo Simple Tee',
                'origin_currency' => 'USD',
                'origin_price_minor' => 1299,
                'qty' => 80,
                'shelf_status' => 'active',
                'country_code' => $country,
                'category_id' => 'fake-apparel',
                'category_path' => $zh ? '演示 / 服饰' : 'Demo / Apparel',
                'media' => [[
                    'url' => 'https://placehold.co/96x96/png?text=Simple',
                    'type' => 'image',
                    'role' => 'thumb',
                ]],
                'variants' => [],
                'suggested_eav' => ['dropship_source' => 'fake'],
            ]),
            DropshipCatalogSnapshot::fromArray([
                'provider_code' => 'fake',
                'external_spu' => 'FAKE-SPU-VARIANT',
                'external_sku' => 'FAKE-SKU-VARIANT',
                'title' => $zh ? '万能分销演示·规格T恤' : 'Dropship Demo Variant Tee',
                'origin_currency' => 'USD',
                'origin_price_minor' => 1999,
                'qty' => 24,
                'shelf_status' => 'active',
                'country_code' => $country,
                'category_id' => 'fake-apparel',
                'category_path' => $zh ? '演示 / 服饰' : 'Demo / Apparel',
                'media' => [[
                    'url' => 'https://placehold.co/96x96/png?text=Variant',
                    'type' => 'image',
                    'role' => 'thumb',
                ]],
                'variants' => [
                    'axes' => [
                        [
                            'code' => 'size',
                            'label' => $zh ? '尺码' : 'Size',
                            'options' => [
                                ['value' => 'S', 'label' => 'S'],
                                ['value' => 'M', 'label' => 'M'],
                            ],
                        ],
                        [
                            'code' => 'color',
                            'label' => $zh ? '颜色' : 'Color',
                            'options' => [
                                ['value' => '白色', 'label' => $zh ? '白色' : 'White'],
                                ['value' => '红色', 'label' => $zh ? '红色' : 'Red'],
                            ],
                        ],
                    ],
                ],
                'suggested_eav' => ['dropship_source' => 'fake'],
            ]),
            DropshipCatalogSnapshot::fromArray([
                'provider_code' => 'fake',
                'external_spu' => 'FAKE-SPU-HOME',
                'external_sku' => 'FAKE-SKU-HOME',
                'title' => $zh ? '演示家居装饰' : 'Fake Home Decor',
                'origin_currency' => 'USD',
                'origin_price_minor' => 2200,
                'qty' => 40,
                'shelf_status' => 'active',
                'country_code' => $country,
                'category_id' => 'fake-home',
                'category_path' => $zh ? '演示 / 家居' : 'Demo / Home',
                'media' => [[
                    'url' => 'https://placehold.co/96x96/png?text=Home',
                    'type' => 'image',
                    'role' => 'thumb',
                ]],
                'suggested_eav' => ['dropship_source' => 'fake'],
            ]),
        ];
        $out = [];
        $spuFilter = trim((string)($query['external_spu'] ?? ''));
        foreach ($all as $snap) {
            if ($spuFilter !== '' && $snap->externalSpu !== $spuFilter) {
                continue;
            }
            if ($categoryId !== '' && $snap->categoryId !== $categoryId) {
                continue;
            }
            if ($keyword !== '' && !str_contains(strtolower($snap->title), $keyword) && !str_contains(strtolower($snap->externalSpu), $keyword)) {
                continue;
            }
            $out[] = $snap;
        }

        return $out;
    }

    public function listCategories(array $query = []): array
    {
        $zh = self::localePrefersZh((string)($query['locale'] ?? ''));
        if ($zh) {
            return [
                ['id' => 'fake-root', 'name' => '演示', 'parent_id' => '', 'level' => 1, 'path' => '演示'],
                ['id' => 'fake-apparel', 'name' => '服饰', 'parent_id' => 'fake-root', 'level' => 2, 'path' => '演示 / 服饰'],
                ['id' => 'fake-home', 'name' => '家居', 'parent_id' => 'fake-root', 'level' => 2, 'path' => '演示 / 家居'],
            ];
        }

        return [
            ['id' => 'fake-root', 'name' => 'Demo', 'parent_id' => '', 'level' => 1, 'path' => 'Demo'],
            ['id' => 'fake-apparel', 'name' => 'Apparel', 'parent_id' => 'fake-root', 'level' => 2, 'path' => 'Demo / Apparel'],
            ['id' => 'fake-home', 'name' => 'Home', 'parent_id' => 'fake-root', 'level' => 2, 'path' => 'Demo / Home'],
        ];
    }

    private static function localePrefersZh(string $locale): bool
    {
        $locale = strtolower(str_replace('-', '_', trim($locale)));

        return $locale === '' || str_starts_with($locale, 'zh');
    }

    public function getProduct(array $identity): ?DropshipCatalogSnapshot
    {
        $spu = trim((string)($identity['external_spu'] ?? ''));
        $items = $this->searchProducts($identity);
        if ($spu === '') {
            return $items[0] ?? null;
        }
        foreach ($items as $item) {
            if ($item->externalSpu === $spu) {
                return $item;
            }
        }

        return null;
    }

    public function syncCatalogSnapshot(array $listingRow): ?DropshipCatalogSnapshot
    {
        return $this->getProduct([
            'external_spu' => $listingRow['external_spu'] ?? 'FAKE-SPU-SIMPLE',
        ]);
    }

    public function createFulfillment(array $command): array
    {
        return [
            'ok' => true,
            'external_order_id' => 'FAKE-ORD-' . substr(md5((string)($command['order_uuid'] ?? '')), 0, 8),
        ];
    }

    public function cancelFulfillment(array $command): array
    {
        return ['ok' => true];
    }

    public function queryFulfillment(array $query): array
    {
        return [
            'ok' => true,
            'status' => 'shipped',
            'tracking' => ['number' => 'FAKE-TRACK-1', 'carrier' => 'FakePost'],
        ];
    }

    public function quoteFreight(array $request): array
    {
        return [[
            'code' => 'fake_standard',
            'title' => 'Fake Standard',
            'amount_minor' => 499,
            'currency' => 'USD',
        ]];
    }

    public function freightOnFailure(): string
    {
        return \Weline\Dropship\Service\DropshipFreightPolicy::ON_FAILURE_FALLBACK_LOCAL;
    }

    public function verifyWebhook(array $headers, string $body): array
    {
        return ['ok' => true];
    }

    public function parseWebhook(array $headers, string $body): array
    {
        $payload = json_decode($body, true);
        if (!\is_array($payload)) {
            $payload = [];
        }

        $topic = strtolower(trim((string)($payload['topic'] ?? '')));
        $event = (string)($payload['type'] ?? $payload['event'] ?? 'order.updated');
        $typeUp = strtoupper(trim((string)($payload['type'] ?? '')));
        if ($topic === '') {
            $topic = match ($typeUp) {
                'PRODUCT', 'VARIANT' => 'product',
                'STOCK' => 'stock',
                'LOGISTIC', 'LOGISTICS' => 'logistics',
                'MAKEUP' => 'makeup',
                'PRIVATE_ORDER' => 'private_order',
                'DISPUTES', 'DISPUTE' => 'dispute',
                'ORDER', 'ORDERSPLIT' => 'order',
                default => 'order',
            };
        }
        $externalId = (string)($payload['id'] ?? $payload['external_event_id'] ?? $payload['messageId'] ?? '');
        if ($externalId === '') {
            $externalId = $body !== '' ? md5($body) : ('fake-event-' . substr(uniqid('', true), -8));
        }

        $catalog = \is_array($payload['catalog'] ?? null) ? $payload['catalog'] : [];
        if ($catalog === [] && \in_array($topic, ['product', 'stock'], true)) {
            $catalog = [
                'external_spu' => (string)($payload['external_spu'] ?? $payload['pid'] ?? ''),
                'external_sku' => (string)($payload['external_sku'] ?? $payload['sku'] ?? ''),
                'qty' => array_key_exists('qty', $payload) ? (int)$payload['qty'] : null,
                'shelf_status' => (string)($payload['shelf_status'] ?? 'active'),
                'origin_price_minor' => isset($payload['origin_price_minor']) ? (int)$payload['origin_price_minor'] : null,
                'origin_currency' => (string)($payload['origin_currency'] ?? 'USD'),
                'title' => (string)($payload['title'] ?? ''),
            ];
        }

        $makeup = \is_array($payload['makeup'] ?? null) ? $payload['makeup'] : [];
        if ($makeup === [] && $topic === 'makeup') {
            $makeup = [
                'external_id' => (string)($payload['makeup_id'] ?? $payload['id'] ?? ''),
                'related_external_order_id' => (string)($payload['related_external_order_id'] ?? $payload['external_order_id'] ?? ''),
                'status' => (string)($payload['status'] ?? 'PAID'),
                'amount_minor' => isset($payload['amount_minor']) ? (int)$payload['amount_minor'] : null,
                'currency' => (string)($payload['currency'] ?? 'USD'),
            ];
        }

        $externalOrderId = (string)($payload['external_order_id'] ?? $payload['orderId'] ?? $payload['id'] ?? '');
        $tracking = $payload['tracking'] ?? $payload['tracking_number'] ?? $payload['trackNumber'] ?? '';
        if (\is_array($tracking)) {
            $trackingNumber = (string)($tracking['number'] ?? '');
            $carrier = (string)($tracking['carrier'] ?? ($payload['carrier'] ?? $payload['logisticName'] ?? ''));
        } else {
            $trackingNumber = (string)$tracking;
            $carrier = (string)($payload['carrier'] ?? $payload['logisticName'] ?? '');
        }

        $fulfillment = [
            'external_order_id' => $externalOrderId,
            'order_uuid' => (string)($payload['order_uuid'] ?? $payload['orderNumber'] ?? ''),
            'tracking_number' => $trackingNumber,
            'carrier' => $carrier,
            'status' => (string)($payload['status'] ?? $payload['orderStatus'] ?? 'updated'),
        ];
        if (\in_array($topic, ['product', 'stock'], true)) {
            $fulfillment = [
                'external_order_id' => '',
                'order_uuid' => '',
                'tracking_number' => '',
                'carrier' => '',
                'status' => '',
            ];
        }

        return [
            'ok' => true,
            'event' => $event,
            'topic' => $topic,
            'external_id' => $externalId,
            'fulfillment' => $fulfillment,
            'catalog' => $catalog,
            'makeup' => $makeup,
            'payload' => $payload,
        ];
    }

    public function listWarehouses(array $context = []): array
    {
        $this->ensureSeeded();
        $q = mb_strtolower(trim((string)($context['q'] ?? '')), 'UTF-8');
        $limit = max(1, min(100, (int)($context['limit'] ?? 50)));
        /** @var FakeProviderWarehouse $model */
        $model = ObjectManager::getInstance(FakeProviderWarehouse::class);
        $rows = $model->clear()
            ->where(FakeProviderWarehouse::schema_fields_ENABLED, 1)
            ->order(FakeProviderWarehouse::schema_fields_EXTERNAL_ID, 'ASC')
            ->limit(200)
            ->select()
            ->fetchArray();
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string)($row[FakeProviderWarehouse::schema_fields_EXTERNAL_ID] ?? ''));
            $name = trim((string)($row[FakeProviderWarehouse::schema_fields_NAME] ?? $id));
            $country = strtoupper(trim((string)($row[FakeProviderWarehouse::schema_fields_COUNTRY_CODE] ?? '')));
            if ($id === '') {
                continue;
            }
            $label = $name . ($country !== '' ? ' (' . $country . ')' : '') . ' [' . $id . ']';
            if ($q !== '') {
                $hay = mb_strtolower($id . ' ' . $name . ' ' . $country . ' ' . $label, 'UTF-8');
                if (!str_contains($hay, $q)) {
                    continue;
                }
            }
            $out[] = ['value' => $id, 'label' => $label, 'country_code' => $country];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    public function pullWarehouses(array $context = []): array
    {
        unset($context);
        $seeds = [
            ['external_id' => 'FAKE-US-1', 'name' => 'Fake US East', 'country_code' => 'US'],
            ['external_id' => 'FAKE-CN-1', 'name' => 'Fake CN Hub', 'country_code' => 'CN'],
            ['external_id' => 'FAKE-EU-1', 'name' => 'Fake EU Hub', 'country_code' => 'DE'],
        ];
        $now = date('Y-m-d H:i:s');
        foreach ($seeds as $seed) {
            /** @var FakeProviderWarehouse $model */
            $model = ObjectManager::getInstance(FakeProviderWarehouse::class);
            $existing = $model->clear()
                ->where(FakeProviderWarehouse::schema_fields_EXTERNAL_ID, $seed['external_id'])
                ->find()
                ->fetch();
            $data = [
                FakeProviderWarehouse::schema_fields_EXTERNAL_ID => $seed['external_id'],
                FakeProviderWarehouse::schema_fields_NAME => $seed['name'],
                FakeProviderWarehouse::schema_fields_COUNTRY_CODE => $seed['country_code'],
                FakeProviderWarehouse::schema_fields_ENABLED => 1,
                FakeProviderWarehouse::schema_fields_UPDATED_AT => $now,
            ];
            if ($existing && $existing->getId()) {
                $existing->setData($data)->save();
            } else {
                $model->clear()->setData($data)->save();
            }
        }

        return $this->listWarehouses(['limit' => 50]);
    }

    /**
     * Shell Gateway sample: dropship/.../provider-gateway/dispatch?provider_code=fake&action=ping
     *
     * @param array<string, mixed> $params
     * @return array{ok:bool,provider:string}
     */
    public function controllerPing(array $params = []): array
    {
        unset($params);

        return ['ok' => true, 'provider' => $this->getCode()];
    }

    private function ensureSeeded(): void
    {
        /** @var FakeProviderWarehouse $model */
        $model = ObjectManager::getInstance(FakeProviderWarehouse::class);
        $any = $model->clear()->limit(1)->select()->fetchArray();
        if (!is_array($any) || $any === []) {
            $this->pullWarehouses([]);
        }
    }
}
