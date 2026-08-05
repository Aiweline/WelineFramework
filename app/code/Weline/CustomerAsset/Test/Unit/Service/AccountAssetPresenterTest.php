<?php

declare(strict_types=1);

namespace Weline\CustomerAsset\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\CustomerAsset\Api\CustomerAssetFacadeInterface;
use Weline\CustomerAsset\Service\AccountAssetPresenter;

final class AccountAssetPresenterTest extends TestCase
{
    public function testProjectionIsOwnerScopedBoundedAndNewestFirst(): void
    {
        $assets = $this->createMock(CustomerAssetFacadeInterface::class);
        $assets->expects(self::once())
            ->method('listAccounts')
            ->with(42, 0, 'live', 12)
            ->willReturn([[
                'asset_code' => 'credit',
                'available_minor' => 900,
                'reserved_minor' => 100,
                'reservable_minor' => 800,
                'updated_at' => '2026-07-28 12:00:00',
            ]]);
        $assets->expects(self::once())
            ->method('listLedger')
            ->with(42, 0, 'credit', 'live', 8)
            ->willReturn([
                [
                    'entry_id' => 'older',
                    'event_type' => 'credit',
                    'amount_minor' => 1000,
                    'balance_after_available' => 1000,
                    'created_at' => '2026-07-28 10:00:00',
                ],
                [
                    'entry_id' => 'newer',
                    'event_type' => 'reserve',
                    'amount_minor' => 100,
                    'balance_after_available' => 900,
                    'created_at' => '2026-07-28 11:00:00',
                ],
            ]);

        $view = (new AccountAssetPresenter($assets))->present(42, 0);

        self::assertSame('ready', $view['state']);
        self::assertSame(0, $view['website_id']);
        self::assertSame('credit', $view['accounts'][0]['asset_code']);
        self::assertSame(800, $view['accounts'][0]['reservable_minor']);
        self::assertSame('newer', $view['accounts'][0]['ledger'][0]['entry_id']);
    }

    public function testUnauthenticatedProjectionDoesNotReadAssets(): void
    {
        $assets = $this->createMock(CustomerAssetFacadeInterface::class);
        $assets->expects(self::never())->method('listAccounts');
        $assets->expects(self::never())->method('listLedger');

        $view = (new AccountAssetPresenter($assets))->present(null, 0);

        self::assertSame('unauthenticated', $view['state']);
        self::assertSame([], $view['accounts']);
    }

    public function testReadFailureReturnsSafeErrorState(): void
    {
        $assets = $this->createMock(CustomerAssetFacadeInterface::class);
        $assets->method('listAccounts')
            ->willThrowException(new \RuntimeException('database detail'));

        $view = (new AccountAssetPresenter($assets))->present(42, 0);

        self::assertSame('error', $view['state']);
        self::assertSame('customer_asset_account_projection_failed', $view['error_code']);
        self::assertSame([], $view['accounts']);
    }
}
