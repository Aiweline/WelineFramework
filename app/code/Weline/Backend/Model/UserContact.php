<?php

declare(strict_types=1);

namespace Weline\Backend\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class UserContact extends Model
{
    public const fields_ID = 'contact_id';
    public const fields_user_id = 'user_id';
    public const fields_channel_code = 'channel_code';
    public const fields_contact_value = 'contact_value';
    public const fields_contact_name = 'contact_name';
    public const fields_is_verified = 'is_verified';
    public const fields_is_default = 'is_default';
    public const fields_is_enabled = 'is_enabled';
    public const fields_extra_config = 'extra_config';

    public array $_unit_primary_keys = ['contact_id'];
    public array $_index_sort_keys = ['contact_id', 'user_id', 'channel_code'];

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('用户联系人表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'primary key auto_increment',
                    '主键'
                )
                ->addColumn(
                    self::fields_user_id,
                    TableInterface::column_type_INTEGER,
                    0,
                    'not null',
                    '后台用户 ID'
                )
                ->addColumn(
                    self::fields_channel_code,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'not null',
                    '渠道标识'
                )
                ->addColumn(
                    self::fields_contact_value,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    '联系方式值'
                )
                ->addColumn(
                    self::fields_contact_name,
                    TableInterface::column_type_VARCHAR,
                    100,
                    "default ''",
                    '联系人名称'
                )
                ->addColumn(
                    self::fields_is_verified,
                    TableInterface::column_type_SMALLINT,
                    1,
                    'default 0',
                    '是否已验证'
                )
                ->addColumn(
                    self::fields_is_default,
                    TableInterface::column_type_SMALLINT,
                    1,
                    'default 0',
                    '是否默认'
                )
                ->addColumn(
                    self::fields_is_enabled,
                    TableInterface::column_type_SMALLINT,
                    1,
                    'default 1',
                    '是否启用'
                )
                ->addColumn(
                    self::fields_extra_config,
                    TableInterface::column_type_TEXT,
                    0,
                    '',
                    '扩展配置 JSON'
                )
                ->addIndex(
                    TableInterface::index_type_UNIQUE,
                    'uk_user_channel_value',
                    'user_id,channel_code,contact_value',
                    '用户渠道联系方式唯一'
                )
                ->addIndex(TableInterface::index_type_KEY, 'idx_user_id', self::fields_user_id, '用户索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_channel_code', self::fields_channel_code, '渠道索引')
                ->create();
        }
    }

    public function upgrade(ModelSetup $setup, Context $context): void
    {
    }

    public function getUserId(): int
    {
        return (int) $this->getData(self::fields_user_id);
    }

    public function setUserId(int $userId): static
    {
        return $this->setData(self::fields_user_id, $userId);
    }

    public function getChannelCode(): string
    {
        return (string) $this->getData(self::fields_channel_code);
    }

    public function setChannelCode(string $code): static
    {
        return $this->setData(self::fields_channel_code, $code);
    }

    public function getContactValue(): string
    {
        return (string) $this->getData(self::fields_contact_value);
    }

    public function setContactValue(string $value): static
    {
        return $this->setData(self::fields_contact_value, $value);
    }

    public function getContactName(): string
    {
        return (string) $this->getData(self::fields_contact_name);
    }

    public function setContactName(string $name): static
    {
        return $this->setData(self::fields_contact_name, $name);
    }

    public function isVerified(): bool
    {
        return (bool) $this->getData(self::fields_is_verified);
    }

    public function setIsVerified(bool $verified): static
    {
        return $this->setData(self::fields_is_verified, $verified ? 1 : 0);
    }

    public function isDefault(): bool
    {
        return (bool) $this->getData(self::fields_is_default);
    }

    public function setIsDefault(bool $default): static
    {
        return $this->setData(self::fields_is_default, $default ? 1 : 0);
    }

    public function isEnabled(): bool
    {
        return (bool) $this->getData(self::fields_is_enabled);
    }

    public function setIsEnabled(bool $enabled): static
    {
        return $this->setData(self::fields_is_enabled, $enabled ? 1 : 0);
    }

    public function getExtraConfig(): array
    {
        $json = $this->getData(self::fields_extra_config);
        if (empty($json)) {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function setExtraConfig(array $config): static
    {
        return $this->setData(self::fields_extra_config, json_encode($config));
    }
}
