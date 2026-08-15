<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Adapter\Mysql\Connector as MysqlConnector;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Connector as PgsqlConnector;
use Weline\Framework\Database\Connection\Adapter\Sqlite\Connector as SqliteConnector;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Connection\Api\Sql\CreatesTableFromSchemaTrait;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Exception\LinkException;

final class DeclarativeSchemaConnectorContractTest extends TestCase
{
    public function testEverySupportedConnectorPublishesDeclarativeSchemaCreation(): void
    {
        self::assertTrue(method_exists(ConnectorInterface::class, 'createTableFromSchema'));

        foreach ([MysqlConnector::class, PgsqlConnector::class, SqliteConnector::class] as $connector) {
            self::assertContains(
                CreatesTableFromSchemaTrait::class,
                class_uses($connector),
                $connector . ' must implement the shared declarative schema contract.',
            );
            self::assertTrue(method_exists($connector, 'createTableFromSchema'));
        }
    }

    public function testUnavailableDriversFailWithoutEnteringTranslationBootstrap(): void
    {
        $driver = 'wls_missing_driver_' . bin2hex(random_bytes(4));
        foreach ([MysqlConnector::class, PgsqlConnector::class, SqliteConnector::class] as $connectorClass) {
            $connector = new $connectorClass(new ConfigProvider([
                'type' => $driver,
                'database' => ':memory:',
                'prefix' => '',
            ]));

            try {
                $connector->create();
                self::fail($connectorClass . ' accepted an unavailable PDO driver.');
            } catch (LinkException $exception) {
                self::assertStringContainsString($driver, $exception->getMessage());
                self::assertStringContainsString('Available drivers:', $exception->getMessage());
            }
        }
    }

    public function testUnavailableDriverBranchesAreLocalizationIndependent(): void
    {
        foreach ([MysqlConnector::class, PgsqlConnector::class, SqliteConnector::class] as $connectorClass) {
            $source = file_get_contents((new \ReflectionClass($connectorClass))->getFileName());
            self::assertIsString($source);
            $branchStart = strpos($source, '$availableDrivers = PDO::getAvailableDrivers();');
            $poolStart = strpos($source, 'ConnectionPool::acquire(', $branchStart ?: 0);
            self::assertIsInt($branchStart);
            self::assertIsInt($poolStart);
            self::assertStringNotContainsString('__(' , substr($source, $branchStart, $poolStart - $branchStart));
        }
    }
}
