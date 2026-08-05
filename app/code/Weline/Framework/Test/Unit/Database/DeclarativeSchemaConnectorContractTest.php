<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Adapter\Mysql\Connector as MysqlConnector;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Connector as PgsqlConnector;
use Weline\Framework\Database\Connection\Adapter\Sqlite\Connector as SqliteConnector;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Connection\Api\Sql\CreatesTableFromSchemaTrait;

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
}
