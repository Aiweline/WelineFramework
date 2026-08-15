<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database\Connection\Adapter\Pgsql;

use PDO;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\ConnectionFactory;

final class PgsqlConnectorDisconnectRecoveryTest extends TestCase
{
    public function testTableExistProbeReacquiresADeadOwnerConnection(): void
    {
        $connector = ConnectionFactory::getInstance()->getConnector();
        $config = $connector->getConfigProvider();
        if (\strtolower($config->getDbType()) !== 'pgsql') {
            self::markTestSkipped('PostgreSQL is required for disconnect recovery evidence.');
        }

        $stale = $connector->getWrappedConnection()->getPdo();
        $staleObjectId = \spl_object_id($stale);
        $backendPid = (int)$stale->query('SELECT pg_backend_pid()')->fetchColumn();
        self::assertGreaterThan(0, $backendPid);

        $dsn = 'pgsql:host=' . $config->getHostName()
            . ';port=' . $config->getHostPort()
            . ';dbname=' . $config->getDatabase();
        $killer = new PDO(
            $dsn,
            $config->getUsername(),
            $config->getPassword(),
            $config->getOptions(),
        );
        $killer->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::assertTrue((bool)$killer
            ->query('SELECT pg_terminate_backend(' . $backendPid . ')')
            ->fetchColumn());

        $disconnected = false;
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            try {
                $stale->query('SELECT 1');
            } catch (\PDOException) {
                $disconnected = true;
                break;
            }
            \usleep(10_000);
        }
        self::assertTrue($disconnected, 'The target PostgreSQL backend did not terminate boundedly.');

        self::assertFalse($connector->tableExist(
            'wls_pg_disconnect_probe_missing_' . \bin2hex(\random_bytes(4)),
        ));
        $reconnected = $connector->getWrappedConnection()->getPdo();
        self::assertNotSame($staleObjectId, \spl_object_id($reconnected));
        self::assertSame('1', (string)$reconnected->query('SELECT 1')->fetchColumn());
    }
}
