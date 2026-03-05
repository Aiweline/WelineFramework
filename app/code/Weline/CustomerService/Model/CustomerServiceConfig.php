<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\CustomerService\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '客服系统配置表')]
#[Index(name: 'idx_key', columns: ['key'])]
class CustomerServiceConfig extends Model
{

    public const schema_table = 'customer_service_config';
    public const schema_primary_key = 'config_id';
    public const schema_primary_keys = ['config_id'];

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '配置ID')]
    public const schema_fields_ID = 'config_id';
    #[Col('varchar', 255, nullable: false, comment: '配置键')]
    public const schema_fields_key = 'key';
    #[Col('text', comment: '配置值')]
    public const schema_fields_value = 'value';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_created_at = 'created_at';
    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_updated_at = 'updated_at';

    public function _init(): void
    {
    }

    public function getKey(): string
    {
        return (string)$this->getData(self::schema_fields_key);
    }

    public function setKey(string $key): static
    {
        return $this->setData(self::schema_fields_key, $key);
    }

    public function getValue(): string
    {
        return (string)$this->getData(self::schema_fields_value);
    }

    public function setValue(string $value): static
    {
        return $this->setData(self::schema_fields_value, $value);
    }

    /**
     * 按键名获取配置值
     * @param string $key 配置键
     * @param string $default 默认值
     * @return string
     */
    public function getConfigValue(string $key, string $default = ''): string
    {
        $this->reset()
            ->where(self::schema_fields_key, $key)
            ->find()
            ->fetch();

        return $this->getId() ? $this->getValue() : $default;
    }

    /**
     * 设置配置项（存在则更新，不存在则新增）
     * @param string $key 配置键
     * @param string $value 配置值
     */
    public function setConfigValue(string $key, string $value): void
    {
        $this->reset()
            ->where(self::schema_fields_key, $key)
            ->find()
            ->fetch();

        if ($this->getId()) {
            $this->setValue($value)
                ->setData(self::schema_fields_updated_at, date('Y-m-d H:i:s'))
                ->save();
        } else {
            $this->reset()
                ->setKey($key)
                ->setValue($value)
                ->setData(self::schema_fields_created_at, date('Y-m-d H:i:s'))
                ->save();
        }
    }
}


