<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Model\PaymentAttempt;
use Weline\Payment\Model\PaymentIntent;
use Weline\Payment\Model\PaymentTransaction;
use Weline\Payment\Service\PaymentCompatibilityMigrationService;

/**
 * TEST-MIG-P2-08：历史 Transaction（成功/失败/未知 + provider ref）→ 兼容 Intent/Attempt。
 */
final class PaymentCompatibilityMigrationServiceTest extends TestCase
{
    private PaymentCompatibilityMigrationService $svc;

    protected function setUp(): void
    {
        $this->svc = PaymentCompatibilityMigrationService::forTesting();
        $this->svc->seedTransaction('tx-ok', PaymentTransaction::STATUS_SUCCESS, '100.00', 'CNY', 'prov_ok_1');
        $this->svc->seedTransaction('tx-fail', PaymentTransaction::STATUS_FAILED, '50.50', 'CNY', 'prov_fail_1');
        $this->svc->seedTransaction('tx-unknown', 'unknown', '12.34', 'USD', 'prov_unk_1');
    }

    public function testMigP208DeterministicMapConserveAndNoProviderOutbox(): void
    {
        $pre = $this->svc->preflight();
        self::assertTrue($pre['ok']);
        self::assertSame(3, $pre['transaction_count']);
        self::assertSame(0, $pre['provider_calls']);
        self::assertSame(0, $pre['outbox_count']);

        $blocked = $this->svc->apply(null);
        self::assertFalse($blocked['ok']);
        self::assertStringContainsString(
            PaymentCompatibilityMigrationService::ERROR_SHARED_DB,
            $blocked['error'],
        );
        self::assertSame(0, $blocked['mapped']);
    }

    public function testMigP208ApplyOnIsolatedCloneIsIdempotentAndConserves(): void
    {
        $db = [
            'type' => 'pgsql',
            'hostname' => '127.0.0.1',
            'hostport' => '5432',
            'database' => 'mig_clone_p2pay_unit',
            'username' => 'weline',
        ];
        $first = $this->svc->apply($db);
        self::assertTrue($first['ok']);
        self::assertSame(3, $first['mapped']);
        self::assertSame(0, $first['provider_calls']);
        self::assertSame(0, $first['outbox_count']);
        self::assertNotSame('', $first['checkpoint_id']);
        self::assertTrue($first['history_retained']);

        $second = $this->svc->apply($db);
        self::assertTrue($second['ok']);
        self::assertSame(0, $second['mapped']);
        self::assertSame(3, $second['already']);

        $verify = $this->svc->verify($db);
        self::assertTrue($verify['ok'], json_encode($verify['diffs'] ?? []));
        self::assertSame(0, $verify['diff_count']);
        self::assertTrue($verify['history_retained']);

        $okIntent = $this->svc->intents()[$this->svc->compatIntentCode('tx-ok')];
        $okAttempt = $this->svc->attempts()[$this->svc->compatAttemptCode('tx-ok')];
        self::assertSame(PaymentIntent::STATUS_PAID, $okIntent['status']);
        self::assertSame(PaymentAttempt::STATUS_SUCCEEDED, $okAttempt['status']);
        self::assertSame(10000, $okIntent['amount_minor']);
        self::assertSame('CNY', $okIntent['currency_code']);
        self::assertSame('prov_ok_1', $okAttempt['provider_reference']);

        $failAttempt = $this->svc->attempts()[$this->svc->compatAttemptCode('tx-fail')];
        self::assertSame(PaymentAttempt::STATUS_FAILED, $failAttempt['status']);
        self::assertSame(5050, $failAttempt['amount_minor']);

        $unkAttempt = $this->svc->attempts()[$this->svc->compatAttemptCode('tx-unknown')];
        self::assertSame(PaymentAttempt::STATUS_PROCESSING, $unkAttempt['status']);
        self::assertSame(1234, $unkAttempt['amount_minor']);
        self::assertSame('USD', $unkAttempt['payment_currency_code']);
        self::assertSame(PaymentAttempt::NONTERMINAL_GUARD_VALUE, $unkAttempt['nonterminal_guard']);

        // History never deleted.
        self::assertCount(3, $this->svc->transactions());
        self::assertSame(0, $this->svc->providerCallCount());
        self::assertSame(0, $this->svc->outboxCount());
    }

    public function testRollbackModeOffKeepsReadersAndHistory(): void
    {
        $db = [
            'type' => 'pgsql',
            'hostname' => '127.0.0.1',
            'hostport' => '5432',
            'database' => 'mig_clone_p2pay_rb',
            'username' => 'weline',
        ];
        $this->svc->apply($db);
        $rb = $this->svc->rollbackToModeOff();
        self::assertTrue($rb['ok']);
        self::assertTrue($rb['history_retained']);
        self::assertSame(3, $rb['transaction_count']);
        self::assertSame(3, $rb['intent_count']);

        $blocked = $this->svc->apply($db);
        self::assertFalse($blocked['ok']);
        self::assertSame(PaymentCompatibilityMigrationService::ERROR_MODE_OFF, $blocked['error']);
    }

    public function testSharedWelineNameRejectedOnApply(): void
    {
        $result = $this->svc->apply([
            'type' => 'pgsql',
            'hostname' => '127.0.0.1',
            'hostport' => '5432',
            'database' => 'weline',
            'username' => 'weline',
        ]);
        self::assertFalse($result['ok']);
        self::assertStringContainsString('migration_db_denied', $result['error']);
        self::assertSame(0, $result['mapped']);
    }

    public function testExactMinorConversionAndAmbiguousRowsBlockApplyWithoutWrites(): void
    {
        $svc = PaymentCompatibilityMigrationService::forTesting();
        $svc->seedTransaction(
            'tx-exact',
            PaymentTransaction::STATUS_FAILED,
            '90071992547409.91',
            'CNY',
            null,
        );
        $plan = $svc->mapTransaction($svc->transactions()['tx-exact']);
        self::assertSame(9007199254740991, $plan['intent']['amount_minor']);

        $svc->seedTransaction(
            'tx-ambiguous',
            PaymentTransaction::STATUS_SUCCESS,
            '1.00',
            'CNY',
            null,
        );
        $preflight = $svc->preflight();
        self::assertFalse($preflight['ok']);
        self::assertSame(1, $preflight['conflict_count']);
        self::assertSame(
            'provider_reference_required_for_terminal_success',
            $preflight['conflicts'][0]['code'],
        );

        $result = $svc->apply([
            'type' => 'pgsql',
            'hostname' => '127.0.0.1',
            'hostport' => '5432',
            'database' => 'mig_clone_p2pay_conflict',
            'username' => 'weline',
        ]);
        self::assertFalse($result['ok']);
        self::assertSame(0, $result['mapped']);
        self::assertSame([], $svc->intents());
        self::assertSame([], $svc->attempts());
    }

    public function testDuplicateProviderReferenceOwnershipBlocksWholeBatch(): void
    {
        $svc = PaymentCompatibilityMigrationService::forTesting();
        $svc->seedTransaction(
            'tx-owner-a',
            PaymentTransaction::STATUS_SUCCESS,
            '1.00',
            'CNY',
            'provider-owner-1',
        );
        $svc->seedTransaction(
            'tx-owner-b',
            PaymentTransaction::STATUS_SUCCESS,
            '2.00',
            'CNY',
            'provider-owner-1',
        );

        $preflight = $svc->preflight();
        self::assertFalse($preflight['ok']);
        self::assertSame(1, $preflight['conflict_count']);
        self::assertSame('provider_reference_conflict', $preflight['conflicts'][0]['code']);
        self::assertFalse($preflight['plans_truncated']);

        $result = $svc->apply([
            'type' => 'pgsql',
            'hostname' => '127.0.0.1',
            'hostport' => '5432',
            'database' => 'mig_clone_p2pay_provider_conflict',
            'username' => 'weline',
        ]);
        self::assertFalse($result['ok']);
        self::assertSame(0, $result['mapped']);
        self::assertSame([], $svc->intents());
        self::assertSame([], $svc->attempts());
    }
}
