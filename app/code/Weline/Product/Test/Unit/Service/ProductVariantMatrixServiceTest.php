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

    public function testGeneratedSkuCompactsLongOptionTokens(): void
    {
        $service = new ProductVariantMatrixService();
        $longColor = 'lan-se-shang-yi-hei-se-ku-zi-tao-zhuang2307';
        $rows = $service->generate(
            [
                ['code' => 'color', 'options' => [$longColor]],
                ['code' => 'size', 'options' => ['xxxl130-140jin']],
            ],
            'ZHIZAOSI-9DFA4E25',
        );

        self::assertCount(1, $rows);
        self::assertLessThanOrEqual(48, strlen($rows[0]['sku']));
        self::assertStringStartsWith('ZHIZAOSI-9DFA4E25-', $rows[0]['sku']);
        self::assertStringNotContainsString('TAO-ZHUANG2307', $rows[0]['sku']);
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

    public function testReconcileRejectsStaleVersionAndSkuReservedByActiveIdentity(): void
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
            [['code' => 'color', 'options' => ['red', 'blue']]],
            'SKU',
            [
                [
                    // New SKU on red: do not keep the old uuid here — that identity
                    // still owns SKU RED until blue's steal is rejected.
                    'combination' => ['color' => 'red'],
                    'sku' => 'RED-NEW',
                ],
                [
                    'combination' => ['color' => 'blue'],
                    'sku' => 'RED',
                ],
            ],
            $existing,
        );
    }

    public function testReconcileMigratesSkuWhenObsoleteCombinationRematerializes(): void
    {
        $service = new ProductVariantMatrixService();
        $oldKey = $service->combinationKey(['color' => 'pink', 'size' => 's']);
        $newKey = $service->combinationKey(['size' => 's', 'style_type' => 'pink-skirt']);
        $existing = [[
            'offer_id' => 10,
            'global_offer_uuid' => '10000000-0000-4000-8000-000000000010',
            'sku' => 'PINK-S',
            'combination_key' => $oldKey,
            'publish_version' => 1,
            'identity_version' => 2,
            'status' => 'active',
        ]];

        $plan = $service->reconcile(
            [
                ['code' => 'size', 'options' => ['s']],
                ['code' => 'style_type', 'options' => ['pink-skirt']],
            ],
            'SKU',
            [['combination' => ['size' => 's', 'style_type' => 'pink-skirt'], 'sku' => 'PINK-S']],
            $existing,
        );

        self::assertCount(1, $plan['update']);
        self::assertCount(0, $plan['create']);
        self::assertCount(0, $plan['disable']);
        self::assertSame($newKey, $plan['update'][0]['combination_key']);
        self::assertSame('10000000-0000-4000-8000-000000000010', $plan['update'][0]['global_offer_uuid']);
        self::assertSame(1, $plan['update'][0]['offer_version']);
        self::assertSame(2, $plan['update'][0]['identity_version']);
    }

    public function testReconcilePrefersSkuMigrationOverStaleDraftOnTargetKey(): void
    {
        $service = new ProductVariantMatrixService();
        $obsoleteKey = $service->combinationKey(['color' => 'pink', 'size' => 's']);
        $targetKey = $service->combinationKey(['size' => 's', 'style_type' => 'pink-skirt']);
        $existing = [
            [
                'offer_id' => 10,
                'global_offer_uuid' => '10000000-0000-4000-8000-000000000010',
                'sku' => 'PINK-S',
                'combination_key' => $obsoleteKey,
                'publish_version' => 1,
                'identity_version' => 2,
                'status' => 'draft',
            ],
            [
                'offer_id' => 11,
                'global_offer_uuid' => '10000000-0000-4000-8000-000000000011',
                'sku' => 'STALE-TARGET',
                'combination_key' => $targetKey,
                'publish_version' => 1,
                'identity_version' => 1,
                'status' => 'draft',
            ],
        ];

        $plan = $service->reconcile(
            [
                ['code' => 'size', 'options' => ['s']],
                ['code' => 'style_type', 'options' => ['pink-skirt']],
            ],
            'SKU',
            [['combination' => ['size' => 's', 'style_type' => 'pink-skirt'], 'sku' => 'PINK-S']],
            $existing,
        );

        self::assertCount(1, $plan['update']);
        self::assertCount(0, $plan['create']);
        self::assertCount(1, $plan['disable']);
        self::assertSame('10000000-0000-4000-8000-000000000010', $plan['update'][0]['global_offer_uuid']);
        self::assertSame($targetKey, $plan['update'][0]['combination_key']);
        self::assertSame('10000000-0000-4000-8000-000000000011', $plan['disable'][0]['global_offer_uuid']);
    }

    public function testReconcileIgnoresStaleTargetUuidWhenMigratingBySku(): void
    {
        $service = new ProductVariantMatrixService();
        $obsoleteKey = $service->combinationKey(['color' => 'pink', 'size' => 's']);
        $targetKey = $service->combinationKey(['size' => 's', 'style_type' => 'pink-skirt']);
        $existing = [
            [
                'offer_id' => 10,
                'global_offer_uuid' => '10000000-0000-4000-8000-000000000010',
                'sku' => 'PINK-S',
                'combination_key' => $obsoleteKey,
                'publish_version' => 1,
                'identity_version' => 2,
                'status' => 'draft',
            ],
            [
                'offer_id' => 11,
                'global_offer_uuid' => '10000000-0000-4000-8000-000000000011',
                'sku' => 'STALE-TARGET',
                'combination_key' => $targetKey,
                'publish_version' => 1,
                'identity_version' => 1,
                'status' => 'draft',
            ],
        ];

        $plan = $service->reconcile(
            [
                ['code' => 'size', 'options' => ['s']],
                ['code' => 'style_type', 'options' => ['pink-skirt']],
            ],
            'SKU',
            [[
                'combination' => ['size' => 's', 'style_type' => 'pink-skirt'],
                'sku' => 'PINK-S',
                // Stale occupant uuid attached by key-only import match.
                'global_offer_uuid' => '10000000-0000-4000-8000-000000000011',
            ]],
            $existing,
        );

        self::assertCount(1, $plan['update']);
        self::assertSame('10000000-0000-4000-8000-000000000010', $plan['update'][0]['global_offer_uuid']);
        self::assertSame($targetKey, $plan['update'][0]['combination_key']);
        self::assertSame('10000000-0000-4000-8000-000000000011', $plan['disable'][0]['global_offer_uuid']);
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

    public function testMaterializeOverridesKeepsSparseColorAndStyleTypeRows(): void
    {
        $service = new ProductVariantMatrixService();
        $axes = [
            [
                'code' => 'color',
                'options' => [['value' => 'blue', 'label' => '蓝色印花']],
            ],
            [
                'code' => 'style_type',
                'options' => [['value' => 'pink-set', 'label' => '肉粉上衣粉裙子']],
            ],
            [
                'code' => 'size',
                'options' => [['value' => 's', 'label' => 'S'], ['value' => 'm', 'label' => 'M']],
            ],
        ];
        $rows = $service->materializeOverrides($axes, 'XINYAO', [
            'color=blue|size=s' => 'XINYAO-C-S',
            'size=m|style_type=pink-set' => 'XINYAO-T-M',
        ]);
        self::assertCount(2, $rows);
        $keys = array_column($rows, 'combination_key');
        sort($keys);
        self::assertSame(['color=blue|size=s', 'size=m|style_type=pink-set'], $keys);
    }

    public function testReconcileAcceptsSparseColorAndStyleTypeRows(): void
    {
        $service = new ProductVariantMatrixService();
        $axes = [
            [
                'code' => 'color',
                'options' => [['value' => 'blue', 'label' => '蓝色印花']],
            ],
            [
                'code' => 'style_type',
                'options' => [['value' => 'pink-set', 'label' => '肉粉上衣粉裙子']],
            ],
            [
                'code' => 'size',
                'options' => [['value' => 's', 'label' => 'S'], ['value' => 'm', 'label' => 'M']],
            ],
        ];
        $plan = $service->reconcile($axes, 'XINYAO', [
            [
                'sku' => 'XINYAO-C-S',
                'combination' => ['color' => 'blue', 'size' => 's'],
                'combination_key' => 'color=blue|size=s',
            ],
            [
                'sku' => 'XINYAO-T-M',
                'combination' => ['style_type' => 'pink-set', 'size' => 'm'],
                'combination_key' => 'size=m|style_type=pink-set',
            ],
        ], []);
        self::assertCount(2, $plan['desired']);
        self::assertCount(2, $plan['create']);
        self::assertSame([], $plan['update']);
    }
}
