<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);
\defined('DS') || \define('DS', \DIRECTORY_SEPARATOR);
\defined('APP_PATH') || \define('APP_PATH', BP . 'app' . DS);
\defined('APP_ETC_PATH') || \define('APP_ETC_PATH', APP_PATH . 'etc' . DS);
\defined('APP_CODE_PATH') || \define('APP_CODE_PATH', APP_PATH . 'code' . DS);
\defined('VENDOR_PATH') || \define('VENDOR_PATH', BP . 'vendor' . DS);
\defined('PUB') || \define('PUB', BP . 'pub' . DS);
\defined('DEV') || \define('DEV', false);
\defined('DEBUG') || \define('DEBUG', false);
\defined('SANDBOX') || \define('SANDBOX', false);
require_once APP_CODE_PATH . 'Weline/Framework/Common/functions.php';

use PDO;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Adapter\Sqlite\Connector;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\TransactionContext;

final class TransactionContextTest extends TestCase
{
    private ?string $dbPath = null;
    private ?Connector $connector = null;

    protected function tearDown(): void
    {
        TransactionContext::reset();
        $this->connector?->close();
        $this->connector = null;
        if ($this->dbPath !== null && is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
        $this->dbPath = null;
        parent::tearDown();
    }

    public function testInnerCommitDoesNotCommitOuterTransaction(): void
    {
        $connector = $this->createConnector();
        $connector->query('CREATE TABLE transaction_probe (id INTEGER PRIMARY KEY AUTOINCREMENT, value VARCHAR(32))')->fetch();

        $connector->beginTransaction();
        $connector->query("INSERT INTO transaction_probe (value) VALUES ('outer')")->fetch();
        $connector->beginTransaction();
        $connector->query("INSERT INTO transaction_probe (value) VALUES ('inner')")->fetch();
        $connector->commit();

        self::assertTrue($connector->getWrappedConnection()->inTransaction());
        $connector->rollBack();
        self::assertSame(0, (int)($connector->query('SELECT COUNT(*) AS count FROM transaction_probe')->fetch()[0]['count'] ?? -1));
    }

    public function testOutermostCommitPersistsNestedWrites(): void
    {
        $connector = $this->createConnector();
        $connector->query('CREATE TABLE transaction_probe (id INTEGER PRIMARY KEY AUTOINCREMENT, value VARCHAR(32))')->fetch();

        $connector->beginTransaction();
        $connector->beginTransaction();
        $connector->query("INSERT INTO transaction_probe (value) VALUES ('kept')")->fetch();
        $connector->commit();
        $connector->commit();

        self::assertFalse($connector->getWrappedConnection()->inTransaction());
        self::assertSame(1, (int)($connector->query('SELECT COUNT(*) AS count FROM transaction_probe')->fetch()[0]['count'] ?? -1));
    }

    public function testNestedRollbackInvalidatesWholeTransaction(): void
    {
        $connector = $this->createConnector();
        $connector->query('CREATE TABLE transaction_probe (id INTEGER PRIMARY KEY AUTOINCREMENT, value VARCHAR(32))')->fetch();

        $connector->beginTransaction();
        $connector->beginTransaction();
        $connector->query("INSERT INTO transaction_probe (value) VALUES ('discarded')")->fetch();
        $connector->rollBack();
        $connector->commit();

        self::assertFalse($connector->getWrappedConnection()->inTransaction());
        self::assertSame(0, (int)($connector->query('SELECT COUNT(*) AS count FROM transaction_probe')->fetch()[0]['count'] ?? -1));
    }

    public function testParallelTransactionOnSameDatabaseIsRejectedWithoutLeakingConnection(): void
    {
        $connector = $this->createConnector();
        $parallel = clone $connector;
        $connector->beginTransaction();

        try {
            $parallel->beginTransaction();
            self::fail('A parallel transaction in one request scope must be rejected.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('不能并行开启', $exception->getMessage());
            // Connector clones in one request intentionally hold logical leases
            // to the same pooled PDO, so they observe the outer transaction.
            // The rejected clone must not roll it back or gain transaction depth.
            self::assertTrue($connector->getWrappedConnection()->inTransaction());
        } finally {
            $connector->rollBack();
            self::assertFalse($parallel->getWrappedConnection()->inTransaction());
            $parallel->close();
        }
    }

    private function createConnector(): Connector
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }
        if (!defined('IS_WIN')) {
            define('IS_WIN', PHP_OS_FAMILY === 'Windows');
        }
        if (!defined('PHP_CS')) {
            define('PHP_CS', false);
        }

        $this->dbPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline_transaction_' . uniqid('', true) . '.sqlite';
        $this->connector = new Connector(new ConfigProvider([
            'type' => 'sqlite',
            'database' => '',
            'path' => $this->dbPath,
            'persistent' => false,
        ]));
        return $this->connector;
    }
}
