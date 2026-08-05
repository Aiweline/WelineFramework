<?php

declare(strict_types=1);

namespace Weline\CustomerAsset\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\CustomerAsset\Model\AssetAccount;
use Weline\CustomerAsset\Service\CustomerAssetConflictException;
use Weline\CustomerAsset\Service\CustomerAssetService;
use Weline\SystemConfig\Service\CommerceRolloutGate;

/**
 * TEST-P4D-01, TEST-P4D-04 and TEST-P4D-05 state-machine and mode-off obligation coverage.
 *
 * PostgreSQL concurrency and rollback evidence is intentionally separate;
 * this suite exercises only the explicit memory seam.
 */
final class CustomerAssetLedgerReservationTest extends TestCase
{
    private CustomerAssetService $service;

    protected function setUp(): void
    {
        $this->service = CustomerAssetService::forTesting(new CommerceRolloutGate());
        $this->service->enableAllowlist(['website:0']);
        $this->service->credit([
            'customer_id' => 'cust-a',
            'website_id' => 0,
            'asset_code' => 'credit',
            'namespace' => AssetAccount::NS_LIVE,
            'amount_minor' => 1000,
            'event_id' => 'credit-seed-1',
        ]);
    }

    public function testCapacityGuardDoesNotOverReserve(): void
    {
        $first = $this->reserve(1000, 'cart-a-reserve');
        self::assertSame(1000, $first['account']['reserved_minor']);
        self::assertSame(0, $first['account']['reservable_minor']);

        $error = $this->captureConflict(
            fn (): array => $this->reserve(1, 'cart-b-reserve'),
        );
        self::assertSame(CustomerAssetService::ERROR_INSUFFICIENT, $error->errorCode);
        self::assertSame(1000, $this->balance()['reserved_minor']);
        self::assertCount(2, $this->ledger());
    }

    public function testReserveReplayRequiresSameCanonicalRequest(): void
    {
        $first = $this->reserve(400, 'reserve-replay');
        $replayed = $this->reserve(400, 'reserve-replay');
        self::assertTrue($replayed['idempotent']);
        self::assertSame(
            $first['reservation']['reservation_id'],
            $replayed['reservation']['reservation_id'],
        );

        $error = $this->captureConflict(
            fn (): array => $this->reserve(401, 'reserve-replay'),
        );
        self::assertSame(CustomerAssetService::ERROR_DUPLICATE_EVENT, $error->errorCode);
        self::assertSame(400, $this->balance()['reserved_minor']);
        self::assertCount(2, $this->ledger());
    }

    public function testCreditReplayRejectsChangedAmount(): void
    {
        $same = $this->service->credit([
            'customer_id' => 'cust-a',
            'website_id' => 0,
            'asset_code' => 'credit',
            'amount_minor' => 1000,
            'event_id' => 'credit-seed-1',
        ]);
        self::assertTrue($same['idempotent']);

        $error = $this->captureConflict(fn (): array => $this->service->credit([
            'customer_id' => 'cust-a',
            'website_id' => 0,
            'asset_code' => 'credit',
            'amount_minor' => 999,
            'event_id' => 'credit-seed-1',
        ]));
        self::assertSame(CustomerAssetService::ERROR_DUPLICATE_EVENT, $error->errorCode);
        self::assertSame(1000, $this->balance()['available_minor']);
        self::assertCount(1, $this->ledger());
    }

    public function testSandboxAndLiveHaveIndependentAccounts(): void
    {
        $this->service->credit([
            'customer_id' => 'cust-a',
            'website_id' => 0,
            'asset_code' => 'credit',
            'namespace' => AssetAccount::NS_SANDBOX,
            'amount_minor' => 200,
            'event_id' => 'sandbox-credit-1',
        ]);
        $this->service->reserve([
            'customer_id' => 'cust-a',
            'website_id' => 0,
            'asset_code' => 'credit',
            'namespace' => AssetAccount::NS_SANDBOX,
            'amount_minor' => 200,
            'event_id' => 'sandbox-reserve-1',
        ]);

        self::assertSame(1000, $this->balance()['available_minor']);
        self::assertSame(0, $this->balance()['reserved_minor']);
        $sandbox = $this->service->getBalance(
            'cust-a',
            0,
            'credit',
            AssetAccount::NS_SANDBOX,
        );
        self::assertSame(200, $sandbox['available_minor']);
        self::assertSame(200, $sandbox['reserved_minor']);
        self::assertCount(1, $this->ledger());
        self::assertCount(2, $this->service->listLedger(
            'cust-a',
            0,
            'credit',
            AssetAccount::NS_SANDBOX,
        ));
    }

    public function testEventIdIsGlobalAcrossNamespaces(): void
    {
        $error = $this->captureConflict(fn (): array => $this->service->credit([
            'customer_id' => 'cust-a',
            'website_id' => 0,
            'asset_code' => 'credit',
            'namespace' => AssetAccount::NS_SANDBOX,
            'amount_minor' => 1000,
            'event_id' => 'credit-seed-1',
        ]));
        self::assertSame(CustomerAssetService::ERROR_DUPLICATE_EVENT, $error->errorCode);
        self::assertFalse($this->service->getBalance(
            'cust-a',
            0,
            'credit',
            AssetAccount::NS_SANDBOX,
        )['exists']);
    }

    public function testReleaseIsIdempotentAndRestoresReservableBalance(): void
    {
        $reserved = $this->reserve(300, 'release-reserve');
        $reservationId = (string) $reserved['reservation']['reservation_id'];
        $released = $this->service->release($reservationId, 'release-event');
        $replayed = $this->service->release($reservationId, 'release-event');

        self::assertFalse($released['idempotent']);
        self::assertTrue($replayed['idempotent']);
        self::assertSame(0, $this->balance()['reserved_minor']);
        self::assertSame(1000, $this->balance()['reservable_minor']);
        self::assertSame('released', $this->service->getReservation($reservationId)['status']);

        $error = $this->captureConflict(
            fn (): array => $this->service->release($reservationId, 'release-event-2'),
        );
        self::assertSame(CustomerAssetService::ERROR_INVALID_TRANSITION, $error->errorCode);
        self::assertCount(3, $this->ledger());
    }

    public function testCommitDebitsAvailableExactlyOnce(): void
    {
        $reserved = $this->reserve(250, 'commit-reserve');
        $reservationId = (string) $reserved['reservation']['reservation_id'];
        $committed = $this->service->commit($reservationId, 'commit-event');
        $replayed = $this->service->commit($reservationId, 'commit-event');

        self::assertFalse($committed['idempotent']);
        self::assertTrue($replayed['idempotent']);
        self::assertSame(750, $this->balance()['available_minor']);
        self::assertSame(0, $this->balance()['reserved_minor']);
        self::assertSame('committed', $this->service->getReservation($reservationId)['status']);

        $error = $this->captureConflict(
            fn (): array => $this->service->release($reservationId, 'late-release'),
        );
        self::assertSame(CustomerAssetService::ERROR_INVALID_TRANSITION, $error->errorCode);
        self::assertSame(750, $this->balance()['available_minor']);
    }

    public function testModeOffBlocksNewTenderButAllowsExistingSettlement(): void
    {
        $reserved = $this->reserve(100, 'mode-off-reserve');
        $this->service->modeOff();

        $error = $this->captureConflict(
            fn (): array => $this->reserve(1, 'mode-off-new'),
        );
        self::assertSame(CustomerAssetService::ERROR_MODE_OFF, $error->errorCode);

        $released = $this->service->release(
            (string) $reserved['reservation']['reservation_id'],
            'mode-off-release',
        );
        self::assertTrue($released['ok']);
        self::assertSame(0, $this->balance()['reserved_minor']);
        self::assertCount(3, $this->ledger());
    }

    public function testCommittedReservationCanBeReturnedPartiallyWhileModeIsOff(): void
    {
        $reserved = $this->reserve(600, 'return-reserve');
        $reservationId = (string) $reserved['reservation']['reservation_id'];
        $this->service->commit($reservationId, 'return-commit');
        $this->service->modeOff();

        $first = $this->service->returnCommitted(
            $reservationId,
            200,
            'return-event-1',
        );
        $replay = $this->service->returnCommitted(
            $reservationId,
            200,
            'return-event-1',
        );
        $second = $this->service->returnCommitted(
            $reservationId,
            400,
            'return-event-2',
        );

        self::assertFalse($first['idempotent']);
        self::assertTrue($replay['idempotent']);
        self::assertFalse($second['idempotent']);
        self::assertSame(1000, $this->balance()['available_minor']);
        self::assertSame(600, $second['reservation']['returned_amount_minor']);
        self::assertSame('return', $second['entry']['event_type']);
        self::assertCount(5, $this->ledger());
    }

    public function testCommittedReturnRejectsInvalidStateOverReturnAndEventCollision(): void
    {
        $reserved = $this->reserve(300, 'return-guard-reserve');
        $reservationId = (string) $reserved['reservation']['reservation_id'];

        $notCommitted = $this->captureConflict(
            fn (): array => $this->service->returnCommitted(
                $reservationId,
                1,
                'return-before-commit',
            ),
        );
        self::assertSame(
            CustomerAssetService::ERROR_INVALID_TRANSITION,
            $notCommitted->errorCode,
        );

        $this->service->commit($reservationId, 'return-guard-commit');
        $this->service->returnCommitted($reservationId, 200, 'return-guard-event');

        $collision = $this->captureConflict(
            fn (): array => $this->service->returnCommitted(
                $reservationId,
                199,
                'return-guard-event',
            ),
        );
        self::assertSame(CustomerAssetService::ERROR_DUPLICATE_EVENT, $collision->errorCode);

        $overReturn = $this->captureConflict(
            fn (): array => $this->service->returnCommitted(
                $reservationId,
                101,
                'return-over-limit',
            ),
        );
        self::assertSame(
            CustomerAssetService::ERROR_INVALID_TRANSITION,
            $overReturn->errorCode,
        );
        self::assertSame(900, $this->balance()['available_minor']);
        self::assertSame(
            200,
            $this->service->getReservation($reservationId)['returned_amount_minor'],
        );
        self::assertCount(4, $this->ledger());
    }

    public function testListAccountsIsScopedSortedAndBounded(): void
    {
        $this->service->credit([
            'customer_id' => 'cust-a',
            'website_id' => 0,
            'asset_code' => 'points',
            'amount_minor' => 50,
            'event_id' => 'points-credit',
        ]);
        $this->service->credit([
            'customer_id' => 'cust-a',
            'website_id' => 0,
            'asset_code' => 'wcoin',
            'amount_minor' => 25,
            'event_id' => 'wcoin-credit',
        ]);

        $accounts = $this->service->listAccounts('cust-a', 0, 'live', 2);
        self::assertCount(2, $accounts);
        self::assertSame(['credit', 'points'], array_column($accounts, 'asset_code'));
        self::assertSame([], $this->service->listAccounts('other', 0));
    }

    public function testWebsiteZeroAndMissingBalanceAreValid(): void
    {
        self::assertTrue($this->balance()['exists']);
        $missing = $this->service->getBalance('missing', 0, 'credit');
        self::assertFalse($missing['exists']);
        self::assertSame(0, $missing['available_minor']);
        self::assertSame([], $this->service->listLedger('missing', 0, 'credit'));
    }

    /** @return array<string, mixed> */
    private function reserve(int $amount, string $eventId): array
    {
        return $this->service->reserve([
            'customer_id' => 'cust-a',
            'website_id' => 0,
            'asset_code' => 'credit',
            'amount_minor' => $amount,
            'event_id' => $eventId,
        ]);
    }

    /** @return array<string, mixed> */
    private function balance(): array
    {
        return $this->service->getBalance('cust-a', 0, 'credit');
    }

    /** @return list<array<string, mixed>> */
    private function ledger(): array
    {
        return $this->service->listLedger('cust-a', 0, 'credit');
    }

    /** @param callable(): array<string, mixed> $operation */
    private function captureConflict(callable $operation): CustomerAssetConflictException
    {
        try {
            $operation();
            self::fail('Expected CustomerAssetConflictException');
        } catch (CustomerAssetConflictException $exception) {
            return $exception;
        }
    }
}
