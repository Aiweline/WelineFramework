<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Integration;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Transaction\TransactionCoordinator;
use Weline\Framework\Database\TransactionContext;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
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

    public function testRequestMemoReusesReadsAndRepositoryWritesInvalidateIt(): void
    {
        require_once BP . 'app/code/Weline/Framework/Common/functions.php';
        $path = sys_get_temp_dir() . '/weline_theme_application_memo_' . bin2hex(random_bytes(6)) . '.sqlite';
        $connection = ConnectionFactory::getInstance(new ConfigProvider([
            'type' => 'sqlite', 'database' => '', 'path' => $path, 'persistent' => false,
        ]));
        $connector = $connection->getConnector();
        Runtime::setMode('wls');
        if (Context::hasCurrent()) {
            Context::leave();
        }
        RequestContext::cleanup();
        try {
            $connector->query('CREATE TABLE websites_theme_application (application_id INTEGER PRIMARY KEY AUTOINCREMENT, identity_hash VARCHAR(64) NOT NULL UNIQUE, scope_key VARCHAR(512) NOT NULL, store_mode VARCHAR(16) NOT NULL, area VARCHAR(16) NOT NULL, reference_json TEXT NULL, revision BIGINT NOT NULL DEFAULT 0, metadata_json TEXT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)')->fetch();
            $model = new ThemeApplication();
            $model->setConnection($connection);
            $model->getTable('websites_theme_application');
            $repository = new OrmThemeApplicationRepository($model, new TransactionCoordinator());
            $scope = 'website|0|default||||v1';
            $repository->compareAndSwap($scope, 'normal', 'frontend', new ThemeApplicationReference(3, 11, 4, 'default.__website__.default', 'normal', 'frontend'), 0);

            // 无请求上下文（CLI）：每次直读库，外部写入立即可见。
            $connector->query("UPDATE websites_theme_application SET revision = 7")->fetch();
            self::assertSame(7, $repository->read($scope, 'normal', 'frontend')['revision']);
            $connector->query("UPDATE websites_theme_application SET revision = 1")->fetch();
            self::assertSame(1, $repository->read($scope, 'normal', 'frontend')['revision']);

            Context::enter(new Context(['runtime' => ['request_context' => ['initialized' => true]]]));
            RequestContext::setId('theme-application-memo-test');
            self::assertSame(1, $repository->read($scope, 'normal', 'frontend')['revision']);
            $listed = $repository->listForArea('normal', 'frontend');
            self::assertCount(1, $listed);
            self::assertSame($scope, $listed[0]['scope_key']);
            self::assertSame(11, $listed[0]['reference']->themeVersionId);
            self::assertSame([], $repository->listForArea('normal', 'backend'));

            // 同请求内复用快照：库被旁路改写也不再重复查询。
            $connector->query("UPDATE websites_theme_application SET revision = 9")->fetch();
            self::assertSame(1, $repository->read($scope, 'normal', 'frontend')['revision']);
            self::assertSame(1, $repository->listForArea('normal', 'frontend')[0]['revision']);
            $connector->query("UPDATE websites_theme_application SET revision = 1")->fetch();

            // 本仓库写入后同请求立即读到新值。
            $saved = $repository->compareAndSwap($scope, 'normal', 'frontend', null, 1);
            self::assertSame(2, $saved['revision']);
            self::assertSame(2, $repository->read($scope, 'normal', 'frontend')['revision']);
            self::assertNull($repository->listForArea('normal', 'frontend')[0]['reference']);

            // 修订冲突失败后不残留旧快照。
            try {
                $repository->compareAndSwap($scope, 'normal', 'frontend', null, 0);
                self::fail('Stale save overwrote a database reference.');
            } catch (\RuntimeException $error) {
                self::assertSame('website_theme_application_revision_conflict:2', $error->getMessage());
            }
            self::assertSame(2, $repository->read($scope, 'normal', 'frontend')['revision']);
        } finally {
            RequestContext::cleanup();
            if (Context::hasCurrent()) {
                Context::leave();
            }
            Runtime::resetModeCache();
            TransactionContext::reset();
            $connector->close();
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
