<?php

declare(strict_types=1);

namespace Weline\Backend\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class UserNotificationSubscription extends Model
{
    public const fields_ID = 'subscription_id';
    public const fields_user_id = 'user_id';
    public const fields_topic_code = 'topic_code';
    public const fields_channel = 'channel';
    public const fields_min_type = 'min_type';
    public const fields_is_enabled = 'is_enabled';
    public const fields_channel_config = 'channel_config';

    public array $_unit_primary_keys = ['subscription_id'];
    public array $_index_sort_keys = ['subscription_id', 'user_id', 'topic_code'];

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('用户通知订阅表')
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
                    self::fields_topic_code,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'not null',
                    '订阅主题'
                )
                ->addColumn(
                    self::fields_channel,
                    TableInterface::column_type_VARCHAR,
                    50,
                    "default 'backend'",
                    '渠道：backend/email/feishu/dingtalk/webhook'
                )
                ->addColumn(
                    self::fields_min_type,
                    TableInterface::column_type_VARCHAR,
                    20,
                    "default 'info'",
                    '最低级别：info/success/warning/error/urgent'
                )
                ->addColumn(
                    self::fields_is_enabled,
                    TableInterface::column_type_SMALLINT,
                    1,
                    'default 1',
                    '是否启用'
                )
                ->addColumn(
                    self::fields_channel_config,
                    TableInterface::column_type_TEXT,
                    0,
                    '',
                    '渠道配置 JSON'
                )
                ->addIndex(
                    TableInterface::index_type_UNIQUE,
                    'uk_user_topic_channel',
                    'user_id,topic_code,channel',
                    '用户主题渠道唯一'
                )
                ->addIndex(TableInterface::index_type_KEY, 'idx_user_id', self::fields_user_id, '用户索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_topic_code', self::fields_topic_code, '主题索引')
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

    public function getTopicCode(): string
    {
        return (string) $this->getData(self::fields_topic_code);
    }

    public function setTopicCode(string $code): static
    {
        return $this->setData(self::fields_topic_code, $code);
    }

    public function getChannel(): string
    {
        return (string) $this->getData(self::fields_channel);
    }

    public function setChannel(string $channel): static
    {
        return $this->setData(self::fields_channel, $channel);
    }

    public function getMinType(): string
    {
        return (string) $this->getData(self::fields_min_type);
    }

    public function setMinType(string $type): static
    {
        return $this->setData(self::fields_min_type, $type);
    }

    public function isEnabled(): bool
    {
        return (bool) $this->getData(self::fields_is_enabled);
    }

    public function setIsEnabled(bool $enabled): static
    {
        return $this->setData(self::fields_is_enabled, $enabled ? 1 : 0);
    }

    public function getChannelConfig(): array
    {
        $json = $this->getData(self::fields_channel_config);
        if (empty($json)) {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function setChannelConfig(array $config): static
    {
        return $this->setData(self::fields_channel_config, json_encode($config));
    }
}
