<?php
declare(strict_types=1);
namespace Weline\Backend\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '用户联系人表')]
#[Index(name: 'uk_user_channel_value', columns: ['user_id', 'channel_code', 'contact_value'], type: 'UNIQUE', comment: '用户渠道联系方式唯一')]
#[Index(name: 'idx_user_id', columns: ['user_id'], comment: '用户索引')]
#[Index(name: 'idx_channel_code', columns: ['channel_code'], comment: '渠道索引')]
class UserContact extends Model
{
    public const schema_table = 'weline_backend_user_contact';
    public const schema_primary_key = 'contact_id';
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '主键')]
    public const schema_fields_ID = 'contact_id';
    #[Col(type: 'int', nullable: false, comment: '后台用户 ID')]
    public const schema_fields_user_id = 'user_id';
    #[Col(type: 'varchar', length: 50, nullable: false, comment: '渠道标识')]
    public const schema_fields_channel_code = 'channel_code';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '联系方式值')]
    public const schema_fields_contact_value = 'contact_value';
    #[Col(type: 'varchar', length: 100, default: '', comment: '联系人名称')]
    public const schema_fields_contact_name = 'contact_name';
    #[Col(type: 'smallint', length: 1, default: 0, comment: '是否已验证')]
    public const schema_fields_is_verified = 'is_verified';
    #[Col(type: 'smallint', length: 1, default: 0, comment: '是否默认')]
    public const schema_fields_is_default = 'is_default';
    #[Col(type: 'smallint', length: 1, default: 1, comment: '是否启用')]
    public const schema_fields_is_enabled = 'is_enabled';
    #[Col(type: 'text', comment: '扩展配置 JSON')]
    public const schema_fields_extra_config = 'extra_config';
    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = ['contact_id', 'user_id', 'channel_code'];

    public function getUserId(): int
    {
        return (int) $this->getData(self::schema_fields_user_id);
    }
    public function setUserId(int $userId): static
    {
        return $this->setData(self::schema_fields_user_id, $userId);
    }
    public function getChannelCode(): string
    {
        return (string) $this->getData(self::schema_fields_channel_code);
    }
    public function setChannelCode(string $code): static
    {
        return $this->setData(self::schema_fields_channel_code, $code);
    }
    public function getContactValue(): string
    {
        return (string) $this->getData(self::schema_fields_contact_value);
    }
    public function setContactValue(string $value): static
    {
        return $this->setData(self::schema_fields_contact_value, $value);
    }
    public function getContactName(): string
    {
        return (string) $this->getData(self::schema_fields_contact_name);
    }
    public function setContactName(string $name): static
    {
        return $this->setData(self::schema_fields_contact_name, $name);
    }
    public function isVerified(): bool
    {
        return (bool) $this->getData(self::schema_fields_is_verified);
    }
    public function setIsVerified(bool $verified): static
    {
        return $this->setData(self::schema_fields_is_verified, $verified ? 1 : 0);
    }
    public function isDefault(): bool
    {
        return (bool) $this->getData(self::schema_fields_is_default);
    }
    public function setIsDefault(bool $default): static
    {
        return $this->setData(self::schema_fields_is_default, $default ? 1 : 0);
    }
    public function isEnabled(): bool
    {
        return (bool) $this->getData(self::schema_fields_is_enabled);
    }
    public function setIsEnabled(bool $enabled): static
    {
        return $this->setData(self::schema_fields_is_enabled, $enabled ? 1 : 0);
    }
    public function getExtraConfig(): array
    {
        $json = $this->getData(self::schema_fields_extra_config);
        if (empty($json)) {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
    public function setExtraConfig(array $config): static
    {
        return $this->setData(self::schema_fields_extra_config, json_encode($config));
    }
}
