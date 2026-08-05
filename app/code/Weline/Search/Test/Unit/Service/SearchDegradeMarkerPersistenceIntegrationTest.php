<?php

declare(strict_types=1);

namespace Weline\Search\Test\Unit\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Search\Model\SearchDegradeState;
use Weline\Search\Service\DatabaseSearchDegradeMarkerStore;
use Weline\Search\Service\SearchDegradeMarker;
use Weline\Search\Service\SearchQueryException;

/**
 * TEST-P3C-04：marker 跨实例持久、watermark gate 与 rollback reason CAS。
 */
final class SearchDegradeMarkerPersistenceIntegrationTest extends TestCase
{
    public function testMarkerIsVisibleAcrossInstancesAndClearsOnlyAtEqualWatermark(): void
    {
        self::assertContains('sqlite', PDO::getAvailableDrivers());
        $path = \sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'weline_p3c002_marker_'
            . \bin2hex(\random_bytes(8))
            . '.sqlite';
        $connection = ConnectionFactory::getInstance(new ConfigProvider([
            'type' => 'sqlite',
            'database' => '',
            'path' => $path,
            'persistent' => false,
        ]));
        $connector = $connection->getConnector();

        try {
            $this->createTable($connector);
            $first = new SearchDegradeMarker(
                new DatabaseSearchDegradeMarkerStore($this->model($connection)),
            );
            $second = new SearchDegradeMarker(
                new DatabaseSearchDegradeMarkerStore($this->model($connection)),
            );

            $marked = $first->mark(0, 'consumer_stopped', 7, 3);
            self::assertTrue($marked['active']);
            self::assertSame(1, $marked['marker_version']);
            self::assertTrue($second->isActive(0));
            self::assertSame(7, $second->get(0)['required_source_watermark']);

            $remarked = $second->mark(0, 'index_read_failed', 9, 4);
            self::assertSame(2, $remarked['marker_version']);
            self::assertSame(9, $remarked['required_source_watermark']);
            self::assertSame('index_read_failed', $first->get(0)['reason']);

            try {
                $first->clearIfRecovered(0, 8, 9);
                self::fail('lagging Search watermark must not clear marker');
            } catch (SearchQueryException $exception) {
                self::assertSame(
                    SearchQueryException::ERROR_RECOVERY_WATERMARK,
                    $exception->errorCode,
                );
            }
            self::assertTrue($second->isActive(0));

            $cleared = $second->clearIfRecovered(0, 9, 9);
            self::assertFalse($cleared['active']);
            self::assertSame(3, $cleared['marker_version']);
            self::assertFalse($first->isActive(0));

            $first->mark(0, 'controlled_e2e_consumer_stop', 10, 9);
            self::assertFalse(
                $second->clearForRollback(0, 'wrong_reason'),
                'rollback must not clear another outage marker',
            );
            self::assertTrue($second->isActive(0));
            self::assertTrue(
                $second->clearForRollback(0, 'controlled_e2e_consumer_stop'),
            );
            self::assertFalse($first->isActive(0));
        } finally {
            $connector->close();
            $connection->close();
            if (\is_file($path)) {
                \unlink($path);
            }
        }

        self::assertFileDoesNotExist($path);
    }

    private function model(ConnectionFactory $connection): SearchDegradeState
    {
        $model = new SearchDegradeState();
        $model->setConnection($connection);
        $model->__init();

        return $model;
    }

    private function createTable(ConnectorInterface $connector): void
    {
        $connector->query(
            'CREATE TABLE search_degrade_state ('
            . 'marker_id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'website_id INTEGER NOT NULL UNIQUE, '
            . 'active INTEGER NOT NULL DEFAULT 0, '
            . "reason VARCHAR(64) NOT NULL DEFAULT '', "
            . 'required_source_watermark INTEGER NOT NULL DEFAULT 0, '
            . 'index_watermark_at_mark INTEGER NOT NULL DEFAULT 0, '
            . 'marker_version INTEGER NOT NULL DEFAULT 0, '
            . "cas_token CHAR(64) NOT NULL DEFAULT '', "
            . 'marked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . 'cleared_at DATETIME NULL, '
            . 'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'
            . ')',
        )->fetch();
    }
}
