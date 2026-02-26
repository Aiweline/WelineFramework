<?php

declare(strict_types=1);

namespace Weline\Storage\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * @DESC | 存储配置 Model
 */
class StorageConfig extends Model
{
    public const fields_CONFIG_ID = 'config_id';
    public const fields_NAME = 'name';
    public const fields_DISPLAY_NAME = 'display_name';
    public const fields_DRIVER = 'driver';
    public const fields_IS_DEFAULT = 'is_default';
    public const fields_CONFIG = 'config';
    public const fields_STATUS = 'status';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';
    
    public const DRIVER_LOCAL = 'local';
    public const DRIVER_S3 = 's3';
    public const DRIVER_OSS = 'oss';
    
    public const STATUS_DISABLED = 0;
    public const STATUS_ENABLED = 1;
    
    public array $_unit_primary_keys = ['config_id'];
    public array $_index_sort_keys = ['config_id', 'name', 'driver', 'status'];
    
    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }
    
    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
    }
    
    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable(__('存储配置'))
                ->addColumn(
                    self::fields_CONFIG_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'primary key auto_increment',
                    'ID'
                )
                ->addColumn(
                    self::fields_NAME,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'not null unique',
                    __('存储标识')
                )
                ->addColumn(
                    self::fields_DISPLAY_NAME,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    __('显示名称')
                )
                ->addColumn(
                    self::fields_DRIVER,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'not null',
                    __('驱动类型')
                )
                ->addColumn(
                    self::fields_IS_DEFAULT,
                    TableInterface::column_type_SMALLINT,
                    1,
                    'not null default 0',
                    __('是否默认')
                )
                ->addColumn(
                    self::fields_CONFIG,
                    TableInterface::column_type_TEXT,
                    0,
                    '',
                    __('JSON 配置')
                )
                ->addColumn(
                    self::fields_STATUS,
                    TableInterface::column_type_SMALLINT,
                    1,
                    'not null default 1',
                    __('状态')
                )
                ->addColumn(
                    self::fields_CREATED_AT,
                    TableInterface::column_type_DATETIME,
                    0,
                    'default current_timestamp',
                    __('创建时间')
                )
                ->addColumn(
                    self::fields_UPDATED_AT,
                    TableInterface::column_type_DATETIME,
                    0,
                    '',
                    __('更新时间')
                )
                ->create();
        }
    }
    
    /**
     * 获取配置数组（解密 JSON）
     */
    public function getConfigArray(): array
    {
        $configJson = $this->getData(self::fields_CONFIG);
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
        $this->setData(self::fields_CONFIG, \json_encode($config, \JSON_UNESCAPED_UNICODE));
        return $this;
    }
    
    /**
     * 获取所有可用的存储配置
     */
    public function getEnabledConfigs(): array
    {
        return $this->reset()
            ->where(self::fields_STATUS, self::STATUS_ENABLED)
            ->order(self::fields_IS_DEFAULT, 'DESC')
            ->order(self::fields_CONFIG_ID, 'ASC')
            ->select()
            ->fetchArray();
    }
    
    /**
     * 获取默认存储配置
     */
    public function getDefaultConfig(): ?self
    {
        $result = $this->reset()
            ->where(self::fields_STATUS, self::STATUS_ENABLED)
            ->where(self::fields_IS_DEFAULT, 1)
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
            ->where(self::fields_NAME, $name)
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
            ->where(self::fields_IS_DEFAULT, 1)
            ->update([self::fields_IS_DEFAULT => 0]);
        
        $this->setData(self::fields_IS_DEFAULT, 1);
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
