<?php

declare(strict_types=1);

namespace Weline\Dropship\Extends\Module\Weline_Dropship\DropshipProvider;

use Weline\Dropship\Api\Data\DropshipCatalogSnapshot;
use Weline\Dropship\Interface\DropshipCatalogProviderInterface;
use Weline\Dropship\Interface\DropshipFreightProviderInterface;
use Weline\Dropship\Interface\DropshipFulfillmentProviderInterface;
use Weline\Dropship\Interface\DropshipWebhookProviderInterface;

/**
 * Dev/test fake provider (Payment FakeCard analogue).
 */
class FakeDropshipProvider implements
    DropshipCatalogProviderInterface,
    DropshipFulfillmentProviderInterface,
    DropshipFreightProviderInterface,
    DropshipWebhookProviderInterface
{
    public function getCode(): string
    {
        return 'fake';
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
        return [
            DropshipCatalogSnapshot::fromArray([
                'provider_code' => 'fake',
                'external_spu' => 'FAKE-SPU-1',
                'external_sku' => 'FAKE-SKU-1',
                'title' => 'Fake Product',
                'origin_currency' => 'USD',
                'origin_price_minor' => 1000,
                'qty' => 100,
                'shelf_status' => 'active',
                'country_code' => (string)($query['country_code'] ?? 'US'),
            ]),
        ];
    }

    public function getProduct(array $identity): ?DropshipCatalogSnapshot
    {
        $items = $this->searchProducts($identity);

        return $items[0] ?? null;
    }

    public function syncCatalogSnapshot(array $listingRow): ?DropshipCatalogSnapshot
    {
        return $this->getProduct([
            'external_spu' => $listingRow['external_spu'] ?? 'FAKE-SPU-1',
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

    public function parseWebhook(array $headers, string $body): array
    {
        // Align with CjProvider: type|event, id|orderId; normalize keys for shell Inbox→fulfillment.
        $payload = json_decode($body, true);
        if (!\is_array($payload)) {
            $payload = [];
        }

        $event = (string)($payload['type'] ?? $payload['event'] ?? 'order.updated');
        $externalId = (string)($payload['id'] ?? $payload['orderId'] ?? $payload['external_order_id'] ?? $payload['external_event_id'] ?? '');
        if ($externalId === '') {
            $externalId = $body !== '' ? md5($body) : ('fake-event-' . substr(uniqid('', true), -8));
        }

        if (!isset($payload['orderId']) && isset($payload['external_order_id'])) {
            $payload['orderId'] = (string)$payload['external_order_id'];
        }
        if (!isset($payload['trackNumber']) && isset($payload['tracking'])) {
            $tracking = $payload['tracking'];
            $payload['trackNumber'] = \is_array($tracking)
                ? (string)($tracking['number'] ?? '')
                : (string)$tracking;
        }
        if (!isset($payload['logisticName']) && isset($payload['carrier'])) {
            $payload['logisticName'] = (string)$payload['carrier'];
        }
        if (!isset($payload['orderStatus']) && isset($payload['status'])) {
            $payload['orderStatus'] = (string)$payload['status'];
        }

        return [
            'ok' => true,
            'event' => $event,
            'external_id' => $externalId,
            'payload' => $payload,
        ];
    }
}
