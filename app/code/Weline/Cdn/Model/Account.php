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
 * CDN账户模型
 * @package Weline_Cdn
 */
#[Table(comment: 'CDN账户表')]
#[Index(name: 'idx_adapter', columns: ['adapter'])]
#[Index(name: 'idx_status', columns: ['status'])]
#[Index(name: 'idx_adapter_default', columns: ['adapter', 'is_default'])]
class Account extends Model
{
    public const schema_table = 'cdn_account';
    public const schema_primary_key = 'account_id';
    public string $_primary_key = 'account_id';
    public array $_unit_primary_keys = ['account_id'];

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '账户ID')]
    public const schema_fields_ACCOUNT_ID = 'account_id';
    #[Col(type: 'varchar', length: 50, nullable: false, comment: '适配器代码')]
    public const schema_fields_ADAPTER = 'adapter';
    #[Col(type: 'varchar', length: 128, nullable: false, comment: '账户名称')]
    public const schema_fields_NAME = 'name';
    #[Col(type: 'text', nullable: true, comment: '账户描述')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col(type: 'text', nullable: false, comment: '凭据JSON')]
    public const schema_fields_CREDENTIALS = 'credentials';
    #[Col(type: 'int', length: 1, default: 0, comment: '是否默认账户')]
    public const schema_fields_IS_DEFAULT = 'is_default';
    #[Col(type: 'varchar', length: 20, default: 'active', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'int', default: 0, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'int', default: 0, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ACCOUNT_ID;
    }

    public function getCredentialsArray(): array
    {
        $credentials = $this->getData(self::schema_fields_CREDENTIALS);
        if (is_string($credentials)) {
            $decoded = json_decode($credentials, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($credentials) ? $credentials : [];
    }

    public function setCredentialsArray(array $credentials): self
    {
        $this->setData(self::schema_fields_CREDENTIALS, json_encode($credentials, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    public function isDefault(): bool
    {
        return (int)$this->getData(self::schema_fields_IS_DEFAULT) === 1;
    }

    public function isActive(): bool
    {
        return $this->getData(self::schema_fields_STATUS) === self::STATUS_ACTIVE;
    }

    public function save_before(): void
    {
        $now = time();
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
        $this->setData(self::schema_fields_UPDATED_AT, $now);
    }
}
