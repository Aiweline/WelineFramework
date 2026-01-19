<?php

declare(strict_types=1);

namespace Weline\Captcha\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 验证码配置模型
 */
class Config extends \Weline\Framework\Database\Model
{
    public const table = 'weline_captcha_config';
    public const primary_key = 'config_id';
    
    public const fields_ID = 'config_id';
    public const fields_KEY = 'config_key';
    public const fields_VALUE = 'config_value';
    public const fields_MODULE = 'module';
    public const fields_AREA = 'area';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';
    
    public const area_BACKEND = 'backend';
    public const area_FRONTEND = 'frontend';
    
    public array $_unit_primary_keys = ['config_id'];
    public array $_index_sort_keys = ['config_key', 'module', 'area'];
    
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
        // 升级逻辑
    }
    
    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('验证码配置表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', '配置ID')
                ->addColumn(self::fields_KEY, TableInterface::column_type_VARCHAR, 255, 'not null', '配置键')
                ->addColumn(self::fields_VALUE, TableInterface::column_type_TEXT, 0, '', '配置值')
                ->addColumn(self::fields_MODULE, TableInterface::column_type_VARCHAR, 100, 'not null', '模块名称')
                ->addColumn(self::fields_AREA, TableInterface::column_type_VARCHAR, 20, 'not null default \'backend\'', '区域（backend/frontend）')
                ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP', '创建时间')
                ->addColumn(self::fields_UPDATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP', '更新时间')
                ->addIndex(TableInterface::index_type_UNIQUE, 'idx_key_module_area', [self::fields_KEY, self::fields_MODULE, self::fields_AREA], '配置键、模块、区域唯一索引')
                ->create();
        }
    }
    
    /**
     * 获取配置值
     * 
     * @param string $key 配置键
     * @param string $module 模块名称
     * @param string $area 区域
     * @param mixed $default 默认值
     * @return mixed
     */
    public function getConfig(string $key, string $module = 'Weline_Captcha', string $area = self::area_BACKEND, mixed $default = null): mixed
    {
        $config = $this->clear()
            ->where(self::fields_KEY, $key)
            ->where(self::fields_MODULE, $module)
            ->where(self::fields_AREA, $area)
            ->find()
            ->fetch();
        
        if ($config && $config->getData(self::fields_VALUE)) {
            $value = $config->getData(self::fields_VALUE);
            // 尝试解析 JSON
            $decoded = json_decode($value, true);
            return $decoded !== null ? $decoded : $value;
        }
        
        return $default;
    }
    
    /**
     * 设置配置值
     * 
     * @param string $key 配置键
     * @param mixed $value 配置值
     * @param string $module 模块名称
     * @param string $area 区域
     * @return bool
     */
    public function setConfig(string $key, mixed $value, string $module = 'Weline_Captcha', string $area = self::area_BACKEND): bool
    {
        // 如果是数组或对象，转换为 JSON
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        
        $config = $this->clear()
            ->where(self::fields_KEY, $key)
            ->where(self::fields_MODULE, $module)
            ->where(self::fields_AREA, $area)
            ->find()
            ->fetch();
        
        if ($config) {
            $config->setData(self::fields_VALUE, $value);
            return $config->save();
        } else {
            $this->clearData();
            $this->setData(self::fields_KEY, $key);
            $this->setData(self::fields_VALUE, $value);
            $this->setData(self::fields_MODULE, $module);
            $this->setData(self::fields_AREA, $area);
            return $this->save();
        }
    }
}
