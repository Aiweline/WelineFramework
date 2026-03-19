<?php

declare(strict_types=1);

namespace Weline\Backend\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '联系人表（实体，可绑定多渠道配置）')]
#[Index(name: 'idx_user_id', columns: ['user_id'], comment: '用户索引')]
#[Index(name: 'idx_is_enabled', columns: ['is_enabled'], comment: '启用索引')]
class Contact extends Model
{
    public const schema_table = 'weline_backend_contact';
    public const schema_primary_key = 'contact_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '主键')]
    public const schema_fields_ID = 'contact_id';

    #[Col(type: 'int', nullable: false, comment: '后台用户 ID')]
    public const schema_fields_user_id = 'user_id';

    #[Col(type: 'varchar', length: 100, nullable: false, comment: '联系人显示名称')]
    public const schema_fields_contact_name = 'contact_name';

    #[Col(type: 'text', comment: '按渠道的配置 JSON，如 {"webhook":{"webhook_url":"..."}, "email":{"to_email":"..."}}')]
    public const schema_fields_channel_config = 'channel_config';

    #[Col(type: 'varchar', length: 255, default: '', comment: '已配置渠道逗号分隔，如 webhook,feishu,email')]
    public const schema_fields_channels = 'channels';

    #[Col(type: 'smallint', length: 1, default: 1, comment: '是否启用')]
    public const schema_fields_is_enabled = 'is_enabled';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = ['contact_id', 'user_id'];

    public function getUserId(): int
    {
        return (int) $this->getData(self::schema_fields_user_id);
    }

    public function setUserId(int $userId): static
    {
        return $this->setData(self::schema_fields_user_id, $userId);
    }

    public function getContactName(): string
    {
        return (string) $this->getData(self::schema_fields_contact_name);
    }

    public function setContactName(string $name): static
    {
        return $this->setData(self::schema_fields_contact_name, $name);
    }

    public function getChannelConfig(): array
    {
        $json = $this->getData(self::schema_fields_channel_config);
        if ($json === null || $json === '') {
            return [];
        }
        if (is_array($json)) {
            return $json;
        }
        $decoded = json_decode((string) $json, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function setChannelConfig(array $config): static
    {
        return $this->setData(self::schema_fields_channel_config, json_encode($config, JSON_UNESCAPED_UNICODE));
    }

    public function getChannels(): string
    {
        return (string) ($this->getData(self::schema_fields_channels) ?? '');
    }

    public function setChannels(string $channels): static
    {
        return $this->setData(self::schema_fields_channels, $channels);
    }

    public function isEnabled(): bool
    {
        return (bool) $this->getData(self::schema_fields_is_enabled);
    }

    public function setIsEnabled(bool $enabled): static
    {
        return $this->setData(self::schema_fields_is_enabled, $enabled ? 1 : 0);
    }
}
