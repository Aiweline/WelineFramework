<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database\Compiler;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Compiler\Dialect\DialectInterface;
use Weline\Framework\Database\Compiler\Dialect\MysqlDialect;
use Weline\Framework\Database\Compiler\Dialect\PgsqlDialect;
use Weline\Framework\Database\Compiler\Dialect\SqliteDialect;
use Weline\Framework\Database\Connection\Adapter\Mysql\Connector as MysqlConnector;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Connector as PgsqlConnector;
use Weline\Framework\Database\Connection\Adapter\Sqlite\Connector as SqliteConnector;

/**
 * 可移植方言助手须留在 ORM Dialect/Connector，禁止业务 match(driver) 拼 SQL。
 */
final class DialectPortableExpressionsContractTest extends TestCase
{
    /** @return list<array{0:class-string<DialectInterface>,1:string}> */
    public static function dialectProvider(): array
    {
        return [
            [MysqlDialect::class, 'mysql'],
            [PgsqlDialect::class, 'pgsql'],
            [SqliteDialect::class, 'sqlite'],
        ];
    }

    /**
     * @dataProvider dialectProvider
     * @param class-string<DialectInterface> $class
     */
    public function testDialectExposesPortableHelpers(string $class, string $driver): void
    {
        /** @var DialectInterface $dialect */
        $dialect = new $class();
        self::assertSame($driver, $dialect->getDriverType());
        self::assertNotSame('', $dialect->unixEpochExpression(true));
        self::assertNotSame('', $dialect->unixEpochExpression(false));
        self::assertStringContainsString('created_at', $dialect->timeBucketExpression('created_at', 5));
        self::assertStringContainsString('payload', $dialect->jsonExtractText('payload', 'testId'));
        self::assertStringContainsString('LIMIT', trim($dialect->limitOffset(10, 0)));
    }

    public function testConnectorsPublishSqlDialect(): void
    {
        foreach ([MysqlConnector::class, PgsqlConnector::class, SqliteConnector::class] as $connectorClass) {
            self::assertTrue(method_exists($connectorClass, 'getSqlDialect'));
        }
        self::assertTrue(method_exists(
            \Weline\Framework\Database\Connection\Api\ConnectorInterface::class,
            'getSqlDialect',
        ));
    }

    public function testBusinessInventoryNoLongerOwnsDialectKeywords(): void
    {
        $paths = [
            BP . 'app/code/Weline/Meta/Service/Repository/MetaConfigRepository.php',
            BP . 'app/code/Weline/Order/Service/DisplayNumberAllocator.php',
            BP . 'app/code/Weline/Websites/Service/WebsiteBrandIdentitySeedService.php',
            BP . 'app/code/Weline/Framework/Event/Async/DeliveryRetryPolicy.php',
            BP . 'app/code/Weline/Framework/Service/Query/Store/DatabaseFrontendWorkerCredentialTransaction.php',
            BP . 'app/code/Weline/Visitor/Service/PixelStatisticsService.php',
            BP . 'app/code/Weline/Visitor/Model/Pixel.php',
            BP . 'app/code/Weline/DataTable/Setup/InstallData.php',
        ];
        // 业务盘点文件不得再内嵌引擎方言关键字（Dialect 实现文件除外）
        $forbidden = [
            'ON CONFLICT',
            'ON DUPLICATE KEY',
            'INSERT IGNORE',
            'ENGINE=InnoDB',
            'JSON_EXTRACT(',
            'DATE_FORMAT(',
            "strftime('%s'",
            'UNIX_TIMESTAMP',
            'pgsql:host=',
        ];
        foreach ($paths as $path) {
            self::assertFileExists($path);
            $source = (string)file_get_contents($path);
            foreach ($forbidden as $needle) {
                self::assertStringNotContainsString(
                    $needle,
                    $source,
                    basename($path) . ' still contains dialect fragment: ' . $needle,
                );
            }
        }
    }
}
