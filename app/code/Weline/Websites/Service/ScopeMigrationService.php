<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Eav\Service\EavScopeMigrationService;
use Weline\Framework\Database\Migration\MigrationManifest;
use Weline\Framework\Database\Migration\Service\MigrationCheckpointService;
use Weline\Framework\Database\Migration\Service\MigrationCloneService;
use Weline\Framework\Database\Migration\Service\MigrationTargetBinder;
use Weline\Framework\Manager\ObjectManager;
use Weline\Queue\Service\QueueScopeMigrationService;
use Weline\Websites\Model\SalesChannel;
use Weline\Websites\Model\Store;
use Weline\Websites\Model\Website;

/**
 * MIG-P1A：Scope 基础迁移编排（preflight / apply / verify / rollback）。
 *
 * apply 必须在隔离 clone（mig_clone_*）上执行：fingerprint → checkpoint → seed → queue → eav。
 */
class ScopeMigrationService
{
    public function __construct(
        private readonly StoreChannelSeedService $seedService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function preflight(): array
    {
        [$websites, $missingStore, $missingChannel] = $this->countMissing();
        $queue = $this->queueMigration()->preflight();
        $eav = $this->eavMigration()->preflight();

        return [
            'websites' => $websites,
            'websites_missing_store' => $missingStore,
            'stores_missing_channel' => $missingChannel,
            'queue_legacy_rows' => $queue['legacy_rows'],
            'queue_mappable' => $queue['mappable'],
            'queue_cancelled' => $queue['cancelled'],
            'queue_quarantine' => $queue['quarantine'],
            'queue_conservation_ok' => $queue['conservation_ok'],
            'eav_value_tables' => $eav['value_tables'],
            'eav_missing_scope_columns' => $eav['missing_scope_columns'],
            'eav_legacy_rows_sample_tables' => $eav['legacy_rows_sample_tables'],
        ];
    }

    /**
     * @param array{hostname?:string,hostport?:int|string,database?:string,username?:string,password?:string,type?:string}|null $targetDb
     * @return array<string, mixed>
     */
    public function apply(?array $targetDb = null): array
    {
        $db = $this->requireIsolatedTarget($targetDb);
        // 先清单例，再 bind：避免 clear 后从 env.php 重建回共享库。
        ObjectManager::clearInstances();
        $binder = ObjectManager::getInstance(MigrationTargetBinder::class);
        $bind = $binder->bindIsolated($db);

        /** @var StoreChannelSeedService $seedService */
        $seedService = ObjectManager::getInstance(StoreChannelSeedService::class);
        /** @var QueueScopeMigrationService $queueMigration */
        $queueMigration = ObjectManager::getInstance(QueueScopeMigrationService::class);
        /** @var EavScopeMigrationService $eavMigration */
        $eavMigration = ObjectManager::getInstance(EavScopeMigrationService::class);
        /** @var MigrationCloneService $cloneService */
        $cloneService = ObjectManager::getInstance(MigrationCloneService::class);
        $checkpoint = MigrationCheckpointService::withDefaultStore($cloneService->guardedFingerprint());

        $fp = (string)$bind['fingerprint'];
        $checkpointId = 'p1a-' . \gmdate('YmdHis') . '-' . \substr(\bin2hex(\random_bytes(3)), 0, 6);
        $manifest = MigrationManifest::fromArray([
            'checkpoint_id' => $checkpointId,
            'phase' => 'p1a-apply',
            'repo' => 'framework',
            'branch' => 'local',
            'commit' => 'mig-p1a',
            'connector_fingerprint' => $fp,
            'schema_fingerprints' => [],
            'row_counts' => [],
            'row_hashes' => [],
            'watermarks' => ['queue' => 0, 'eav' => 0],
            'backup_ref' => 'clone:' . $db['database'],
            'created_at' => \gmdate('c'),
        ]);
        $checkpoint->checkpoint($manifest);
        $checkpoint->applyGuard([
            'type' => (string)($db['type'] ?? 'pgsql'),
            'hostname' => (string)($db['hostname'] ?? '127.0.0.1'),
            'hostport' => (string)($db['hostport'] ?? '5432'),
            'database' => (string)$db['database'],
            'username' => (string)($db['username'] ?? ''),
        ], $checkpointId, $manifest);

        $seed = $seedService->ensureDefaults();
        $queue = $queueMigration->apply();
        $eav = $eavMigration->apply();
        $checkpoint->appendJournal($checkpointId, 'p1a_apply_done', [
            'stores_created' => $seed['stores_created'],
            'channels_created' => $seed['channels_created'],
            'queue_mapped' => $queue['mapped'] ?? 0,
            'eav_stamped_global' => $eav['stamped_global'] ?? 0,
        ]);

        return [
            'checkpoint_id' => $checkpointId,
            'manifest_hash' => $manifest->hash(),
            'database' => (string)$db['database'],
            'fingerprint' => $fp,
            'stores_created' => $seed['stores_created'],
            'channels_created' => $seed['channels_created'],
            'queue' => $queue,
            'eav' => $eav,
            'queue_apply_deferred' => false,
        ];
    }

    /**
     * @param array{hostname?:string,hostport?:int|string,database?:string,username?:string,password?:string,type?:string}|null $targetDb
     * @return array<string, mixed>
     */
    public function verify(?array $targetDb = null): array
    {
        if ($targetDb !== null) {
            $db = $this->requireIsolatedTarget($targetDb);
            ObjectManager::clearInstances();
            $binder = ObjectManager::getInstance(MigrationTargetBinder::class);
            $binder->bindIsolated($db);
        }

        [, $missingStore, $missingChannel] = $this->countMissing();
        $queue = $this->queueMigration()->verify();
        $eav = $this->eavMigration()->verify();

        return [
            'ok' => $missingStore === 0 && $missingChannel === 0 && !empty($queue['ok']) && !empty($eav['ok']),
            'websites_missing_store' => $missingStore,
            'stores_missing_channel' => $missingChannel,
            'queue' => $queue,
            'eav' => $eav,
        ];
    }

    /**
     * @param array{hostname?:string,hostport?:int|string,database?:string,username?:string,password?:string,type?:string}|null $targetDb
     * @return array<string, mixed>
     */
    public function rollback(?array $targetDb = null): array
    {
        if ($targetDb !== null) {
            $db = $this->requireIsolatedTarget($targetDb);
            ObjectManager::clearInstances();
            $binder = ObjectManager::getInstance(MigrationTargetBinder::class);
            $binder->bindIsolated($db);
        }

        // TEST-MIG-P1A-08：保留 additive 列；不删除 schema；不放宽 canonical write。
        $eav = $this->eavMigration()->preflight();

        return [
            'ok' => true,
            'message' => (string)__(
                'MIG-P1A rollback：保留 additive Scope 列与已 stamp 的 typed 行；'
                . '兼容 reader 仍可读遗留/ typed；canonical write 不放宽。'
                . '不删除 default Store/Channel（底层禁删保护）。'
            ),
            'additive_columns_retained' => true,
            'eav_with_scope_columns' => $eav['with_scope_columns'] ?? 0,
            'canonical_write_relaxed' => false,
        ];
    }

    /**
     * @param array{hostname?:string,hostport?:int|string,database?:string,username?:string,password?:string,type?:string}|null $targetDb
     * @return array{hostname:string,hostport:string,database:string,username:string,password:string,type:string}
     */
    private function requireIsolatedTarget(?array $targetDb): array
    {
        if ($targetDb === null || \trim((string)($targetDb['database'] ?? '')) === '') {
            throw new \RuntimeException(
                'mig_p1a_requires_isolated_database: pass --database=mig_clone_* '
                . '(create via php bin/w mig:foundation clone-create)'
            );
        }
        $database = \strtolower(\trim((string)$targetDb['database']));
        /** @var MigrationCloneService $cloneService */
        $cloneService = ObjectManager::getInstance(MigrationCloneService::class);
        $guard = $cloneService->list() === []
            ? new \Weline\Framework\Database\Migration\Service\DatabaseFingerprintGuard()
            : $cloneService->guardedFingerprint();
        $config = [
            'type' => (string)($targetDb['type'] ?? 'pgsql'),
            'hostname' => (string)($targetDb['hostname'] ?? '127.0.0.1'),
            'hostport' => (string)($targetDb['hostport'] ?? '5432'),
            'database' => $database,
            'username' => (string)($targetDb['username'] ?? ''),
        ];
        $guard->assertIsolatedDatabase($config);

        return [
            'type' => $config['type'],
            'hostname' => $config['hostname'],
            'hostport' => $config['hostport'],
            'database' => $database,
            'username' => $config['username'],
            'password' => (string)($targetDb['password'] ?? ''),
        ];
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function countMissing(): array
    {
        /** @var Website $websiteModel */
        $websiteModel = ObjectManager::getInstance(Website::class, [], false);
        $connector = $websiteModel->getConnection()->getConnector();
        $link = $connector->getLink();
        $websiteTable = $connector->getTable($websiteModel->getOriginTableName());

        /** @var Store $storeModel */
        $storeModel = ObjectManager::getInstance(Store::class, [], false);
        $storeTable = $connector->getTable($storeModel->getOriginTableName());
        /** @var SalesChannel $channelModel */
        $channelModel = ObjectManager::getInstance(SalesChannel::class, [], false);
        $channelTable = $connector->getTable($channelModel->getOriginTableName());

        $websites = (int)$link->query("SELECT COUNT(*) FROM {$websiteTable}")->fetchColumn();
        $missingStore = (int)$link->query(
            "SELECT COUNT(*) FROM {$websiteTable} w WHERE NOT EXISTS ("
            . " SELECT 1 FROM {$storeTable} s WHERE s.website_id = w.website_id AND s.code = 'default')"
        )->fetchColumn();
        $missingChannel = (int)$link->query(
            "SELECT COUNT(*) FROM {$storeTable} s"
            . " WHERE s.lifecycle_status = 'active' AND s.tombstoned_at IS NULL AND NOT EXISTS ("
            . " SELECT 1 FROM {$channelTable} c WHERE c.store_id = s.store_id AND c.code = 'default')"
        )->fetchColumn();

        return [$websites, $missingStore, $missingChannel];
    }

    private function queueMigration(): QueueScopeMigrationService
    {
        return ObjectManager::getInstance(QueueScopeMigrationService::class);
    }

    private function eavMigration(): EavScopeMigrationService
    {
        return ObjectManager::getInstance(EavScopeMigrationService::class);
    }
}
