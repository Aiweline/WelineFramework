<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Api\Data\CreateCheckoutGroupCommand;
use Weline\Order\Service\OrderFacade;
use Weline\Order\Service\OrderFacadeConflictException;

final class ProductDownloadOrderSnapshotTest extends TestCase
{
    public function testOrderReadKeepsDigitalFulfillmentSnapshotAndLegacyLineShape(): void
    {
        $metadata = [
            'digital_download' => [
                'schema_version' => 'product-download.v1',
                'global_product_uuid' => '11111111-1111-4111-8111-111111111111',
                'global_offer_uuid' => '22222222-2222-4222-8222-222222222222',
                'assets' => [[
                    'asset_id' => '33333333-3333-4333-8333-333333333333',
                    'asset_revision' => 3,
                    'policy_revision' => 5,
                    'name' => 'Guide.pdf',
                ]],
                'entitlement_policy' => [
                    'download_limit' => 2,
                    'expires_after_days' => 30,
                ],
            ],
        ];
        $facade = OrderFacade::forTesting();
        $command = new CreateCheckoutGroupCommand(
            idempotencyKey: 'download-snapshot',
            requestHash: hash('sha256', 'download-snapshot'),
            websiteId: 0,
            storeId: 2,
            currency: 'CNY',
            customerId: 77,
            lines: [
                [
                    'line_uuid' => 'line-download',
                    'provider_code' => 'product',
                    'global_offer_uuid' => '22222222-2222-4222-8222-222222222222',
                    'name' => 'Download',
                    'qty_minor' => 1,
                    'unit_price_minor' => 1000,
                    'requires_shipping' => false,
                    'fulfillment_metadata' => $metadata,
                ],
                [
                    'line_uuid' => 'line-legacy',
                    'name' => 'Legacy',
                    'qty_minor' => 1,
                    'unit_price_minor' => 500,
                    'requires_shipping' => false,
                    'fulfillment_metadata' => [],
                ],
            ],
        );

        $created = $facade->create($command);
        $items = $facade->get($created->orderUuids[0])->items;

        self::assertSame($metadata, $items[0]['fulfillment_metadata']);
        self::assertSame('product', $items[0]['provider_code']);
        self::assertSame(
            '22222222-2222-4222-8222-222222222222',
            $items[0]['global_offer_uuid'],
        );
        self::assertArrayNotHasKey('fulfillment_metadata', $items[1]);
        self::assertArrayNotHasKey('provider_code', $items[1]);
        self::assertArrayNotHasKey('global_offer_uuid', $items[1]);
    }

    public function testOrderRejectsUnsafeFulfillmentMetadataBeforePlanning(): void
    {
        $deep = ['leaf' => 'ok'];
        for ($i = 0; $i < 10; $i++) {
            $deep = ['next' => $deep];
        }
        $invalid = [
            ['object' => new \stdClass()],
            ['large' => str_repeat('x', 4097)],
            ['nan' => NAN],
            $deep,
        ];

        foreach ($invalid as $index => $metadata) {
            $facade = OrderFacade::forTesting();
            $command = new CreateCheckoutGroupCommand(
                idempotencyKey: 'invalid-download-' . $index,
                requestHash: hash('sha256', 'invalid-download-' . $index),
                lines: [[
                    'name' => 'Unsafe',
                    'qty_minor' => 1,
                    'unit_price_minor' => 1,
                    'fulfillment_metadata' => $metadata,
                ]],
            );
            try {
                $facade->plan($command);
                self::fail('Unsafe fulfillment metadata must be rejected.');
            } catch (OrderFacadeConflictException $exception) {
                self::assertSame(OrderFacade::ERROR_INVALID_COMMAND, $exception->errorCode());
            }
            self::assertSame(0, $facade->writeCount());
        }
    }
}
