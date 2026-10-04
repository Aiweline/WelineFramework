<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Integration;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Transaction\TransactionCoordinator;
use Weline\Framework\Database\TransactionContext;
use Weline\Websites\Api\Theme\ThemeApplicationReference;
use Weline\Websites\Model\ThemeApplication;
use Weline\Websites\Service\OrmThemeApplicationRepository;

final class ThemeApplicationRepositoryTest extends TestCase
{
    public function testActualDatabasePersistsExactReferenceAndRejectsStaleSave(): void
    {
        self::assertTrue(class_exists(OrmThemeApplicationRepository::class), 'Website application ORM repository is missing.');
        self::assertSame(realpath(dirname(__DIR__, 2) . '/Service/OrmThemeApplicationRepository.php'), (new \ReflectionClass(OrmThemeApplicationRepository::class))->getFileName());
        require_once BP . 'app/code/Weline/Framework/Common/functions.php';
        if (!defined('PROD')) {
            define('PROD', false);
        }
        if (!defined('DEV')) {
            define('DEV', true);
        }
        $path = sys_get_temp_dir() . '/weline_theme_application_' . bin2hex(random_bytes(6)) . '.sqlite';
        $connection = ConnectionFactory::getInstance(new ConfigProvider([
            'type' => 'sqlite', 'database' => '', 'path' => $path, 'persistent' => false,
        ]));
        $connector = $connection->getConnector();
        try {
            $connector->query('CREATE TABLE websites_theme_application (application_id INTEGER PRIMARY KEY AUTOINCREMENT, identity_hash VARCHAR(64) NOT NULL UNIQUE, scope_key VARCHAR(512) NOT NULL, store_mode VARCHAR(16) NOT NULL, area VARCHAR(16) NOT NULL, reference_json TEXT NULL, revision BIGINT NOT NULL DEFAULT 0, metadata_json TEXT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)')->fetch();
            $model = new ThemeApplication();
            $model->setConnection($connection);
            $model->getTable('websites_theme_application');
            $repository = new OrmThemeApplicationRepository($model, new TransactionCoordinator());
            $scope = 'website|0|default||||v1';
            $reference = new ThemeApplicationReference(3, 11, 4, 'default.__website__.default', 'normal', 'frontend');
            self::assertSame(0, $repository->read($scope, 'normal', 'frontend')['revision']);
            $saved = $repository->compareAndSwap($scope, 'normal', 'frontend', $reference, 0);
            self::assertSame(1, $saved['revision']);
            // 新实例读取相同数据库，证明不是实例内存保存。
            $reopened = new OrmThemeApplicationRepository($model, new TransactionCoordinator());
            self::assertSame($reference->toArray(), $reopened->read($scope, 'normal', 'frontend')['reference']->toArray());
            try {
                $repository->compareAndSwap($scope, 'normal', 'frontend', null, 0);
                self::fail('Stale save overwrote a database reference.');
            } catch (\RuntimeException $error) {
                self::assertSame('website_theme_application_revision_conflict:1', $error->getMessage());
            }
            self::assertSame(11, $reopened->read($scope, 'normal', 'frontend')['reference']->themeVersionId);
            $restored = $repository->compareAndSwap($scope, 'normal', 'frontend', null, 1);
            self::assertNull($restored['reference']);
            self::assertSame(2, $reopened->read($scope, 'normal', 'frontend')['revision']);
            $repository->compareAndSwap($scope, 'test', 'frontend', new ThemeApplicationReference(3, 0, 0, 'default.__website__.default', 'test', 'frontend'), 0);
            self::assertSame(0, $reopened->read($scope, 'test', 'frontend')['reference']->themeVersionId);
            self::assertNull($reopened->read($scope, 'normal', 'frontend')['reference']);
            $connector->query("UPDATE websites_theme_application SET reference_json = '{\"theme_id\":3,\"version_owner_scope\":\"default.__website__.default\",\"version_owner_store_mode\":\"test\",\"area\":\"frontend\"}' WHERE store_mode = 'test'")->fetch();
            try {
                $reopened->read($scope, 'test', 'frontend');
                self::fail('Missing historical version fields were silently read as package defaults.');
            } catch (\RuntimeException $error) {
                self::assertSame('website_theme_application_stored_reference_incomplete', $error->getMessage());
            }
        } finally {
            TransactionContext::reset();
            $connector->close();
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
