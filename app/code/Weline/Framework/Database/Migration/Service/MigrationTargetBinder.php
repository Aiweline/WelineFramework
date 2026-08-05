<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Migration\Service;

use Weline\Framework\Database\DbManager;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\DbManagerFactory;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;

/**
 * 将进程内默认 DB 临时切到隔离 clone（TASK-MIG-P1A apply 门禁）。
 *
 * 模型常通过 DbManagerFactory 取连接，Setup/Schema 服务则直接注入
 * ConnectionFactory；三者必须原子地同步切库，否则命令表面绑定 clone，
 * 实际 DDL 仍可能落到源库。
 */
final class MigrationTargetBinder
{
    /**
     * @param array{hostname?:string,hostport?:int|string,database?:string,username?:string,password?:string,type?:string} $target
     * @return array{ok:bool,fingerprint:string,database:string,previous_database:string}
     */
    public function bindIsolated(array $target): array
    {
        $database = \strtolower(\trim((string)($target['database'] ?? '')));
        if ($database === '') {
            throw new \InvalidArgumentException('migration_target_database_missing');
        }

        /** @var MigrationCloneService $cloneService */
        $cloneService = ObjectManager::getInstance(MigrationCloneService::class);
        $guard = $cloneService->list() === []
            ? new DatabaseFingerprintGuard()
            : $cloneService->guardedFingerprint();
        $fp = $guard->assertIsolatedDatabase([
            'type' => (string)($target['type'] ?? 'pgsql'),
            'hostname' => (string)($target['hostname'] ?? '127.0.0.1'),
            'hostport' => (string)($target['hostport'] ?? '5432'),
            'database' => $database,
            'username' => (string)($target['username'] ?? ''),
        ]);

        /** @var DbManager $dbManager */
        $dbManager = ObjectManager::getInstance(DbManager::class);
        $current = $dbManager->getConfig();
        $previous = (string)$current->getDatabase();

        $data = $current->getData();
        if (!\is_array($data)) {
            $data = [];
        }
        $data['database'] = $database;
        if (isset($target['hostname'])) {
            $data['hostname'] = (string)$target['hostname'];
        }
        if (isset($target['hostport'])) {
            $data['hostport'] = (string)$target['hostport'];
        }
        if (isset($target['username'])) {
            $data['username'] = (string)$target['username'];
        }
        if (\array_key_exists('password', $target)) {
            $data['password'] = (string)$target['password'];
        }
        if (isset($target['type'])) {
            $data['type'] = (string)$target['type'];
        }

        $provider = new ConfigProvider($data);
        $this->rebindManager($dbManager, $provider);
        /** @var DbManagerFactory $factory */
        $factory = ObjectManager::getInstance(DbManagerFactory::class);
        $this->rebindManager($factory, $provider);
        $directFactory = ConnectionFactory::getInstance($provider);
        ObjectManager::setInstance(ConnectionFactory::class, $directFactory);

        return [
            'ok' => true,
            'fingerprint' => $fp,
            'database' => $database,
            'previous_database' => $previous,
        ];
    }

    private function rebindManager(DbManager $dbManager, ConfigProvider $provider): void
    {
        $dbManager->setConfig($provider);
        $ref = new \ReflectionObject($dbManager);
        foreach (['defaultConnectionFactory' => null, 'connections' => []] as $prop => $value) {
            if (!$ref->hasProperty($prop)) {
                continue;
            }
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($dbManager, $value);
        }
        $dbManager->create('default', $provider);
    }
}
