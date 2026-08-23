<?php

declare(strict_types=1);

namespace Weline\Storage\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Http\Security\SecretRefCipher;
use Weline\Framework\Manager\ObjectManager;
use Weline\Storage\Api\Data\StorageDiskCode;
use Weline\Storage\Api\StorageDriverConfigurationProviderInterface;
use Weline\Storage\Service\StorageDriverProviderRegistry;
/** 存储配置 Model */
#[Table(comment: '存储配置')]
#[Index(name: 'idx_name', columns: ['name'], type: 'UNIQUE')]
class StorageConfig extends Model
{
    private const SECRET_FIELDS = ['secret', 'access_key_secret', 'security_token'];
    public const schema_table = 'storage_config';
    public const schema_primary_key = 'config_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_CONFIG_ID = 'config_id';
    #[Col(type: 'varchar', length: 190, nullable: false, unique: true, comment: '存储标识')]
    public const schema_fields_NAME = 'name';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '显示名称')]
    public const schema_fields_DISPLAY_NAME = 'display_name';
    #[Col(type: 'varchar', length: 130, nullable: false, comment: '驱动 Provider 代码')]
    public const schema_fields_DRIVER = 'driver';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 0, comment: '是否默认')]
    public const schema_fields_IS_DEFAULT = 'is_default';
    #[Col(type: 'text', nullable: true, comment: 'JSON 配置')]
    public const schema_fields_CONFIG = 'config';
    #[Col(type: 'int', length: 11, nullable: false, default: 1, comment: '配置快照版本')]
    public const schema_fields_CONFIG_REVISION = 'config_revision';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 1, comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'datetime', nullable: true, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    public const DRIVER_LOCAL = 'local';
    public const DRIVER_S3 = 's3';
    public const DRIVER_OSS = 'oss';
    public const STATUS_DISABLED = 0;
    public const STATUS_ENABLED = 1;
    public array $_unit_primary_keys = ['config_id'];
    public array $_index_sort_keys = ['config_id', 'name', 'driver', 'status'];
/**
     * 获取配置数组（解密 JSON）
     */
    public function getConfigArray(): array
    {
        $configJson = $this->getData(self::schema_fields_CONFIG);
        if (empty($configJson)) {
            return [];
        }
        
        try {
            $config = \json_decode((string)$configJson, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException((string)__('存储配置 JSON 无效。'));
        }
        if (!\is_array($config)) {
            throw new \RuntimeException((string)__('存储配置 JSON 无效。'));
        }
        return self::revealSecrets($config);
    }
    
    /**
     * 设置配置数组（加密 JSON）
     */
    public function setConfigArray(array $config, array $secretFields = []): self
    {
        $secretFields = array_values(array_unique(array_merge(self::SECRET_FIELDS, $secretFields)));
        if (count($secretFields) > 32) {
            throw new \InvalidArgumentException((string)__('存储密钥字段数量超过上限。'));
        }
        foreach ($secretFields as $field) {
            if (!is_string($field) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $field) !== 1) {
                throw new \InvalidArgumentException((string)__('存储密钥字段代码无效。'));
            }
            $rawSecret = $config[$field] ?? '';
            if (!is_scalar($rawSecret) && $rawSecret !== null) {
                throw new \InvalidArgumentException((string)__('存储密钥配置值无效。'));
            }
            $secret = (string)$rawSecret;
            if ($secret !== '' && !SecretRefCipher::isRef($secret)) {
                $config[$field] = SecretRefCipher::seal($secret);
            }
        }
        $this->setData(self::schema_fields_CONFIG, \json_encode(
            $config,
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
        ));
        return $this;
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    public static function revealSecrets(array $config): array
    {
        foreach ($config as $field => $value) {
            if (!is_string($value) || $value === '' || !SecretRefCipher::isRef($value)) {
                continue;
            }
            try {
                $config[$field] = SecretRefCipher::reveal($value);
            } catch (\Throwable $exception) {
                throw new \RuntimeException((string)__('存储凭据解密失败。'), 0, $exception);
            }
        }
        return $config;
    }
    
    /**
     * 获取所有可用的存储配置
     */
    public function getEnabledConfigs(): array
    {
        $rows = $this->reset()
            ->where(self::schema_fields_STATUS, self::STATUS_ENABLED)
            ->order(self::schema_fields_IS_DEFAULT, 'DESC')
            ->order(self::schema_fields_CONFIG_ID, 'ASC')
            ->limit(1001)
            ->select()
            ->fetchArray();
        $rows = is_array($rows) ? $rows : [];
        if (count($rows) > 1000) {
            throw new \RuntimeException((string)__('已启用的存储磁盘数量超过上限。'));
        }
        return $rows;
    }
    
    /**
     * 获取默认存储配置
     */
    public function getDefaultConfig(): ?self
    {
        $result = $this->reset()
            ->where(self::schema_fields_STATUS, self::STATUS_ENABLED)
            ->where(self::schema_fields_IS_DEFAULT, 1)
            ->find()
            ->fetch();
        
        return $result ? $this : null;
    }
    
    /**
     * 根据名称获取配置
     */
    public function loadByName(string $name): self
    {
        $this->reset()
            ->where(self::schema_fields_NAME, $name)
            ->find()
            ->fetch();
        return $this;
    }
    
    /**
     * 设置为默认存储（取消其他默认）
     */
    public function setAsDefault(): bool
    {
        if (!$this->getId()) {
            return false;
        }
        if ((int)$this->getData(self::schema_fields_STATUS) !== self::STATUS_ENABLED) {
            throw new \LogicException((string)__('禁用的存储磁盘不能设为默认。'));
        }

        /** @var WriteIntentTransactionCoordinatorInterface $transactions */
        $transactions = ObjectManager::getInstance(WriteIntentTransactionCoordinatorInterface::class);
        $connection = $this->getConnection();
        $operation = function (): bool {
            $locks = clone $this;
            $locks->clearData()->reset()->order(self::schema_fields_CONFIG_ID, 'ASC')->limit(1001);
            if ($this->supportsForUpdate()) {
                $locks->additional('FOR UPDATE');
            }
            $items = $locks->select()->fetchArray();
            $items = is_array($items) ? $items : [];
            if (count($items) > 1000) {
                throw new \RuntimeException((string)__('存储磁盘配置数量超过默认切换上限。'));
            }

            // The controller may have loaded this model before the write
            // transaction began. Read the target again after the table rows are
            // locked and update only the default marker, otherwise a concurrent
            // configuration/credential edit could be overwritten by stale data.
            $target = clone $this;
            $target->clearData()->reset()
                ->where(self::schema_fields_CONFIG_ID, (int)$this->getId())
                ->find()->fetch();
            if (!$target->getId()) {
                return false;
            }
            if ((int)$target->getData(self::schema_fields_STATUS) !== self::STATUS_ENABLED) {
                throw new \LogicException((string)__('禁用的存储磁盘不能设为默认。'));
            }

            $others = clone $this;
            $others->clearData()->reset()
                ->where(self::schema_fields_IS_DEFAULT, 1)
                ->update([self::schema_fields_IS_DEFAULT => 0])
                ->fetch();

            $selected = clone $this;
            $selected->clearData()->reset()
                ->where(self::schema_fields_CONFIG_ID, (int)$target->getId())
                ->update([self::schema_fields_IS_DEFAULT => 1])
                ->fetch();
            $this->setData(self::schema_fields_IS_DEFAULT, 1);
            return true;
        };

        if ($transactions->isActive($connection)) {
            if (!$transactions->isWriteIntent($connection)) {
                throw new \LogicException((string)__('默认存储切换必须位于写意图事务内。'));
            }
            return (bool)$transactions->withSavepoint($connection, 'storage_set_default', $operation);
        }
        return (bool)$transactions->runWrite($connection, $operation);
    }
    
    /**
     * 获取驱动类型列表
     */
    public static function getDriverOptions(): array
    {
        return ObjectManager::getInstance(StorageDriverProviderRegistry::class)->configurationOptions();
    }
    
    /**
     * 获取状态列表
     */
    public static function getStatusOptions(): array
    {
        return [
            self::STATUS_DISABLED => __('禁用'),
            self::STATUS_ENABLED => __('启用'),
        ];
    }

    public static function canonicalDiskCode(string $driver, string $name): string
    {
        $name = strtolower(trim($name));
        $provider = self::providerCodeForDriver($driver);
        try {
            $parsed = \Weline\Storage\Api\Data\StorageDiskCode::parse($name);
            if ($parsed->providerCode() !== $provider) {
                throw new \InvalidArgumentException((string)__('磁盘代码与所选驱动不匹配。'));
            }
            return (string)$parsed;
        } catch (\InvalidArgumentException) {
            if (substr_count($name, '::') > 0) {
                throw new \InvalidArgumentException((string)__('磁盘代码必须使用与驱动匹配的 type::vendor::instance 三段式格式。'));
            }
        }
        if ($provider === 'local::filesystem' && in_array($name, ['local', '__local__'], true)) {
            $name = 'media';
        }
        $instance = preg_replace('/[^a-z0-9_-]+/', '_', $name) ?: 'media';
        return (string)\Weline\Storage\Api\Data\StorageDiskCode::fromProvider(
            $provider,
            trim($instance, '_') ?: 'media',
        );
    }

    public static function providerCodeForDriver(string $driver): string
    {
        $driver = strtolower(trim($driver));
        $legacy = match ($driver) {
            self::DRIVER_OSS, 'aliyun', 'oss::aliyun' => 'oss::aliyun',
            self::DRIVER_S3, 'aws', 's3::aws' => 's3::aws',
            self::DRIVER_LOCAL, 'filesystem', 'local::filesystem' => 'local::filesystem',
            default => '',
        };
        if ($legacy !== '') {
            return $legacy;
        }
        try {
            $provider = StorageDiskCode::fromProvider($driver, 'provider_validation')->providerCode();
        } catch (\InvalidArgumentException $exception) {
            throw new \InvalidArgumentException((string)__('未知的存储驱动。'), 0, $exception);
        }
        if ($provider !== $driver) {
            throw new \InvalidArgumentException((string)__('未知的存储驱动。'));
        }
        return $provider;
    }

    public function save_before(): void
    {
        parent::save_before();
        $name = (string)$this->getData(self::schema_fields_NAME);
        $driver = (string)$this->getData(self::schema_fields_DRIVER);
        if ($name === '') {
            throw new \InvalidArgumentException((string)__('存储标识不能为空'));
        }
        $providerCode = self::providerCodeForDriver($driver);
        $provider = ObjectManager::getInstance(StorageDriverProviderRegistry::class)->get($providerCode);
        $secretFields = $provider instanceof StorageDriverConfigurationProviderInterface
            ? $provider->secretConfigurationKeys()
            : [];
        $this->setData(self::schema_fields_DRIVER, $providerCode);
        $this->setData(self::schema_fields_NAME, self::canonicalDiskCode($driver, $name));

        $displayName = trim((string)$this->getData(self::schema_fields_DISPLAY_NAME));
        $displayNameLength = function_exists('mb_strlen') ? mb_strlen($displayName, 'UTF-8') : strlen($displayName);
        if (
            $displayName === ''
            || preg_match('//u', $displayName) !== 1
            || $displayNameLength > 255
            || preg_match('/[\x00-\x1F\x7F]/', $displayName) === 1
        ) {
            throw new \InvalidArgumentException((string)__('存储显示名称无效。'));
        }
        $this->setData(self::schema_fields_DISPLAY_NAME, $displayName);

        $status = (int)$this->getData(self::schema_fields_STATUS);
        if (!in_array($status, [self::STATUS_DISABLED, self::STATUS_ENABLED], true)) {
            throw new \InvalidArgumentException((string)__('存储状态无效。'));
        }
        if ($status === self::STATUS_DISABLED
            && (int)$this->getData(self::schema_fields_IS_DEFAULT) === 1
        ) {
            throw new \LogicException((string)__('默认存储磁盘不能直接禁用，请先切换默认磁盘。'));
        }

        $rawConfig = trim((string)$this->getData(self::schema_fields_CONFIG));
        try {
            $config = $rawConfig === '' ? [] : json_decode($rawConfig, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException((string)__('存储配置 JSON 无效。'), 0, $exception);
        }
        if (!is_array($config)) {
            throw new \InvalidArgumentException((string)__('存储配置 JSON 无效。'));
        }
        $this->setConfigArray($config, $secretFields);
        if (strlen((string)$this->getData(self::schema_fields_CONFIG)) > 65535) {
            throw new \InvalidArgumentException((string)__('存储配置超过大小限制。'));
        }
        $currentRevision = (int)$this->getData(self::schema_fields_CONFIG_REVISION);
        $this->setData(self::schema_fields_CONFIG_REVISION, $this->getId()
            ? max(1, $currentRevision) + 1
            : max(1, $currentRevision));
        $this->setData(self::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'));
    }

    private function supportsForUpdate(): bool
    {
        $type = strtolower((string)$this->getConnection()
            ->getConnector()->getConfigProvider()->getDbType());
        return in_array($type, ['mysql', 'mariadb', 'pgsql', 'postgres', 'postgresql'], true);
    }
}
