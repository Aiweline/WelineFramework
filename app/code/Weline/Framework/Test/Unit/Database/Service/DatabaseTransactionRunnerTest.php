<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Adapter\Sqlite\Connector;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Service\DatabaseTransactionRunner;
use Weline\Framework\Database\Service\DatabaseTransactionRunnerInterface;
use Weline\Framework\Database\Transaction\TransactionCoordinator;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Database\TransactionContext;

final class DatabaseTransactionRunnerTest extends TestCase
{
    public function testTransactionContextHasNoRunAndRunnerImplementsContract(): void
    {
        self::assertTrue(method_exists(TransactionContext::class, 'enter'));
        self::assertTrue(method_exists(TransactionContext::class, 'leave'));
        self::assertTrue(method_exists(TransactionContext::class, 'reset'));
        self::assertFalse(method_exists(TransactionContext::class, 'run'));

        $ref = new \ReflectionClass(DatabaseTransactionRunner::class);
        self::assertTrue($ref->implementsInterface(DatabaseTransactionRunnerInterface::class));
        self::assertTrue(is_a(TransactionCoordinator::class, WriteIntentTransactionCoordinatorInterface::class, true));
    }

    public function testRealSqliteRunCommitsRollsBackAndAlwaysReleasesContext(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }

        $dbPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline_p2a001_tx_' . uniqid('', true) . '.sqlite';
        $connector = new Connector(new ConfigProvider([
            'type' => 'sqlite',
            'database' => '',
            'path' => $dbPath,
            'persistent' => false,
        ]));
        $connection = $this->createMock(ConnectionFactory::class);
        $connection->method('getConnector')->willReturn($connector);
        $runner = new DatabaseTransactionRunner(new TransactionCoordinator());

        try {
            TransactionContext::reset();
            $connector->query(
                'CREATE TABLE p2a001_tx_probe (id INTEGER PRIMARY KEY AUTOINCREMENT, marker TEXT NOT NULL)'
            )->fetch();

            $result = $runner->run($connection, function () use ($connector): string {
                $connector->query(
                    "INSERT INTO p2a001_tx_probe (marker) VALUES ('committed')"
                )->fetch();
                return 'done';
            });

            self::assertSame('done', $result);
            self::assertSame(1, $this->probeCount($connector));
            self::assertNull(TransactionContext::transactionState($connector));
            self::assertFalse($connector->getWrappedConnection()->inTransaction());

            try {
                $runner->run($connection, function () use ($connector): void {
                    $connector->query(
                        "INSERT INTO p2a001_tx_probe (marker) VALUES ('rolled-back')"
                    )->fetch();
                    throw new \RuntimeException('p2a001 rollback probe');
                });
                self::fail('The transaction runner must rethrow the callback failure.');
            } catch (\RuntimeException $exception) {
                self::assertSame('p2a001 rollback probe', $exception->getMessage());
            }

            self::assertSame(1, $this->probeCount($connector));
            self::assertNull(TransactionContext::transactionState($connector));
            self::assertFalse($connector->getWrappedConnection()->inTransaction());
        } finally {
            TransactionContext::reset();
            $connector->close();
            if (is_file($dbPath)) {
                unlink($dbPath);
            }
        }
    }

    private function probeCount(Connector $connector): int
    {
        $rows = $connector->query('SELECT COUNT(*) AS count FROM p2a001_tx_probe')->fetch();
        return (int)($rows[0]['count'] ?? -1);
    }
}
