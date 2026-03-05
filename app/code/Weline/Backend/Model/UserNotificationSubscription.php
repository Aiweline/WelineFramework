<?php

declare(strict_types=1);

namespace Weline\Backend\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '用户通知订阅表')]
#[Index(name: 'uk_user_topic_channel', columns: ['user_id', 'topic_code', 'channel'], type: 'UNIQUE', comment: '用户主题渠道唯一')]
#[Index(name: 'idx_user_id', columns: ['user_id'], comment: '用户索引')]
#[Index(name: 'idx_topic_code', columns: ['topic_code'], comment: '主题索引')]
class UserNotificationSubscription extends Model
{

    #[Col('int', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: '主键')]
    public const schema_fields_ID = 'subscription_id';
    #[Col('int', 0, nullable: false, comment: '后台用户 ID')]
    public const schema_fields_user_id = 'user_id';
    #[Col('varchar', 50, nullable: false, comment: '订阅主题')]
    public const schema_fields_topic_code = 'topic_code';
    #[Col('varchar', 50, default: 'backend', comment: '渠道：backend/email/feishu/dingtalk/webhook')]
    public const schema_fields_channel = 'channel';
    #[Col('varchar', 20, default: 'info', comment: '最低级别：info/success/warning/error/urgent')]
    public const schema_fields_min_type = 'min_type';
    #[Col('smallint', 1, default: 1, comment: '是否启用')]
    public const schema_fields_is_enabled = 'is_enabled';
    #[Col('text', comment: '渠道配置 JSON')]
    public const schema_fields_channel_config = 'channel_config';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = ['subscription_id', 'user_id', 'topic_code'];
public function getUserId(): int
    {
        return (int) $this->getData(self::schema_fields_user_id);
    }

    public function setUserId(int $userId): static
    {
        return $this->setData(self::schema_fields_user_id, $userId);
    }

    public function getTopicCode(): string
    {
        return (string) $this->getData(self::schema_fields_topic_code);
    }

    public function setTopicCode(string $code): static
    {
        return $this->setData(self::schema_fields_topic_code, $code);
    }

    public function getChannel(): string
    {
        return (string) $this->getData(self::schema_fields_channel);
    }

    public function setChannel(string $channel): static
    {
        return $this->setData(self::schema_fields_channel, $channel);
    }

    public function getMinType(): string
    {
        return (string) $this->getData(self::schema_fields_min_type);
    }

    public function setMinType(string $type): static
    {
        return $this->setData(self::schema_fields_min_type, $type);
    }

    public function isEnabled(): bool
    {
        return (bool) $this->getData(self::schema_fields_is_enabled);
    }

    public function setIsEnabled(bool $enabled): static
    {
        return $this->setData(self::schema_fields_is_enabled, $enabled ? 1 : 0);
    }

    public function getChannelConfig(): array
    {
        $json = $this->getData(self::schema_fields_channel_config);
        if (empty($json)) {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function setChannelConfig(array $config): static
    {
        return $this->setData(self::schema_fields_channel_config, json_encode($config));
    }
}

