<?php
declare(strict_types=1);
/*
 * AWS Domains 管理模块
 * AWS 配置模型
 */
namespace Aws\Domains\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * AWS 配置模型
 * 存储 AWS 访问密钥和区域配置
 */
#[Table(comment: 'AWS域名配置表')]
#[Index(name: 'uk_name', columns: ['name'], type: 'UNIQUE', comment: '配置名称唯一索引')]
#[Index(name: 'idx_active_default', columns: ['is_active', 'is_default'], comment: '启用+默认索引')]
class AwsConfig extends Model
{
    public const schema_table = 'aws_domains_config';
    public const schema_primary_key = 'config_id';
    // 字段常量
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '配置ID')]
    public const schema_fields_CONFIG_ID = 'config_id';
    #[Col(type: 'varchar', length: 100, nullable: false, comment: '配置名称')]
    public const schema_fields_NAME = 'name';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'Access Key ID')]
    public const schema_fields_ACCESS_KEY_ID = 'access_key_id';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'Secret Access Key')]
    public const schema_fields_SECRET_ACCESS_KEY = 'secret_access_key';
    #[Col(type: 'varchar', length: 50, nullable: true, default: 'us-east-1', comment: 'AWS 区域')]
    public const schema_fields_REGION = 'region';
    #[Col(type: 'varchar', length: 500, nullable: true, comment: '描述')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col(type: 'tinyint', length: 1, nullable: false, default: 1, comment: '是否启用 1=是 0=否')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col(type: 'tinyint', length: 1, nullable: false, default: 0, comment: '是否默认 1=是 0=否')]
    public const schema_fields_IS_DEFAULT = 'is_default';
    #[Col(type: 'datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    // AWS 支持 Route 53 Domains 的区域（目前仅支持 us-east-1）
    public const SUPPORTED_REGIONS = [
        'us-east-1' => 'US East (N. Virginia)',
    ];
    // 状态常量
    public const STATUS_ACTIVE = 1;
    public const STATUS_INACTIVE = 0;
    public function _init(): void
    {
        $this->useMainDbMaster();
    }
    public function getIdFieldName(): string
    {
        return self::schema_fields_CONFIG_ID;
    }
    /**
     * 获取默认配置
     */
    public static function getDefaultConfig(): ?self
    {
        $model = new self();
        $model->where(self::schema_fields_IS_ACTIVE, self::STATUS_ACTIVE)
            ->where(self::schema_fields_IS_DEFAULT, 1)
            ->find()
            ->fetch();
        return $model->getId() ? $model : null;
    }
    /**
     * 获取所有启用的配置
     */
    public static function getActiveConfigs(): array
    {
        $model = new self();
        return $model->where(self::schema_fields_IS_ACTIVE, self::STATUS_ACTIVE)
            ->order(self::schema_fields_IS_DEFAULT, 'DESC')
            ->order(self::schema_fields_CREATED_AT, 'DESC')
            ->select()
            ->fetchArray();
    }
    public function isActive(): bool
    {
        return (int)$this->getData(self::schema_fields_IS_ACTIVE) === self::STATUS_ACTIVE;
    }
    public function isDefault(): bool
    {
        return (int)$this->getData(self::schema_fields_IS_DEFAULT) === 1;
    }
    /**
     * 设置为默认配置（同时取消其他默认）
     */
    public function setAsDefault(): self
    {
        // 取消其他默认配置
        $this->reset()
            ->where(self::schema_fields_IS_DEFAULT, 1)
            ->update([self::schema_fields_IS_DEFAULT => 0]);
        $this->setData(self::schema_fields_IS_DEFAULT, 1);
        return $this;
    }
    public function save_before(): void
    {
        $now = date('Y-m-d H:i:s');
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
        $this->setData(self::schema_fields_UPDATED_AT, $now);
    }
    /**
     * 获取区域显示名称
     */
    public function getRegionDisplayName(): string
    {
        $region = $this->getData(self::schema_fields_REGION) ?: 'us-east-1';
        return self::SUPPORTED_REGIONS[$region] ?? $region;
    }
}
