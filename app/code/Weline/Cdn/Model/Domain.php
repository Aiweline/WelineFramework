<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * CDN域名模型
 * @package Weline_Cdn
 */
#[Table(comment: 'CDN域名表')]
#[Index(name: 'idx_site_id', columns: ['site_id'])]
#[Index(name: 'idx_adapter', columns: ['adapter'])]
#[Index(name: 'idx_enabled', columns: ['enabled'])]
#[Index(name: 'idx_domain_name', columns: ['domain_name'])]
class Domain extends Model
{

    public const schema_table = 'cdn_domain';
    public const schema_primary_key = 'domain_id';

    /**
     * Primary key
     */
    public string $_primary_key = 'domain_id';

    /**
     * Primary keys
     */
    public array $_unit_primary_keys = ['domain_id'];

    /**
     * Field name constants
     */
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '域名ID')]
    public const schema_fields_DOMAIN_ID = 'domain_id';
    #[Col('int', nullable: false, comment: '网站ID')]
    public const schema_fields_SITE_ID = 'site_id';
    #[Col('varchar', 50, nullable: false, comment: '适配器代码')]
    public const schema_fields_ADAPTER = 'adapter';
    #[Col('varchar', 128, nullable: false, comment: 'Zone ID')]
    public const schema_fields_ZONE_ID = 'zone_id';
    #[Col('varchar', 255, nullable: false, comment: '域名名称')]
    public const schema_fields_DOMAIN_NAME = 'domain_name';
    #[Col('int', comment: '账户ID')]
    public const schema_fields_ACCOUNT_ID = 'account_id';
    #[Col('int', 1, default: 1, comment: '是否继承默认账户')]
    public const schema_fields_INHERIT_DEFAULT = 'inherit_default';
    #[Col('text', comment: '自定义凭据JSON')]
    public const schema_fields_CREDENTIALS = 'credentials';
    #[Col('text', comment: '规则覆盖JSON')]
    public const schema_fields_RULES_OVERRIDE = 'rules_override';
    #[Col('int', default: 300, comment: '预热间隔秒数')]
    public const schema_fields_WARMUP_INTERVAL_SECONDS = 'warmup_interval_seconds';
    #[Col('int', 1, default: 1, comment: '是否启用')]
    public const schema_fields_ENABLED = 'enabled';
    #[Col('int', default: 0, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('int', default: 0, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

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
        return self::schema_fields_DOMAIN_ID;
    }
/**
     * 获取凭据数组
     * 
     * @return array
     */
    public function getCredentialsArray(): array
    {
        $credentials = $this->getData(self::schema_fields_CREDENTIALS);
        if (is_string($credentials)) {
            $decoded = json_decode($credentials, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($credentials) ? $credentials : [];
    }

    /**
     * 设置凭据数组
     * 
     * @param array $credentials
     * @return self
     */
    public function setCredentialsArray(array $credentials): self
    {
        $this->setData(self::schema_fields_CREDENTIALS, json_encode($credentials, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    /**
     * 获取规则覆盖数组
     * 
     * @return array
     */
    public function getRulesOverrideArray(): array
    {
        $rules = $this->getData(self::schema_fields_RULES_OVERRIDE);
        if (is_string($rules)) {
            $decoded = json_decode($rules, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($rules) ? $rules : [];
    }

    /**
     * 设置规则覆盖数组
     * 
     * @param array $rules
     * @return self
     */
    public function setRulesOverrideArray(array $rules): self
    {
        $this->setData(self::schema_fields_RULES_OVERRIDE, json_encode($rules, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    /**
     * 检查是否启用
     * 
     * @return bool
     */
    public function isEnabled(): bool
    {
        return (int)$this->getData(self::schema_fields_ENABLED) === 1;
    }

    /**
     * 检查是否继承默认账户
     * 
     * @return bool
     */
    public function isInheritDefault(): bool
    {
        return (int)$this->getData(self::schema_fields_INHERIT_DEFAULT) === 1;
    }

    /**
     * 保存前处理
     * 
     * @return self
     */
    public function beforeSave(): self
    {
        $now = time();
        if (!$this->getData(self::schema_fields_CREATED_AT)) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
        $this->setData(self::schema_fields_UPDATED_AT, $now);
        return parent::beforeSave();
    }
}


