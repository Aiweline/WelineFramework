<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\ProductV2ConflictException;
use Weline\Product\Service\ProductVariantMatrixService;

final class ProductVariantMatrixServiceTest extends TestCase
{
    public function testGeneratesStableCartesianMatrixAndOverride(): void
    {
        $service = new ProductVariantMatrixService();
        $axes = [
            ['code' => 'color', 'options' => ['red', 'blue']],
            ['code' => 'size', 'options' => ['s', 'm']],
        ];
        $overrideKey = $service->combinationKey(['color' => 'blue', 'size' => 'm']);

        $rows = $service->generate($axes, 'TSHIRT', [$overrideKey => 'SPECIAL-BLUE-M']);

        self::assertCount(4, $rows);
        self::assertSame(
            [
                'color=red|size=s',
                'color=red|size=m',
                'color=blue|size=s',
                'color=blue|size=m',
            ],
            array_column($rows, 'combination_key'),
        );
        self::assertSame('SPECIAL-BLUE-M', $rows[3]['sku']);
        self::assertCount(4, array_unique(array_column($rows, 'sku')));
    }

    public function testReconcilesCreateRenameAndDisableWithoutLosingExistingIdentity(): void
    {
        $service = new ProductVariantMatrixService();
        $axes = [
            ['code' => 'color', 'options' => ['red', 'blue']],
            ['code' => 'size', 'options' => ['s']],
        ];
        $redKey = $service->combinationKey(['color' => 'red', 'size' => 's']);
        $oldKey = $service->combinationKey(['color' => 'green', 'size' => 's']);
        $existing = [
            [
                'offer_id' => 10,
                'global_offer_uuid' => '10000000-0000-4000-8000-000000000010',
                'sku' => 'TSHIRT-RED-S',
                'combination_key' => $redKey,
                'publish_version' => 3,
                'identity_version' => 4,
                'status' => 'published',
            ],
            [
                'offer_id' => 11,
                'global_offer_uuid' => '10000000-0000-4000-8000-000000000011',
                'sku' => 'TSHIRT-GREEN-S',
                'combination_key' => $oldKey,
                'publish_version' => 1,
                'identity_version' => 2,
                'status' => 'published',
            ],
        ];
        $submitted = [[
            'global_offer_uuid' => '10000000-0000-4000-8000-000000000010',
            'offer_version' => 3,
            'identity_version' => 4,
            'combination' => ['size' => 's', 'color' => 'red'],
            'sku' => 'TSHIRT-RED-S-NEW',
            'amount_minor' => 0,
            'scope_state' => 'explicit',
        ]];

        $plan = $service->reconcile($axes, 'TSHIRT', $submitted, $existing);

        self::assertCount(2, $plan['desired']);
        self::assertCount(1, $plan['update']);
        self::assertCount(1, $plan['create']);
        self::assertCount(1, $plan['disable']);
        self::assertSame(
            '10000000-0000-4000-8000-000000000010',
            $plan['update'][0]['global_offer_uuid'],
        );
        self::assertSame('TSHIRT-RED-S-NEW', $plan['update'][0]['sku']);
        self::assertSame(0, $plan['update'][0]['amount_minor']);
        self::assertSame('TSHIRT-BLUE-S', $plan['create'][0]['sku']);
        self::assertSame('TSHIRT-GREEN-S', $plan['impact'][0]['sku']);
        self::assertSame('disable', $plan['impact'][0]['action']);
    }

    public function testReconcileRejectsStaleVersionAndSkuReservedByRemovedIdentity(): void
    {
        $service = new ProductVariantMatrixService();
        $axes = [['code' => 'color', 'options' => ['red']]];
        $redKey = $service->combinationKey(['color' => 'red']);
        $existing = [[
            'offer_id' => 10,
            'global_offer_uuid' => '10000000-0000-4000-8000-000000000010',
            'sku' => 'RED',
            'combination_key' => $redKey,
            'publish_version' => 3,
            'identity_version' => 4,
            'status' => 'draft',
        ]];

        try {
            $service->reconcile($axes, 'SKU', [[
                'global_offer_uuid' => $existing[0]['global_offer_uuid'],
                'offer_version' => 2,
                'identity_version' => 4,
                'combination' => ['color' => 'red'],
                'sku' => 'RED',
            ]], $existing);
            self::fail('Stale Offer projection version must be rejected');
        } catch (ProductV2ConflictException $exception) {
            self::assertSame('variant_offer_version_conflict', $exception->errorCode);
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('variant_sku_reserved');
        $service->reconcile(
            [['code' => 'color', 'options' => ['blue']]],
            'SKU',
            [['combination' => ['color' => 'blue'], 'sku' => 'RED']],
            $existing,
        );
    }

    public function testCommandContractUsesIdentityPreservingOfferTransitions(): void
    {
        $command = file_get_contents(BP . 'app/code/Weline/Product/Service/ProductAdminCommandService.php');
        $identity = file_get_contents(BP . 'app/code/Weline/Product/Service/ProductIdentityV2Service.php');

        self::assertIsString($command);
        self::assertIsString($identity);
        self::assertStringContainsString("'offer_matrix'", $command);
        self::assertStringContainsString('variantMatrix->reconcile(', $command);
        self::assertStringContainsString('identities->renameSku(', $command);
        self::assertStringContainsString('identities->transitionOfferStatus(', $command);
        self::assertStringContainsString('storeOffers->select(', $command);
        self::assertStringContainsString('writeMatrixPrice(', $command);
        self::assertStringNotContainsString('offers->delete(', $command);
        self::assertStringContainsString('offer.identity.status_changed', $identity);
    }

    public function testRejectsDuplicateAxisOptionAndDuplicateSku(): void
    {
        $service = new ProductVariantMatrixService();

        try {
            $service->generate([
                ['code' => 'color', 'options' => ['red', 'RED']],
            ], 'SKU');
            self::fail('Duplicate option must be rejected');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('variant_axis_option_duplicate', $exception->getMessage());
        }

        $axes = [['code' => 'color', 'options' => ['red', 'blue']]];
        $red = $service->combinationKey(['color' => 'red']);
        $blue = $service->combinationKey(['color' => 'blue']);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('variant_sku_duplicate');
        $service->generate($axes, 'SKU', [$red => 'SAME', $blue => 'same']);
    }
}
