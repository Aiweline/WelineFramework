<?php

declare(strict_types=1);

namespace Weline\Framework\Setup\UnitTest;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\DbManager\ConfigProviderInterface;
use Weline\Framework\Setup\Db\Setup;

final class DbSetupTest extends TestCase
{
    public function testGetTableAddsConfiguredPrefixOnce(): void
    {
        $configProvider = $this->createMock(ConfigProviderInterface::class);
        $configProvider->method('getPrefix')->willReturn('pre_');

        $connector = $this->createMock(ConnectorInterface::class);
        $connector->method('getConfigProvider')->willReturn($configProvider);

        $setup = new Setup($connector);

        self::assertSame('pre_table_name', $setup->getTable('table_name'));
        self::assertSame('pre_table_name', $setup->getTable('pre_table_name'));
    }

    public function testTableExistDelegatesToConnectorUsingResolvedTableName(): void
    {
        $configProvider = $this->createMock(ConfigProviderInterface::class);
        $configProvider->method('getPrefix')->willReturn('pre_');

        $connector = $this->createMock(ConnectorInterface::class);
        $connector->method('getConfigProvider')->willReturn($configProvider);
        $connector->expects(self::once())
            ->method('tableExist')
            ->with('pre_aiweline_news')
            ->willReturn(true);

        $setup = new Setup($connector);

        self::assertTrue($setup->tableExist('aiweline_news'));
    }

    public function testSetConnectorReturnsSameInstance(): void
    {
        $connector = $this->createMock(ConnectorInterface::class);
        $setup = new Setup();

        self::assertSame($setup, $setup->setConnector($connector));
        self::assertSame($connector, $setup->getConnector());
    }

    public function testFrameworkBootstrapIncludesCacheNamespaceAuthorityTable(): void
    {
        $stage = new \ReflectionClass(\Weline\Framework\Setup\Stage\FrameworkDbBootstrapStage::class);
        $models = $stage->getReflectionConstant('BOOTSTRAP_MODELS')?->getValue();

        self::assertIsArray($models);
        self::assertContains(
            \Weline\Framework\Model\Cache\NamespaceVersion::class,
            $models,
            'The order-0 framework bootstrap must create the cache namespace authority table.'
        );
    }
}
