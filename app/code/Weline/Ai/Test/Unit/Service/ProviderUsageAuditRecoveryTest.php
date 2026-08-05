<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Model\Provider\Account;
use Weline\Ai\Model\Provider\UsageRecord;
use Weline\Ai\Service\Provider\AccountService;
use Weline\Ai\Service\Provider\UsageAuditOutbox;
use Weline\Framework\Manager\ObjectManager;

final class BusyOnceUsagePersistenceAccountService extends AccountService
{
    public int $persistenceAttempts = 0;
    public int $balanceDebits = 0;

    /** @var array<string,UsageRecord> */
    private array $records = [];

    protected function findUsageRecordByRequestId(string $requestId): ?UsageRecord
    {
        return $this->records[$requestId] ?? null;
    }

    protected function insertUsageRecordAndDebit(array $payload): UsageRecord
    {
        ++$this->persistenceAttempts;
        if ($this->persistenceAttempts === 1) {
            throw new \RuntimeException('SQLSTATE[HY000]: General error: 5 database is locked');
        }

        $requestId = (string)$payload[UsageRecord::schema_fields_REQUEST_ID];
        $record = ObjectManager::getInstance(UsageRecord::class);
        $record->reset()->setData($payload);
        $record->setData(UsageRecord::schema_fields_BALANCE_APPLIED, 1);
        $this->records[$requestId] = $record;
        if (($payload[UsageRecord::schema_fields_STATUS] ?? '') === 'success') {
            ++$this->balanceDebits;
        }

        return $record;
    }
}

final class ExistingUnbalancedUsageAccountService extends AccountService
{
    public int $balanceDebits = 0;

    private UsageRecord $existing;

    public function seedExisting(string $requestId): void
    {
        $this->existing = ObjectManager::getInstance(UsageRecord::class);
        $this->existing->reset()->setData([
            UsageRecord::schema_fields_REQUEST_ID => $requestId,
            UsageRecord::schema_fields_ACCOUNT_ID => 73,
            UsageRecord::schema_fields_PROVIDER_CODE => 'deepseek',
            UsageRecord::schema_fields_MODEL_CODE => 'deepseek-chat',
            UsageRecord::schema_fields_REQUEST_TYPE => 'pagebuilder_block',
            UsageRecord::schema_fields_PROMPT_TOKENS => 1200,
            UsageRecord::schema_fields_COMPLETION_TOKENS => 800,
            UsageRecord::schema_fields_TOTAL_TOKENS => 2000,
            UsageRecord::schema_fields_INPUT_COST => 0.0012,
            UsageRecord::schema_fields_OUTPUT_COST => 0.0016,
            UsageRecord::schema_fields_TOTAL_COST => 0.0028,
            UsageRecord::schema_fields_CURRENCY => 'USD',
            UsageRecord::schema_fields_STATUS => 'success',
            UsageRecord::schema_fields_BALANCE_APPLIED => 0,
        ]);
    }

    protected function findUsageRecordByRequestId(string $requestId): ?UsageRecord
    {
        return isset($this->existing)
            && $this->existing->getData(UsageRecord::schema_fields_REQUEST_ID) === $requestId
            ? $this->existing
            : null;
    }

    protected function insertUsageRecordAndDebit(array $payload): UsageRecord
    {
        throw new \LogicException('An existing usage record must be reconciled instead of inserted.');
    }

    protected function reconcileExistingUsageRecord(UsageRecord $record, array $payload): UsageRecord
    {
        if ((int)$record->getData(UsageRecord::schema_fields_BALANCE_APPLIED) !== 1) {
            ++$this->balanceDebits;
            $record->setData(UsageRecord::schema_fields_BALANCE_APPLIED, 1);
        }

        return $record;
    }
}

final class SimulatedAtomicBalanceStore
{
    public int $state = 0;
    public int $debits = 0;
}

final class SimulatedAtomicAccountStore
{
    public float $balance = 100.0;
    public float $totalSpent = 0.0;
    public int $debits = 0;
}

final class AtomicClaimSimulationAccountService extends AccountService
{
    public function __construct(private readonly SimulatedAtomicBalanceStore $store)
    {
        parent::__construct();
    }

    public function claimForTest(UsageRecord $record): bool
    {
        return $this->claimBalanceWithinTransaction($record);
    }

    public function markAppliedForTest(UsageRecord $record): bool
    {
        return $this->markBalanceAppliedWithinTransaction($record);
    }

    protected function atomicBalanceStateUpdate(
        UsageRecord $record,
        int $expectedState,
        int $nextState,
    ): bool {
        if ($this->store->state !== $expectedState) {
            return false;
        }
        $this->store->state = $nextState;
        $record->setData(UsageRecord::schema_fields_BALANCE_APPLIED, $nextState);

        return true;
    }
}

final class DistinctRequestAtomicDebitSimulationAccountService extends AccountService
{
    public function __construct(private readonly SimulatedAtomicAccountStore $store)
    {
        parent::__construct();
    }

    public function debitForTest(int $recordId, string $requestId, float $amount): bool
    {
        $record = ObjectManager::getInstance(UsageRecord::class);
        $record->reset()->setData([
            UsageRecord::schema_fields_ID => $recordId,
            UsageRecord::schema_fields_REQUEST_ID => $requestId,
            UsageRecord::schema_fields_REQUEST_KEY => hash('sha256', $requestId),
            UsageRecord::schema_fields_BALANCE_APPLIED => 0,
        ]);

        return $this->applyBalanceWithinTransaction($record, [
            UsageRecord::schema_fields_STATUS => 'success',
            UsageRecord::schema_fields_ACCOUNT_ID => 73,
            UsageRecord::schema_fields_TOTAL_COST => $amount,
        ]);
    }

    protected function claimBalanceWithinTransaction(UsageRecord $record): bool
    {
        $record->setData(UsageRecord::schema_fields_BALANCE_APPLIED, 2);

        return true;
    }

    protected function markBalanceAppliedWithinTransaction(UsageRecord $record): bool
    {
        $record->setData(UsageRecord::schema_fields_BALANCE_APPLIED, 1);

        return true;
    }

    protected function atomicAccountBalanceDebitWithinTransaction(int $accountId, float $amount): bool
    {
        if ($accountId !== 73) {
            return false;
        }
        $this->store->balance -= $amount;
        $this->store->totalSpent += $amount;
        ++$this->store->debits;

        return true;
    }
}

final class UsageIdentityReplayGuardAccountService extends AccountService
{
    public function assertReplayableForTest(UsageRecord $record): void
    {
        $this->ensureUsageIdentityIsReplayable($record);
    }
}

final class ProviderUsageAuditRecoveryTest extends TestCase
{
    private string $outboxDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outboxDirectory = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'weline-ai-usage-outbox-test-'
            . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->outboxDirectory)) {
            foreach (new \FilesystemIterator(
                $this->outboxDirectory,
                \FilesystemIterator::SKIP_DOTS,
            ) as $entry) {
                if ($entry->isFile()) {
                    unlink($entry->getPathname());
                } elseif ($entry->isDir()) {
                    rmdir($entry->getPathname());
                }
            }
            rmdir($this->outboxDirectory);
        }
        parent::tearDown();
    }

    public function testBusyUsageWriteIsDurableAndReplayDebitsBalanceAtMostOnce(): void
    {
        if (!method_exists(AccountService::class, 'recordUsageReliably')) {
            self::fail('AccountService must expose reliable usage persistence.');
        }
        self::assertTrue(
            class_exists(UsageAuditOutbox::class),
            'UsageAuditOutbox must provide durable evidence outside the locked SQLite database.',
        );

        $outbox = new UsageAuditOutbox($this->outboxDirectory);
        $service = new BusyOnceUsagePersistenceAccountService($outbox);
        $account = ObjectManager::getInstance(Account::class);
        $account->reset()->setData([
            Account::schema_fields_ID => 73,
            Account::schema_fields_PROVIDER_CODE => 'deepseek',
            Account::schema_fields_CURRENCY => 'USD',
            Account::schema_fields_BALANCE => 100,
            Account::schema_fields_TOTAL_SPENT => 0,
        ]);
        $model = ObjectManager::getInstance(AiModel::class);
        $model->reset()->setData([
            AiModel::schema_fields_MODEL_CODE => 'deepseek-chat',
            AiModel::schema_fields_NAME => 'DeepSeek Chat',
            AiModel::schema_fields_TOKEN_PRICE_INPUT => 0.001,
            AiModel::schema_fields_TOKEN_PRICE_OUTPUT => 0.002,
        ]);
        $context = [
            'request_id' => 'SITE-BUILD-home-hero-attempt-1',
            'request_type' => 'pagebuilder_block',
            'request_time' => 32500,
            'status' => 'success',
        ];
        $usage = [
            'prompt_tokens' => 1200,
            'completion_tokens' => 800,
            'total_tokens' => 2000,
        ];

        self::assertFalse($service->recordUsageReliably($account, $model, $usage, $context));
        self::assertSame(1, $outbox->pendingCount());
        self::assertSame(0, $service->balanceDebits);

        $recovery = $service->recoverDeferredUsage(10);
        self::assertSame(1, $recovery['recovered']);
        self::assertSame(0, $recovery['failed']);
        self::assertSame(0, $outbox->pendingCount());
        self::assertSame(1, $service->balanceDebits);

        self::assertTrue($service->recordUsageReliably($account, $model, $usage, $context));
        self::assertSame(1, $service->balanceDebits);
        self::assertSame(0, $outbox->pendingCount());
    }

    public function testExistingSuccessRecordWithoutBalanceMarkerIsReconciledOnlyOnce(): void
    {
        if (!method_exists(AccountService::class, 'recordUsageReliably')) {
            self::fail('AccountService must expose reliable usage persistence.');
        }

        $service = new ExistingUnbalancedUsageAccountService(
            new UsageAuditOutbox($this->outboxDirectory),
        );
        $service->seedExisting('SITE-BUILD-home-hero-crash-window');
        $account = ObjectManager::getInstance(Account::class);
        $account->reset()->setData([
            Account::schema_fields_ID => 73,
            Account::schema_fields_PROVIDER_CODE => 'deepseek',
            Account::schema_fields_CURRENCY => 'USD',
        ]);
        $model = ObjectManager::getInstance(AiModel::class);
        $model->reset()->setData([
            AiModel::schema_fields_MODEL_CODE => 'deepseek-chat',
            AiModel::schema_fields_NAME => 'DeepSeek Chat',
            AiModel::schema_fields_TOKEN_PRICE_INPUT => 0.001,
            AiModel::schema_fields_TOKEN_PRICE_OUTPUT => 0.002,
        ]);
        $usage = [
            'prompt_tokens' => 1200,
            'completion_tokens' => 800,
            'total_tokens' => 2000,
        ];
        $context = [
            'request_id' => 'SITE-BUILD-home-hero-crash-window',
            'request_type' => 'pagebuilder_block',
            'status' => 'success',
        ];

        self::assertTrue($service->recordUsageReliably($account, $model, $usage, $context));
        self::assertTrue($service->recordUsageReliably($account, $model, $usage, $context));
        self::assertSame(1, $service->balanceDebits);
    }

    public function testDuplicateOutboxEventHasOnePendingFileAndTwoWorkersRecoverItOnce(): void
    {
        if (!class_exists(UsageAuditOutbox::class)) {
            self::fail('UsageAuditOutbox must provide durable evidence outside the locked SQLite database.');
        }

        $payload = [
            UsageRecord::schema_fields_REQUEST_ID => 'SITE-BUILD-home-hero-duplicate',
            UsageRecord::schema_fields_ACCOUNT_ID => 73,
            UsageRecord::schema_fields_PROVIDER_CODE => 'deepseek',
            UsageRecord::schema_fields_MODEL_CODE => 'deepseek-chat',
            UsageRecord::schema_fields_REQUEST_TYPE => 'pagebuilder_block',
            UsageRecord::schema_fields_PROMPT_TOKENS => 12,
            UsageRecord::schema_fields_COMPLETION_TOKENS => 8,
            UsageRecord::schema_fields_TOTAL_TOKENS => 20,
            UsageRecord::schema_fields_INPUT_COST => 0.000012,
            UsageRecord::schema_fields_OUTPUT_COST => 0.000016,
            UsageRecord::schema_fields_TOTAL_COST => 0.000028,
            UsageRecord::schema_fields_CURRENCY => 'USD',
            UsageRecord::schema_fields_REQUEST_TIME => 32500,
            UsageRecord::schema_fields_STATUS => 'success',
            UsageRecord::schema_fields_CREATED_AT => 1785398400,
        ];
        $firstWorker = new UsageAuditOutbox($this->outboxDirectory);
        $secondWorker = new UsageAuditOutbox($this->outboxDirectory);

        $firstWorker->defer($payload, new \RuntimeException('database is locked'));
        $replayedPayload = $payload;
        $replayedPayload[UsageRecord::schema_fields_REQUEST_TIME] = 33000;
        $replayedPayload[UsageRecord::schema_fields_CREATED_AT] = 1785398460;
        $secondWorker->defer($replayedPayload, new \RuntimeException('database is locked'));
        self::assertSame(1, $firstWorker->pendingCount());

        $replays = 0;
        $consumer = static function (array $event) use (&$replays): void {
            ++$replays;
        };
        $first = $firstWorker->recover($consumer, 10);
        $second = $secondWorker->recover($consumer, 10);

        self::assertSame(1, $first['recovered']);
        self::assertSame(0, $second['recovered']);
        self::assertSame(1, $replays);
        self::assertSame(0, $firstWorker->pendingCount());
    }

    public function testCorruptEnvelopeAfterValidEnvelopeCannotReusePreviousLoopState(): void
    {
        $outbox = new UsageAuditOutbox($this->outboxDirectory);
        $firstPath = $outbox->defer(
            $this->usagePayload('SITE-BUILD-outbox-valid-a'),
            new \RuntimeException('database is locked'),
        );
        $secondPath = $outbox->defer(
            $this->usagePayload('SITE-BUILD-outbox-valid-b'),
            new \RuntimeException('database is locked'),
        );
        $paths = [$firstPath, $secondPath];
        sort($paths, SORT_STRING);
        file_put_contents($paths[1], '{not-json');

        $consumed = [];
        $result = $outbox->recover(
            static function (array $payload) use (&$consumed): void {
                $consumed[] = (string)$payload[UsageRecord::schema_fields_REQUEST_ID];
            },
            10,
        );

        self::assertSame(1, $result['recovered']);
        self::assertSame(1, $result['failed']);
        self::assertSame(1, $result['dead']);
        self::assertCount(1, $consumed);
        self::assertSame(0, $outbox->pendingCount());
        self::assertCount(
            1,
            glob($this->outboxDirectory . DIRECTORY_SEPARATOR . '*.corrupt.*.dead.json') ?: [],
        );
    }

    public function testFilenameHashMustEqualEnvelopeRequestKeyAndPayloadRequestIdHash(): void
    {
        $outbox = new UsageAuditOutbox($this->outboxDirectory);
        $payload = $this->usagePayload('SITE-BUILD-outbox-filename-mismatch');
        $path = $outbox->defer($payload, new \RuntimeException('database is locked'));
        $wrongHash = str_repeat('0', 64);
        if (str_contains(basename($path), $wrongHash)) {
            $wrongHash = str_repeat('1', 64);
        }
        $wrongPath = $this->outboxDirectory
            . DIRECTORY_SEPARATOR
            . $wrongHash
            . '.pending.json';
        rename($path, $wrongPath);

        $consumed = 0;
        $result = $outbox->recover(
            static function () use (&$consumed): void {
                ++$consumed;
            },
            10,
        );

        self::assertSame(0, $result['recovered']);
        self::assertSame(1, $result['dead']);
        self::assertSame(0, $consumed);
        self::assertSame(0, $outbox->pendingCount());
    }

    public function testEnvelopeRequestKeyMismatchIsQuarantinedWithoutConsumingPayload(): void
    {
        $outbox = new UsageAuditOutbox($this->outboxDirectory);
        $path = $outbox->defer(
            $this->usagePayload('SITE-BUILD-outbox-envelope-key-mismatch'),
            new \RuntimeException('database is locked'),
        );
        $envelope = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $envelope['request_key_sha256'] = str_repeat('f', 64);
        file_put_contents(
            $path,
            json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
        );

        $consumed = 0;
        $result = $outbox->recover(
            static function () use (&$consumed): void {
                ++$consumed;
            },
            10,
        );

        self::assertSame(0, $result['recovered']);
        self::assertSame(1, $result['dead']);
        self::assertSame(0, $consumed);
    }

    public function testOutboxFailureEvidenceNeverPersistsRawExceptionMessage(): void
    {
        $outbox = new UsageAuditOutbox($this->outboxDirectory);
        $sensitive = 'mysql://billing-user:secret@127.0.0.1/audit SQLSTATE table=usage';
        $path = $outbox->defer(
            $this->usagePayload('SITE-BUILD-outbox-redacted-failure'),
            new \RuntimeException($sensitive),
        );
        $raw = (string)file_get_contents($path);
        $envelope = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $failure = $envelope['last_failure'] ?? [];

        self::assertStringNotContainsString($sensitive, $raw);
        self::assertSame(\RuntimeException::class, $failure['class'] ?? null);
        self::assertArrayHasKey('category', $failure);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string)($failure['message_sha256'] ?? ''));
        self::assertArrayNotHasKey('message', $failure);
    }

    public function testRequestLocksUseBoundedShardsInsteadOfUnboundedPerRequestFiles(): void
    {
        $outbox = new UsageAuditOutbox($this->outboxDirectory);
        for ($index = 0; $index < 40; ++$index) {
            $payload = $this->usagePayload('SITE-BUILD-outbox-lock-' . $index);
            $outbox->defer($payload, new \RuntimeException('database is locked'));
            $outbox->acknowledge($payload);
        }

        $lockFiles = glob(
            $this->outboxDirectory
            . DIRECTORY_SEPARATOR
            . '.usage-audit-lock-shard-*.lock',
        ) ?: [];
        self::assertNotEmpty($lockFiles);
        self::assertLessThanOrEqual(
            16,
            count($lockFiles),
            'A fixed shard set bounds lock-file growth without unlinking live lock inodes.',
        );
        foreach ($lockFiles as $lockFile) {
            self::assertMatchesRegularExpression(
                '/\/\\.usage-audit-lock-shard-[a-f0-9]\\.lock$/',
                str_replace('\\', '/', $lockFile),
            );
        }
    }

    public function testStaleLegacyRequestLocksAreCleanedWithABoundedNonBlockingSweep(): void
    {
        $legacyLock = $this->outboxDirectory
            . DIRECTORY_SEPARATOR
            . hash('sha256', 'legacy-request-lock')
            . '.lock';
        mkdir($this->outboxDirectory, 0770, true);
        file_put_contents($legacyLock, '');
        touch($legacyLock, time() - (8 * 86400));

        $outbox = new UsageAuditOutbox($this->outboxDirectory);
        $result = $outbox->recover(static function (): void {
        }, 10);

        self::assertSame(0, $result['recovered']);
        self::assertFileDoesNotExist($legacyLock);
    }

    public function testDeadTransitionFailureDoesNotCountDeadOrLosePendingEnvelope(): void
    {
        $outbox = new UsageAuditOutbox($this->outboxDirectory);
        $path = $outbox->defer(
            $this->usagePayload('SITE-BUILD-outbox-dead-transition-failure'),
            new \RuntimeException('database is locked'),
        );
        $envelope = json_decode(
            (string)file_get_contents($path),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $envelope['attempt_count'] = 9;
        file_put_contents(
            $path,
            json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
        );
        $deadPath = substr($path, 0, -strlen('.pending.json')) . '.dead.json';
        mkdir($deadPath, 0770, true);

        $result = $outbox->recover(
            static function (): void {
                throw new \RuntimeException('consumer remains unavailable');
            },
            10,
        );

        self::assertSame(0, $result['dead']);
        self::assertSame(1, $result['failed']);
        self::assertSame(1, $result['skipped']);
        self::assertSame(1, $outbox->pendingCount());
        self::assertFileExists($path);
        $updated = json_decode(
            (string)file_get_contents($path),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame(10, $updated['attempt_count'] ?? null);
    }

    public function testDistinctRequestsDebitOneAccountWithoutLostUpdate(): void
    {
        $store = new SimulatedAtomicAccountStore();
        $firstWorker = new DistinctRequestAtomicDebitSimulationAccountService($store);
        $secondWorker = new DistinctRequestAtomicDebitSimulationAccountService($store);

        self::assertTrue($firstWorker->debitForTest(101, 'request-distinct-a', 1.25));
        self::assertTrue($secondWorker->debitForTest(102, 'request-distinct-b', 2.75));

        self::assertSame(2, $store->debits);
        self::assertEqualsWithDelta(96.0, $store->balance, 0.000001);
        self::assertEqualsWithDelta(4.0, $store->totalSpent, 0.000001);
    }

    public function testSQLiteTwoConnectionsPreserveBothAtomicAccountDebits(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required for the two-connection contract.');
        }
        if (!is_dir($this->outboxDirectory)) {
            mkdir($this->outboxDirectory, 0770, true);
        }
        $databasePath = $this->outboxDirectory . DIRECTORY_SEPARATOR . 'account-debit.sqlite';
        $first = new \PDO('sqlite:' . $databasePath);
        $second = new \PDO('sqlite:' . $databasePath);
        foreach ([$first, $second] as $connection) {
            $connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $connection->exec('PRAGMA busy_timeout = 5000');
        }
        $first->exec(
            'CREATE TABLE account (id INTEGER PRIMARY KEY, balance NUMERIC NOT NULL, total_spent NUMERIC NOT NULL)',
        );
        $first->exec('INSERT INTO account (id, balance, total_spent) VALUES (73, 100, 0)');

        self::assertSame('100', (string)$first->query('SELECT balance FROM account WHERE id = 73')->fetchColumn());
        self::assertSame('100', (string)$second->query('SELECT balance FROM account WHERE id = 73')->fetchColumn());
        $statement = 'UPDATE account'
            . ' SET balance = balance - :amount, total_spent = total_spent + :amount'
            . ' WHERE id = :id';
        $firstUpdate = $first->prepare($statement);
        $secondUpdate = $second->prepare($statement);
        self::assertTrue($firstUpdate->execute(['amount' => 1.25, 'id' => 73]));
        self::assertSame(1, $firstUpdate->rowCount());
        self::assertTrue($secondUpdate->execute(['amount' => 2.75, 'id' => 73]));
        self::assertSame(1, $secondUpdate->rowCount());

        $row = $first->query(
            'SELECT balance, total_spent FROM account WHERE id = 73',
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertEqualsWithDelta(96.0, (float)$row['balance'], 0.000001);
        self::assertEqualsWithDelta(4.0, (float)$row['total_spent'], 0.000001);
    }

    /**
     * @dataProvider atomicClaimDriverProvider
     */
    public function testAtomicBalanceClaimAllowsOnlyOneWorkerToDebit(string $driver): void
    {
        $store = new SimulatedAtomicBalanceStore();
        $firstWorker = new AtomicClaimSimulationAccountService($store);
        $secondWorker = new AtomicClaimSimulationAccountService($store);
        $firstRecord = $this->claimRecord(91, 'request-atomic-claim');
        $secondRecord = $this->claimRecord(91, 'request-atomic-claim');

        if ($firstWorker->claimForTest($firstRecord)) {
            ++$store->debits;
        }
        if ($secondWorker->claimForTest($secondRecord)) {
            ++$store->debits;
        }

        self::assertSame(1, $store->debits, $driver);
        self::assertSame(2, $store->state, $driver);
        self::assertTrue($firstWorker->markAppliedForTest($firstRecord), $driver);
        self::assertSame(1, $store->state, $driver);
        self::assertFalse($secondWorker->claimForTest($secondRecord), $driver);
        self::assertSame(1, $store->debits, $driver);
    }

    /**
     * @dataProvider atomicClaimDriverProvider
     */
    public function testRolledBackBalanceClaimCanBeRecoveredByAnotherWorker(string $driver): void
    {
        $store = new SimulatedAtomicBalanceStore();
        $firstWorker = new AtomicClaimSimulationAccountService($store);
        $secondWorker = new AtomicClaimSimulationAccountService($store);
        $firstRecord = $this->claimRecord(92, 'request-atomic-recovery');
        $secondRecord = $this->claimRecord(92, 'request-atomic-recovery');

        self::assertTrue($firstWorker->claimForTest($firstRecord), $driver);
        // A database transaction rollback restores the compare-and-set claim.
        $store->state = 0;
        $firstRecord->setData(UsageRecord::schema_fields_BALANCE_APPLIED, 0);
        self::assertTrue($secondWorker->claimForTest($secondRecord), $driver);
        ++$store->debits;
        self::assertTrue($secondWorker->markAppliedForTest($secondRecord), $driver);

        self::assertSame(1, $store->debits, $driver);
        self::assertSame(1, $store->state, $driver);
    }

    /** @return iterable<string,array{string}> */
    public static function atomicClaimDriverProvider(): iterable
    {
        yield 'SQLite atomic UPDATE predicate' => ['sqlite'];
        yield 'MySQL atomic UPDATE predicate' => ['mysql'];
    }

    public function testHistoricalIdentityConflictFailsClosedBeforeReplayDebit(): void
    {
        $record = $this->claimRecord(93, 'request-legacy-conflict');
        $record->setData(
            UsageRecord::schema_fields_REQUEST_IDENTITY_STATUS,
            UsageRecord::REQUEST_IDENTITY_LEGACY_CONFLICT,
        );
        $service = new UsageIdentityReplayGuardAccountService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'AI provider usage request_id has a quarantined historical conflict.',
        );
        $service->assertReplayableForTest($record);
    }

    public function testAtomicBalanceCasUsesRawAdapterAffectedRowsInsteadOfModelFetchObject(): void
    {
        $source = (string)file_get_contents(
            BP . 'app/code/Weline/Ai/Service/Provider/AccountService.php',
        );

        self::assertStringContainsString(
            '$claimQuery = $claim->getQuery(false);',
            $source,
            'Model::fetch() returns the model object and must not be cast as an affected-row result.',
        );
        self::assertStringContainsString(
            '$statement->rowCount() === 1',
            $source,
            'SQLite and MySQL must use the prepared adapter statement affected-row count.',
        );
        self::assertStringNotContainsString(
            'return (bool)$claim->clear()',
            $source,
        );
    }

    public function testAccountBalanceDebitUsesOneAtomicAdapterMutation(): void
    {
        $source = (string)file_get_contents(
            BP . 'app/code/Weline/Ai/Service/Provider/AccountService.php',
        );

        self::assertStringContainsString(
            'atomicAccountBalanceDebitWithinTransaction',
            $source,
        );
        self::assertStringContainsString(
            '->dec(Account::schema_fields_BALANCE, $amount)',
            $source,
        );
        self::assertStringContainsString(
            '->inc(Account::schema_fields_TOTAL_SPENT, $amount)',
            $source,
        );
        self::assertStringNotContainsString(
            '$account->updateBalance(',
            $source,
            'Application-side read/modify/write loses one of two distinct concurrent request debits.',
        );
    }

    /** @return array<string,mixed> */
    private function usagePayload(string $requestId): array
    {
        return [
            UsageRecord::schema_fields_REQUEST_ID => $requestId,
            UsageRecord::schema_fields_ACCOUNT_ID => 73,
            UsageRecord::schema_fields_PROVIDER_CODE => 'deepseek',
            UsageRecord::schema_fields_MODEL_CODE => 'deepseek-chat',
            UsageRecord::schema_fields_REQUEST_TYPE => 'pagebuilder_block',
            UsageRecord::schema_fields_PROMPT_TOKENS => 12,
            UsageRecord::schema_fields_COMPLETION_TOKENS => 8,
            UsageRecord::schema_fields_TOTAL_TOKENS => 20,
            UsageRecord::schema_fields_INPUT_COST => 0.000012,
            UsageRecord::schema_fields_OUTPUT_COST => 0.000016,
            UsageRecord::schema_fields_TOTAL_COST => 0.000028,
            UsageRecord::schema_fields_CURRENCY => 'USD',
            UsageRecord::schema_fields_REQUEST_TIME => 32500,
            UsageRecord::schema_fields_STATUS => 'success',
            UsageRecord::schema_fields_CREATED_AT => 1785398400,
        ];
    }

    private function claimRecord(int $id, string $requestId): UsageRecord
    {
        $record = ObjectManager::getInstance(UsageRecord::class);
        $record->reset()->setData([
            UsageRecord::schema_fields_ID => $id,
            UsageRecord::schema_fields_REQUEST_ID => $requestId,
            UsageRecord::schema_fields_REQUEST_KEY => hash('sha256', $requestId),
            UsageRecord::schema_fields_REQUEST_IDENTITY_STATUS => UsageRecord::REQUEST_IDENTITY_CANONICAL,
            UsageRecord::schema_fields_BALANCE_APPLIED => 0,
        ]);

        return $record;
    }
}
