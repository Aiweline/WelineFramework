<?php

declare(strict_types=1);

namespace Weline\Storage\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Storage\Api\StorageDriverConfigurationProviderInterface;
use Weline\Storage\Model\StorageConfig;
use Weline\Storage\Service\StorageConfigTester;
use Weline\Storage\Service\StorageDiskUsageGuardRegistry;
use Weline\Storage\Service\StorageDriverProviderRegistry;
use Weline\Storage\Service\StorageManagerV2;

/**
 * @DESC | 后台存储配置控制器
 */
#[Acl('Weline_Storage::storage_config', '存储配置', 'database', '管理文件存储驱动与凭据', 'Weline_Backend::system_service_group')]
class Config extends BackendController
{
    private ?StorageConfig $storageConfig = null;
    private ?StorageConfigTester $storageConfigTester = null;
    private ?StorageDiskUsageGuardRegistry $usageGuards = null;
    private ?StorageDriverProviderRegistry $driverProviders = null;
    
    private function getStorageConfig(): StorageConfig
    {
        if ($this->storageConfig === null) {
            $this->storageConfig = ObjectManager::getInstance(StorageConfig::class);
        }
        return $this->storageConfig;
    }
    
    private function getStorageConfigTester(): StorageConfigTester
    {
        if ($this->storageConfigTester === null) {
            $this->storageConfigTester = ObjectManager::getInstance(StorageConfigTester::class);
        }
        return $this->storageConfigTester;
    }

    private function resetStorageRequestState(): void
    {
        ObjectManager::getInstance(StorageManagerV2::class)->resetRequestState();
    }

    private function getUsageGuards(): StorageDiskUsageGuardRegistry
    {
        return $this->usageGuards ??= ObjectManager::getInstance(StorageDiskUsageGuardRegistry::class);
    }

    private function getDriverProviders(): StorageDriverProviderRegistry
    {
        return $this->driverProviders ??= ObjectManager::getInstance(StorageDriverProviderRegistry::class);
    }
    
    /**
     * 存储配置列表页
     */
    public function index()
    {
        $configs = $this->getStorageConfig()->reset()
            ->order(StorageConfig::schema_fields_IS_DEFAULT, 'DESC')
            ->order(StorageConfig::schema_fields_CONFIG_ID, 'ASC')
            ->limit(1001)
            ->select()
            ->fetchArray();
        $configs = is_array($configs) ? $configs : [];
        if (count($configs) > 1000) {
            throw new \RuntimeException((string)__('存储磁盘配置数量超过后台列表上限。'));
        }
        foreach ($configs as &$config) {
            if (!is_array($config)) {
                continue;
            }
            try {
                $config['provider_code'] = StorageConfig::providerCodeForDriver(
                    (string)($config[StorageConfig::schema_fields_DRIVER] ?? ''),
                );
            } catch (\InvalidArgumentException) {
                $config['provider_code'] = (string)($config[StorageConfig::schema_fields_DRIVER] ?? '');
            }
        }
        unset($config);
        
        $this->assign('configs', $configs);
        $this->assign('drivers', $this->getDriverProviders()->configurationOptions());
        $this->assign('statusOptions', StorageConfig::getStatusOptions());
        $this->assign('title', __('存储配置'));
        
        return $this->fetch();
    }
    
    /**
     * 新增配置页面
     */
    public function getAdd()
    {
        $this->assign('config', null);
        $this->assign('drivers', $this->getDriverProviders()->configurationOptions());
        $this->assign('driverFields', $this->getDriverProviders()->configurationFieldSets());
        $this->assign('statusOptions', StorageConfig::getStatusOptions());
        $this->assign('title', __('新增存储配置'));
        
        return $this->fetch('form');
    }
    
    /**
     * 编辑配置页面
     */
    public function getEdit()
    {
        $id = (int) $this->request->getGet('id');
        
        $config = $this->getStorageConfig()->reset()
            ->where(StorageConfig::schema_fields_CONFIG_ID, $id)
            ->find()
            ->fetch();
        
        if (!$config) {
            return $this->fetchJson(['code' => 404, 'msg' => __('配置不存在')]);
        }
        
        $providerCode = StorageConfig::providerCodeForDriver(
            (string)$this->getStorageConfig()->getData(StorageConfig::schema_fields_DRIVER),
        );
        $provider = $this->getDriverProviders()->configurable($providerCode);
        $configArray = $this->getStorageConfig()->getConfigArray();
        // Credentials are write-only in the admin UI. Empty password fields on
        // save/test preserve the current persisted value.
        foreach (array_unique(array_merge(
            ['secret', 'access_key_secret', 'security_token'],
            $provider->secretConfigurationKeys(),
        )) as $secretKey) {
            unset($configArray[$secretKey]);
        }
        
        $configData = $this->getStorageConfig()->getData();
        $configData[StorageConfig::schema_fields_DRIVER] = $providerCode;
        $this->assign('config', $configData);
        $this->assign('configArray', $configArray);
        $this->assign('drivers', $this->getDriverProviders()->configurationOptions());
        $this->assign('driverFields', $this->getDriverProviders()->configurationFieldSets());
        $this->assign('statusOptions', StorageConfig::getStatusOptions());
        $this->assign('title', __('编辑存储配置'));
        
        return $this->fetch('form');
    }
    
    /**
     * 保存配置
     */
    public function postSave()
    {
        try {
            $data = $this->request->getPost();
            $id = (int) ($data['config_id'] ?? 0);
            $previousName = '';
            $previousDriver = '';
            $previousConfig = [];
            $previousRevision = 0;
            $previousStatus = StorageConfig::STATUS_DISABLED;
            if ($id > 0) {
                $this->getStorageConfig()->reset()
                    ->where(StorageConfig::schema_fields_CONFIG_ID, $id)
                    ->find()
                    ->fetch();
                
                if (!$this->getStorageConfig()->getId()) {
                    return $this->fetchJson(['code' => 404, 'msg' => __('配置不存在')]);
                }
                $previousName = (string)$this->getStorageConfig()->getData(StorageConfig::schema_fields_NAME);
                $previousDriver = (string)$this->getStorageConfig()->getData(StorageConfig::schema_fields_DRIVER);
                $previousConfig = $this->getStorageConfig()->getConfigArray();
                $previousRevision = max(1, (int)$this->getStorageConfig()->getData(
                    StorageConfig::schema_fields_CONFIG_REVISION,
                ));
                $previousStatus = (int)$this->getStorageConfig()->getData(StorageConfig::schema_fields_STATUS);
            } else {
                $this->getStorageConfig()->reset();
            }

            $driver = StorageConfig::providerCodeForDriver(
                (string)($data['driver'] ?? 'local::filesystem'),
            );
            $provider = $this->getDriverProviders()->configurable($driver);
            $submittedName = strtolower(trim((string)($data['name'] ?? '')));
            if ($submittedName === '') {
                return $this->fetchJson(['code' => 400, 'msg' => __('存储标识不能为空')]);
            }
            if ($id > 0
                && $previousDriver !== ''
                && $driver !== StorageConfig::providerCodeForDriver($previousDriver)
            ) {
                return $this->fetchJson(['code' => 400, 'msg' => __('已建立的磁盘不能更换驱动')]);
            }
            $name = StorageConfig::canonicalDiskCode($driver, $submittedName);
            if ($id > 0 && str_contains($previousName, '::') && strtolower($previousName) !== $name) {
                return $this->fetchJson(['code' => 400, 'msg' => __('磁盘代码建立后不能更改')]);
            }

            $existConfig = ObjectManager::getInstance(StorageConfig::class)
                ->reset()
                ->where(StorageConfig::schema_fields_NAME, $name);
            if ($id > 0) {
                $existConfig->where(StorageConfig::schema_fields_CONFIG_ID, $id, '!=');
            }
            $existConfig->find()->fetch();
            if ($existConfig->getId()) {
                return $this->fetchJson(['code' => 400, 'msg' => __('存储标识已存在')]);
            }

            $this->getStorageConfig()->setData(StorageConfig::schema_fields_NAME, $name);
            $this->getStorageConfig()->setData(StorageConfig::schema_fields_DISPLAY_NAME, $data['display_name'] ?? $name);
            $this->getStorageConfig()->setData(StorageConfig::schema_fields_DRIVER, $driver);
            $submittedStatus = (int)($data['status'] ?? StorageConfig::STATUS_ENABLED);
            $this->getStorageConfig()->setData(StorageConfig::schema_fields_STATUS, $submittedStatus);
            $driverConfig = $provider->normalizeConfiguration(
                $this->configurationInput($provider, $data),
                $previousConfig,
            );
            $namespaceChanged = $id > 0 && !hash_equals(
                $this->getDriverProviders()->objectNamespaceFingerprint($driver, $previousConfig),
                $this->getDriverProviders()->objectNamespaceFingerprint($driver, $driverConfig),
            );
            $destructiveChange = $namespaceChanged
                || ($id > 0
                    && $previousStatus === StorageConfig::STATUS_ENABLED
                    && $submittedStatus === StorageConfig::STATUS_DISABLED);
            $driverConfig['legacy_aliases'] = $this->legacyAliases(
                $previousConfig,
                [$previousName, $submittedName],
                $name,
            );
            $this->getStorageConfig()->setConfigArray($driverConfig, $provider->secretConfigurationKeys());
            /** @var WriteIntentTransactionCoordinatorInterface $transactions */
            $transactions = ObjectManager::getInstance(WriteIntentTransactionCoordinatorInterface::class);
            $connection = $this->getStorageConfig()->getConnection();
            $save = function () use (
                $data,
                $id,
                $previousRevision,
                $destructiveChange,
                $name,
            ): void {
                if ($id > 0) {
                    $this->lockConfigForUpdate($id, $previousRevision);
                }
                if ($destructiveChange) {
                    $this->getUsageGuards()->assertCanDelete($name);
                }
                $this->getStorageConfig()->save(true);
                if (!empty($data['is_default']) && !$this->getStorageConfig()->setAsDefault()) {
                    throw new \RuntimeException((string)__('设置默认存储失败'));
                }
                if ($destructiveChange) {
                    $this->getUsageGuards()->assertCanDelete($name);
                }
            };
            if ($transactions->isActive($connection)) {
                if (!$transactions->isWriteIntent($connection)) {
                    throw new \LogicException((string)__('存储配置保存必须位于写意图事务内。'));
                }
                $transactions->withSavepoint($connection, 'storage_config_save', $save);
            } else {
                $transactions->runWrite($connection, $save);
            }
            $transactions->afterCommit(
                $connection,
                'storage_config_request_reset_' . (int)$this->getStorageConfig()->getId(),
                fn () => $this->resetStorageRequestState(),
            );

            return $this->fetchJson([
                'code' => 200,
                'msg' => __('保存成功'),
                'data' => ['id' => $this->getStorageConfig()->getId()],
            ]);
        } catch (\LogicException|\InvalidArgumentException $e) {
            return $this->fetchJson(['code' => 400, 'msg' => $e->getMessage()]);
        } catch (\Throwable) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('存储配置保存失败'),
            ]);
        }
    }
    
    /**
     * 删除配置
     */
    public function postDelete()
    {
        try {
            $id = (int) $this->request->getPost('id');
            
            $this->getStorageConfig()->reset()
                ->where(StorageConfig::schema_fields_CONFIG_ID, $id)
                ->find()
                ->fetch();
            
            if (!$this->getStorageConfig()->getId()) {
                return $this->fetchJson(['code' => 404, 'msg' => __('配置不存在')]);
            }

            if ((int)$this->getStorageConfig()->getData(StorageConfig::schema_fields_IS_DEFAULT) === 1) {
                return $this->fetchJson(['code' => 400, 'msg' => __('默认存储磁盘不能删除，请先切换默认磁盘。')]);
            }
            $expectedRevision = max(1, (int)$this->getStorageConfig()->getData(
                StorageConfig::schema_fields_CONFIG_REVISION,
            ));
            $diskCode = (string)$this->getStorageConfig()->getData(StorageConfig::schema_fields_NAME);
            /** @var WriteIntentTransactionCoordinatorInterface $transactions */
            $transactions = ObjectManager::getInstance(WriteIntentTransactionCoordinatorInterface::class);
            $connection = $this->getStorageConfig()->getConnection();
            $delete = function () use ($id, $expectedRevision, $diskCode): void {
                $this->lockConfigForUpdate($id, $expectedRevision);
                $this->getUsageGuards()->assertCanDelete(
                    $diskCode,
                );
                $this->getStorageConfig()->setData(StorageConfig::schema_fields_STATUS, StorageConfig::STATUS_DISABLED);
                $this->getStorageConfig()->setData(StorageConfig::schema_fields_IS_DEFAULT, 0);
                $this->getStorageConfig()->save(true);
                $this->getUsageGuards()->assertCanDelete($diskCode);
            };
            if ($transactions->isActive($connection)) {
                if (!$transactions->isWriteIntent($connection)) {
                    throw new \LogicException((string)__('存储配置停用必须位于写意图事务内。'));
                }
                $transactions->withSavepoint($connection, 'storage_config_delete', $delete);
            } else {
                $transactions->runWrite($connection, $delete);
            }
            $transactions->afterCommit(
                $connection,
                'storage_config_request_reset_deleted_' . $id,
                fn () => $this->resetStorageRequestState(),
            );
            
            return $this->fetchJson(['code' => 200, 'msg' => __('存储配置已停用并保留，可在需要时重新启用。')]);
        } catch (\LogicException|\InvalidArgumentException $e) {
            return $this->fetchJson(['code' => 400, 'msg' => $e->getMessage()]);
        } catch (\Throwable) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('存储配置删除失败'),
            ]);
        }
    }
    
    /**
     * 测试连接
     */
    public function postTest()
    {
        try {
            $data = $this->request->getPost();
            $driver = StorageConfig::providerCodeForDriver(
                (string)($data['driver'] ?? 'local::filesystem'),
            );
            $provider = $this->getDriverProviders()->configurable($driver);
            $name = trim((string)($data['name'] ?? 'media'));
            $previousConfig = [];
            $id = (int)($data['config_id'] ?? 0);
            if ($id > 0) {
                $existing = ObjectManager::getInstance(StorageConfig::class)
                    ->reset()
                    ->where(StorageConfig::schema_fields_CONFIG_ID, $id)
                    ->find()
                    ->fetch();
                if ($existing->getId()) {
                    $previousConfig = $existing->getConfigArray();
                }
            }
            $config = $provider->normalizeConfiguration(
                $this->configurationInput($provider, $data),
                $previousConfig,
            );
            $success = $this->getStorageConfigTester()->test($driver, $name, $config);
            
            if ($success) {
                return $this->fetchJson(['code' => 200, 'msg' => __('连接测试成功')]);
            } else {
                return $this->fetchJson(['code' => 400, 'msg' => __('连接测试失败，请检查配置')]);
            }
        } catch (\InvalidArgumentException $e) {
            return $this->fetchJson(['code' => 400, 'msg' => $e->getMessage()]);
        } catch (\Throwable) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('存储连接测试失败'),
            ]);
        }
    }
    
    /**
     * 设为默认
     */
    public function postSetDefault()
    {
        try {
            $id = (int) $this->request->getPost('id');
            
            $this->getStorageConfig()->reset()
                ->where(StorageConfig::schema_fields_CONFIG_ID, $id)
                ->find()
                ->fetch();
            
            if (!$this->getStorageConfig()->getId()) {
                return $this->fetchJson(['code' => 404, 'msg' => __('配置不存在')]);
            }
            
            if (!$this->getStorageConfig()->setAsDefault()) {
                throw new \RuntimeException((string)__('设置默认存储失败'));
            }
            /** @var WriteIntentTransactionCoordinatorInterface $transactions */
            $transactions = ObjectManager::getInstance(WriteIntentTransactionCoordinatorInterface::class);
            $transactions->afterCommit(
                $this->getStorageConfig()->getConnection(),
                'storage_config_request_reset_default_' . $id,
                fn () => $this->resetStorageRequestState(),
            );
            
            return $this->fetchJson(['code' => 200, 'msg' => __('设置成功')]);
        } catch (\LogicException|\InvalidArgumentException $e) {
            return $this->fetchJson(['code' => 400, 'msg' => $e->getMessage()]);
        } catch (\Throwable) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('设置默认存储失败'),
            ]);
        }
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function configurationInput(
        StorageDriverConfigurationProviderInterface $provider,
        array $data,
    ): array
    {
        $nested = $data['config'] ?? [];
        if (!is_array($nested) || count($nested) > 64) {
            throw new \InvalidArgumentException((string)__('存储 Provider 配置数据无效。'));
        }
        $allowed = [];
        foreach ($provider->configurationFields() as $field) {
            $allowed[$field->key] = true;
        }
        foreach ($nested as $key => $value) {
            if (!is_string($key) || !isset($allowed[$key])) {
                throw new \InvalidArgumentException((string)__('存储 Provider 配置包含未声明字段。'));
            }
            if (!is_scalar($value) && $value !== null) {
                throw new \InvalidArgumentException((string)__('存储 Provider 配置值必须是标量。'));
            }
        }
        $input = $nested;
        // Read-only compatibility for the pre-1.2 flat admin form.
        foreach (array_keys($allowed) as $key) {
            $legacyKey = 'config_' . $key;
            if (!array_key_exists($key, $input) && array_key_exists($legacyKey, $data)) {
                $value = $data[$legacyKey];
                if (!is_scalar($value) && $value !== null) {
                    throw new \InvalidArgumentException((string)__('存储 Provider 配置值必须是标量。'));
                }
                $input[$key] = $value;
            }
        }
        return $input;
    }

    private function lockConfigForUpdate(int $configId, int $expectedRevision): void
    {
        $locked = clone $this->getStorageConfig();
        $locked->clearData()->reset()->where(StorageConfig::schema_fields_CONFIG_ID, $configId);
        if ($this->supportsForUpdate()) {
            $locked->additional('FOR UPDATE');
        }
        $locked->find()->fetch();
        if (!$locked->getId()
            || (int)$locked->getData(StorageConfig::schema_fields_CONFIG_REVISION) !== $expectedRevision
        ) {
            throw new \LogicException((string)__('存储配置已被其他请求修改，请刷新后重试。'));
        }
    }

    private function supportsForUpdate(): bool
    {
        $type = strtolower((string)$this->getStorageConfig()->getConnection()
            ->getConnector()->getConfigProvider()->getDbType());
        return in_array($type, ['mysql', 'mariadb', 'pgsql', 'postgres', 'postgresql'], true);
    }

    /** @param array<string,mixed> $previous @param list<string> $candidates @return list<string> */
    private function legacyAliases(array $previous, array $candidates, string $canonical): array
    {
        $aliases = is_array($previous['legacy_aliases'] ?? null) ? $previous['legacy_aliases'] : [];
        array_push($aliases, ...$candidates);
        $result = [];
        foreach (array_slice($aliases, 0, 100) as $alias) {
            if (!is_string($alias)) {
                continue;
            }
            $alias = strtolower(trim($alias));
            if ($alias !== '' && $alias !== $canonical && strlen($alias) <= 190) {
                $result[$alias] = $alias;
            }
        }
        return array_values($result);
    }

}
