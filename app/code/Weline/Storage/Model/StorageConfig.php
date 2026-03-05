<?php

declare(strict_types=1);

namespace Weline\Storage\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/** 存储配置 Model */
#[Table(comment: '存储配置')]
#[Index(name: 'idx_name', columns: ['name'], type: 'UNIQUE')]
class StorageConfig extends Model
{
    public const schema_table = 'storage_config';
    public const schema_primary_key = 'config_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_CONFIG_ID = 'config_id';
    #[Col(type: 'varchar', length: 100, nullable: false, unique: true, comment: '存储标识')]
    public const schema_fields_NAME = 'name';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '显示名称')]
    public const schema_fields_DISPLAY_NAME = 'display_name';
    #[Col(type: 'varchar', length: 50, nullable: false, comment: '驱动类型')]
    public const schema_fields_DRIVER = 'driver';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 0, comment: '是否默认')]
    public const schema_fields_IS_DEFAULT = 'is_default';
    #[Col(type: 'text', nullable: true, comment: 'JSON 配置')]
    public const schema_fields_CONFIG = 'config';
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
        
        $config = \json_decode($configJson, true);
        return \is_array($config) ? $config : [];
    }
    
    /**
     * 设置配置数组（加密 JSON）
     */
    public function setConfigArray(array $config): self
    {
        $this->setData(self::schema_fields_CONFIG, \json_encode($config, \JSON_UNESCAPED_UNICODE));
        return $this;
    }
    
    /**
     * 获取所有可用的存储配置
     */
    public function getEnabledConfigs(): array
    {
        return $this->reset()
            ->where(self::schema_fields_STATUS, self::STATUS_ENABLED)
            ->order(self::schema_fields_IS_DEFAULT, 'DESC')
            ->order(self::schema_fields_CONFIG_ID, 'ASC')
            ->select()
            ->fetchArray();
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
        
        $this->reset()
            ->where(self::schema_fields_IS_DEFAULT, 1)
            ->update([self::schema_fields_IS_DEFAULT => 0]);
        
        $this->setData(self::schema_fields_IS_DEFAULT, 1);
        return $this->save(true);
    }
    
    /**
     * 获取驱动类型列表
     */
    public static function getDriverOptions(): array
    {
        return [
            self::DRIVER_LOCAL => __('本地存储'),
            self::DRIVER_S3 => __('AWS S3'),
            self::DRIVER_OSS => __('阿里云 OSS'),
        ];
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
}
