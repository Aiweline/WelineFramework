<?php

declare(strict_types=1);

namespace Weline\CustomerAsset\Test\Integration;

use PHPUnit\Framework\TestCase;
use Weline\CustomerAsset\Model\AssetAccount;
use Weline\CustomerAsset\Model\AssetLedger;
use Weline\CustomerAsset\Model\AssetReservation;
use Weline\CustomerAsset\Service\CustomerAssetConflictException;
use Weline\CustomerAsset\Service\CustomerAssetService;
use Weline\Framework\Database\Migration\Service\MigrationTargetBinder;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Service\CommerceRolloutGate;

/**
 * Real PostgreSQL durability, rollback and cross-process balance CAS.
 */
final class CustomerAssetPostgresqlIntegrationTest extends TestCase
{
    private string $customerId;
    private CustomerAssetService $service;

    public static function setUpBeforeClass(): void
    {
        $database = trim((string) getenv('WELINE_CUSTOMER_ASSET_TEST_DATABASE'));
        if ($database === '') {
            self::markTestSkipped(
                'WELINE_CUSTOMER_ASSET_TEST_DATABASE must identify a registered mig_clone_* PostgreSQL database',
            );
        }

        $env = include BP . '/app/etc/env.php';
        $db = is_array($env) ? ($env['db']['master'] ?? $env['db'] ?? []) : [];
        if (!is_array($db)) {
            self::fail('master database config is unavailable');
        }
        $db['database'] = $database;

        ObjectManager::clearInstances();
        $binding = ObjectManager::getInstance(MigrationTargetBinder::class)->bindIsolated($db);
        self::assertSame($database, $binding['database']);
        self::assertNotSame('', $binding['fingerprint']);
    }

    protected function setUp(): void
    {
        $this->customerId = 'p4d1_' . bin2hex(random_bytes(8));
        $this->service = $this->service();
        self::assertSame(
            'pgsql',
            strtolower((string) $this->accountModel()
                ->getConnection()
                ->getConnector()
                ->getConfigProvider()
                ->getDbType()),
        );
        $this->service->credit([
            'customer_id' => $this->customerId,
            'website_id' => 0,
            'asset_code' => 'credit',
            'amount_minor' => 1000,
            'event_id' => $this->customerId . ':credit',
        ]);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testSchemaDeclaresDurableUniqueGuards(): void
    {
        $parser = new SchemaParser();
        $account = $parser->parse(AssetAccount::class);
        $ledger = $parser->parse(AssetLedger::class);
        $reservation = $parser->parse(AssetReservation::class);
        self::assertNotNull($account);
        self::assertNotNull($ledger);
        self::assertNotNull($reservation);
        self::assertContains(
            'uk_customer_asset_identity',
            array_map(static fn ($index): string => $index->name, $account->indexes),
        );
        self::assertContains(
            'uk_customer_asset_event_id',
            array_map(static fn ($index): string => $index->name, $ledger->indexes),
        );
        self::assertContains(
            'uk_customer_asset_reservation_id',
            array_map(static fn ($index): string => $index->name, $reservation->indexes),
        );
        self::assertContains(
            'uk_customer_asset_terminal_event',
            array_map(static fn ($index): string => $index->name, $reservation->indexes),
        );
    }

    public function testFreshServiceReadsDurableReplayAndTerminalFacts(): void
    {
        $fresh = $this->service();
        self::assertSame(1000, $fresh->getBalance(
            $this->customerId,
            0,
            'credit',
        )['available_minor']);

        $reserved = $fresh->reserve([
            'customer_id' => $this->customerId,
            'website_id' => 0,
            'asset_code' => 'credit',
            'amount_minor' => 400,
            'event_id' => $this->customerId . ':reserve',
        ]);
        $replayed = $this->service()->reserve([
            'customer_id' => $this->customerId,
            'website_id' => 0,
            'asset_code' => 'credit',
            'amount_minor' => 400,
            'event_id' => $this->customerId . ':reserve',
        ]);
        self::assertTrue($replayed['idempotent']);
        self::assertSame(
            $reserved['reservation']['reservation_id'],
            $replayed['reservation']['reservation_id'],
        );

        $error = $this->captureConflict(fn (): array => $this->service()->reserve([
            'customer_id' => $this->customerId,
            'website_id' => 0,
            'asset_code' => 'credit',
            'amount_minor' => 401,
            'event_id' => $this->customerId . ':reserve',
        ]));
        self::assertSame(CustomerAssetService::ERROR_DUPLICATE_EVENT, $error->errorCode);

        $committed = $this->service()->commit(
            (string) $reserved['reservation']['reservation_id'],
            $this->customerId . ':commit',
        );
        self::assertSame(600, $committed['account']['available_minor']);
        self::assertSame(0, $committed['account']['reserved_minor']);
        self::assertSame(
            AssetReservation::STATUS_COMMITTED,
            $this->service()->getReservation(
                (string) $reserved['reservation']['reservation_id'],
            )['status'],
        );
        self::assertCount(3, $this->service()->listLedger(
            $this->customerId,
            0,
            'credit',
        ));
    }

    public function testLedgerFailureRollsBackAccountAndReservation(): void
    {
        $failing = new CustomerAssetService(
            rolloutGate: $this->enabledGate(),
            ledgerFactory: static fn (): AssetLedger => ObjectManager::create(
                FailingAssetLedger::class,
                [],
                false,
            ),
        );
        $error = $this->captureConflict(fn (): array => $failing->reserve([
            'customer_id' => $this->customerId,
            'website_id' => 0,
            'asset_code' => 'credit',
            'amount_minor' => 300,
            'event_id' => $this->customerId . ':forced-ledger-failure',
        ]));
        self::assertSame(CustomerAssetService::ERROR_PERSISTENCE, $error->errorCode);

        $balance = $this->service()->getBalance($this->customerId, 0, 'credit');
        self::assertSame(1000, $balance['available_minor']);
        self::assertSame(0, $balance['reserved_minor']);
        self::assertCount(1, $this->service()->listLedger(
            $this->customerId,
            0,
            'credit',
        ));
        self::assertSame(
            0,
            count($this->reservationModel()->clear()
                ->where(
                    AssetReservation::schema_fields_CUSTOMER_ID,
                    $this->customerId,
                )
                ->select()
                ->fetchArray()),
        );
    }

    public function testLiveAndSandboxPersistAsSeparateAccounts(): void
    {
        $sandbox = $this->service()->credit([
            'customer_id' => $this->customerId,
            'website_id' => 0,
            'asset_code' => 'credit',
            'namespace' => AssetAccount::NS_SANDBOX,
            'amount_minor' => 200,
            'event_id' => $this->customerId . ':sandbox-credit',
        ]);
        self::assertSame(AssetAccount::NS_SANDBOX, $sandbox['account']['namespace']);
        self::assertSame(1000, $this->service()->getBalance(
            $this->customerId,
            0,
            'credit',
            AssetAccount::NS_LIVE,
        )['available_minor']);
        self::assertSame(200, $this->service()->getBalance(
            $this->customerId,
            0,
            'credit',
            AssetAccount::NS_SANDBOX,
        )['available_minor']);
        self::assertCount(1, $this->service()->listLedger(
            $this->customerId,
            0,
            'credit',
            AssetAccount::NS_LIVE,
        ));
        self::assertCount(1, $this->service()->listLedger(
            $this->customerId,
            0,
            'credit',
            AssetAccount::NS_SANDBOX,
        ));

        $error = $this->captureConflict(fn (): array => $this->service()->credit([
            'customer_id' => $this->customerId,
            'website_id' => 0,
            'asset_code' => 'credit',
            'namespace' => AssetAccount::NS_SANDBOX,
            'amount_minor' => 1000,
            'event_id' => $this->customerId . ':credit',
        ]));
        self::assertSame(CustomerAssetService::ERROR_DUPLICATE_EVENT, $error->errorCode);
        self::assertSame(200, $this->service()->getBalance(
            $this->customerId,
            0,
            'credit',
            AssetAccount::NS_SANDBOX,
        )['available_minor']);
    }

    public function testTwoProcessesCannotReserveTheSameBalanceTwice(): void
    {
        $lockPath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'weline_p4d1_lock_'
            . bin2hex(random_bytes(8));
        $lock = fopen($lockPath, 'c+');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX));

        $processes = [];
        try {
            foreach (['a', 'b'] as $suffix) {
                $command = [
                    PHP_BINARY,
                    __DIR__ . '/concurrent-reserve-worker.php',
                    $lockPath,
                    $this->customerId,
                    $this->customerId . ':concurrent:' . $suffix,
                ];
                $pipes = [];
                $process = proc_open(
                    $command,
                    [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ],
                    $pipes,
                    BP,
                );
                self::assertIsResource($process);
                fclose($pipes[0]);
                $processes[] = [$process, $pipes];
            }
            self::assertTrue(flock($lock, LOCK_UN));

            $results = [];
            foreach ($processes as [$process, $pipes]) {
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exit = proc_close($process);
                self::assertSame(0, $exit, $stderr);
                $decoded = json_decode(trim((string) $stdout), true);
                self::assertIsArray($decoded, $stdout . $stderr);
                $results[] = $decoded;
            }

            $successes = array_values(array_filter(
                $results,
                static fn (array $row): bool => ($row['status'] ?? '') === 'reserved',
            ));
            $conflicts = array_values(array_filter(
                $results,
                static fn (array $row): bool => ($row['status'] ?? '') === 'conflict',
            ));
            self::assertCount(1, $successes, json_encode($results));
            self::assertCount(1, $conflicts, json_encode($results));
            self::assertContains(
                $conflicts[0]['error_code'],
                [CustomerAssetService::ERROR_CAS, CustomerAssetService::ERROR_INSUFFICIENT],
            );

            $balance = $this->service()->getBalance($this->customerId, 0, 'credit');
            self::assertSame(1000, $balance['available_minor']);
            self::assertSame(1000, $balance['reserved_minor']);
            self::assertSame(0, $balance['reservable_minor']);
            self::assertCount(2, $this->service()->listLedger(
                $this->customerId,
                0,
                'credit',
            ));
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            if (is_file($lockPath)) {
                unlink($lockPath);
            }
        }
        self::assertFileDoesNotExist($lockPath);
    }

    private function service(): CustomerAssetService
    {
        return new CustomerAssetService(rolloutGate: $this->enabledGate());
    }

    private function enabledGate(): CommerceRolloutGate
    {
        $gate = new CommerceRolloutGate();
        $gate->setMode(
            CustomerAssetService::CAPABILITY,
            CommerceRolloutGate::MODE_ALLOWLIST,
            ['website:0'],
        );
        return $gate;
    }

    private function cleanup(): void
    {
        $this->ledgerModel()->clear()
            ->where(AssetLedger::schema_fields_CUSTOMER_ID, $this->customerId)
            ->delete()
            ->fetch();
        $this->reservationModel()->clear()
            ->where(AssetReservation::schema_fields_CUSTOMER_ID, $this->customerId)
            ->delete()
            ->fetch();
        $this->accountModel()->clear()
            ->where(AssetAccount::schema_fields_CUSTOMER_ID, $this->customerId)
            ->delete()
            ->fetch();
    }

    private function accountModel(): AssetAccount
    {
        return ObjectManager::create(AssetAccount::class, [], false);
    }

    private function ledgerModel(): AssetLedger
    {
        return ObjectManager::create(AssetLedger::class, [], false);
    }

    private function reservationModel(): AssetReservation
    {
        return ObjectManager::create(AssetReservation::class, [], false);
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

final class FailingAssetLedger extends AssetLedger
{
    public function save(
        string|array|bool|\Weline\Framework\Database\AbstractModel $data = [],
        string|array $sequence = '',
    ): bool|int {
        throw new \RuntimeException('forced_customer_asset_ledger_failure');
    }
}
