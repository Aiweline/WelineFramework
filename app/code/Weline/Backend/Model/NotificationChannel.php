<?php

declare(strict_types=1);

namespace Weline\Backend\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class NotificationChannel extends Model
{
    public const fields_ID = 'channel_id';
    public const fields_channel_code = 'channel_code';
    public const fields_channel_name = 'channel_name';
    public const fields_channel_config = 'channel_config';
    public const fields_subscribed_topics = 'subscribed_topics';
    public const fields_min_type = 'min_type';
    public const fields_is_enabled = 'is_enabled';

    public array $_unit_primary_keys = ['channel_id'];
    public array $_index_sort_keys = ['channel_id', 'channel_code'];

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('通知渠道配置表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'primary key auto_increment',
                    '主键'
                )
                ->addColumn(
                    self::fields_channel_code,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'not null',
                    '渠道标识'
                )
                ->addColumn(
                    self::fields_channel_name,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'not null',
                    '渠道名称'
                )
                ->addColumn(
                    self::fields_channel_config,
                    TableInterface::column_type_TEXT,
                    0,
                    '',
                    '配置 JSON'
                )
                ->addColumn(
                    self::fields_subscribed_topics,
                    TableInterface::column_type_TEXT,
                    0,
                    '',
                    '订阅主题 JSON（空=全部）'
                )
                ->addColumn(
                    self::fields_min_type,
                    TableInterface::column_type_VARCHAR,
                    20,
                    "default 'warning'",
                    '最低级别'
                )
                ->addColumn(
                    self::fields_is_enabled,
                    TableInterface::column_type_SMALLINT,
                    1,
                    'default 1',
                    '是否启用'
                )
                ->addIndex(TableInterface::index_type_UNIQUE, 'uk_channel_code', self::fields_channel_code, '渠道标识唯一')
                ->create();
        }
    }

    public function upgrade(ModelSetup $setup, Context $context): void
    {
    }

    public function getChannelCode(): string
    {
        return (string) $this->getData(self::fields_channel_code);
    }

    public function setChannelCode(string $code): static
    {
        return $this->setData(self::fields_channel_code, $code);
    }

    public function getChannelName(): string
    {
        return (string) $this->getData(self::fields_channel_name);
    }

    public function setChannelName(string $name): static
    {
        return $this->setData(self::fields_channel_name, $name);
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

    public function getSubscribedTopics(): array
    {
        $json = $this->getData(self::fields_subscribed_topics);
        if (empty($json)) {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function setSubscribedTopics(array $topics): static
    {
        return $this->setData(self::fields_subscribed_topics, json_encode($topics));
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

    public function isSubscribedToTopic(string $topicCode): bool
    {
        $topics = $this->getSubscribedTopics();
        if (empty($topics)) {
            return true;
        }
        return in_array($topicCode, $topics, true);
    }
}
