<?php

declare(strict_types=1);

namespace Weline\Captcha\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 验证码配置模型
 */
#[Table(comment: '验证码配置表')]
#[Index(name: 'idx_key_module_area', columns: ['config_key', 'module', 'area'], type: 'UNIQUE', comment: '配置键、模块、区域唯一索引')]
class Config extends Model
{

    public const schema_table = 'weline_captcha_config';
    public const schema_primary_key = 'config_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '配置ID')]
    public const schema_fields_ID = 'config_id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '配置键')]
    public const schema_fields_KEY = 'config_key';
    #[Col(type: 'text', nullable: true, comment: '配置值')]
    public const schema_fields_VALUE = 'config_value';
    #[Col(type: 'varchar', length: 100, nullable: false, comment: '模块名称')]
    public const schema_fields_MODULE = 'module';
    #[Col(type: 'varchar', length: 20, nullable: false, default: 'backend', comment: '区域（backend/frontend）')]
    public const schema_fields_AREA = 'area';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public const area_BACKEND = 'backend';
    public const area_FRONTEND = 'frontend';

    public array $_unit_primary_keys = ['config_id'];
    public array $_index_sort_keys = ['config_key', 'module', 'area'];

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
            ->where(self::schema_fields_KEY, $key)
            ->where(self::schema_fields_MODULE, $module)
            ->where(self::schema_fields_AREA, $area)
            ->find()
            ->fetch();

        if ($config && $config->getData(self::schema_fields_VALUE)) {
            $value = $config->getData(self::schema_fields_VALUE);
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
            ->where(self::schema_fields_KEY, $key)
            ->where(self::schema_fields_MODULE, $module)
            ->where(self::schema_fields_AREA, $area)
            ->find()
            ->fetch();

        if ($config) {
            $config->setData(self::schema_fields_VALUE, $value);
            return $config->save();
        } else {
            $this->clearData();
            $this->setData(self::schema_fields_KEY, $key);
            $this->setData(self::schema_fields_VALUE, $value);
            $this->setData(self::schema_fields_MODULE, $module);
            $this->setData(self::schema_fields_AREA, $area);
            return $this->save();
        }
    }
}
