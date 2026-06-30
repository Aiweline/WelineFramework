<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */
namespace Weline\Geo\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/** 平台账户模型 @package Weline_Geo */
#[Table(comment: 'GEO平台账户表')]
#[Index(name: 'idx_platform_id', columns: ['platform_id'], comment: '平台ID索引')]
#[Index(name: 'idx_is_active', columns: ['is_active'], comment: '激活状态索引')]
#[Index(name: 'idx_status', columns: ['status'], comment: '状态索引')]
#[Index(name: 'idx_platform_default', columns: ['platform_id', 'is_default'], comment: '平台默认账户复合索引')]
class PlatformAccount extends Model
{
    public const schema_table = 'geo_platform_account';
    public const schema_primary_key = 'id';
    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['id', 'platform_id', 'is_active', 'is_default'];
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col('int', nullable: false, comment: '关联平台ID')]
    public const schema_fields_PLATFORM_ID = 'platform_id';
    #[Col('varchar', 100, nullable: false, comment: '账户名称')]
    public const schema_fields_ACCOUNT_NAME = 'account_name';
    #[Col('text', nullable: false, comment: 'API密钥')]
    public const schema_fields_API_KEY = 'api_key';
    #[Col('text', comment: 'API密钥Secret')]
    public const schema_fields_API_SECRET = 'api_secret';
    #[Col('int', 1, default: 0, comment: '是否为默认账户')]
    public const schema_fields_IS_DEFAULT = 'is_default';
    #[Col('int', 1, default: 0, comment: '是否激活')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col('varchar', 20, default: 'pending', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('int', comment: '最后测试时间')]
    public const schema_fields_LAST_TEST_TIME = 'last_test_time';
    #[Col('text', comment: '测试消息')]
    public const schema_fields_LAST_TEST_MESSAGE = 'last_test_message';
    #[Col('text', comment: '额外配置JSON')]
    public const schema_fields_CONFIG = 'config';
    #[Col('int', default: 0, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('int', default: 0, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    /**
     * Status constants
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_FAILED = 'failed';
    /**
     * Initialize model
     */
    public function _init(): void
    {
        $this->useMainDbMaster();
    }
    /**
     * 获取主键字段名
     * 
     * @return string
     */
    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
/**
     * 获取配置数组
     * 
     * @return array
     */
    public function getConfigArray(): array
    {
        $config = $this->getData(self::schema_fields_CONFIG);
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($config) ? $config : [];
    }
    /**
     * 设置配置数组
     * 
     * @param array $config
     * @return self
     */
    public function setConfigArray(array $config): self
    {
        $this->setData(self::schema_fields_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
        return $this;
    }
    /**
     * 检查是否默认账户
     * 
     * @return bool
     */
    public function isDefault(): bool
    {
        return (int)$this->getData(self::schema_fields_IS_DEFAULT) === 1;
    }
    /**
     * 检查是否激活
     * 
     * @return bool
     */
    public function isActive(): bool
    {
        return (int)$this->getData(self::schema_fields_IS_ACTIVE) === 1;
    }
    /**
     * 检查是否可用
     * 
     * @return bool
     */
    public function isAvailable(): bool
    {
        return $this->isActive() && $this->getData(self::schema_fields_STATUS) === self::STATUS_ACTIVE;
    }
    /**
     * 保存前处理
     * 
     * @return void
     */
    public function save_before(): void
    {
        $now = time();
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
        $this->setData(self::schema_fields_UPDATED_AT, $now);
    }
}
