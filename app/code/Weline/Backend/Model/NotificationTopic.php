<?php

declare(strict_types=1);

namespace Weline\Backend\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class NotificationTopic extends Model
{
    public const fields_ID = 'topic_id';
    public const fields_topic_code = 'topic_code';
    public const fields_topic_name = 'topic_name';
    public const fields_topic_group = 'topic_group';
    public const fields_topic_group_name = 'topic_group_name';
    public const fields_description = 'description';
    public const fields_module = 'module';
    public const fields_icon = 'icon';
    public const fields_color = 'color';
    public const fields_default_channels = 'default_channels';
    public const fields_is_enabled = 'is_enabled';
    public const fields_sort_order = 'sort_order';

    public array $_unit_primary_keys = ['topic_id'];
    public array $_index_sort_keys = ['topic_id', 'topic_code', 'topic_group'];

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('消息主题表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'primary key auto_increment',
                    '主键'
                )
                ->addColumn(
                    self::fields_topic_code,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'not null',
                    '主题标识'
                )
                ->addColumn(
                    self::fields_topic_name,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'not null',
                    '主题名称'
                )
                ->addColumn(
                    self::fields_topic_group,
                    TableInterface::column_type_VARCHAR,
                    50,
                    "default ''",
                    '主题分组'
                )
                ->addColumn(
                    self::fields_topic_group_name,
                    TableInterface::column_type_VARCHAR,
                    100,
                    "default ''",
                    '分组名称'
                )
                ->addColumn(
                    self::fields_description,
                    TableInterface::column_type_VARCHAR,
                    500,
                    "default ''",
                    '描述'
                )
                ->addColumn(
                    self::fields_module,
                    TableInterface::column_type_VARCHAR,
                    100,
                    "default ''",
                    '来源模块'
                )
                ->addColumn(
                    self::fields_icon,
                    TableInterface::column_type_VARCHAR,
                    100,
                    "default 'ri-notification-line'",
                    '图标'
                )
                ->addColumn(
                    self::fields_color,
                    TableInterface::column_type_VARCHAR,
                    20,
                    "default '#50a5f1'",
                    '主题色'
                )
                ->addColumn(
                    self::fields_default_channels,
                    TableInterface::column_type_TEXT,
                    0,
                    '',
                    '默认渠道 JSON'
                )
                ->addColumn(
                    self::fields_is_enabled,
                    TableInterface::column_type_SMALLINT,
                    1,
                    'default 1',
                    '是否启用'
                )
                ->addColumn(
                    self::fields_sort_order,
                    TableInterface::column_type_INTEGER,
                    0,
                    'default 0',
                    '排序'
                )
                ->addIndex(TableInterface::index_type_UNIQUE, 'uk_topic_code', self::fields_topic_code, '主题标识唯一')
                ->addIndex(TableInterface::index_type_KEY, 'idx_topic_group', self::fields_topic_group, '分组索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_module', self::fields_module, '模块索引')
                ->create();
        }
    }

    public function upgrade(ModelSetup $setup, Context $context): void
    {
    }

    public function getTopicCode(): string
    {
        return (string) $this->getData(self::fields_topic_code);
    }

    public function setTopicCode(string $code): static
    {
        return $this->setData(self::fields_topic_code, $code);
    }

    public function getTopicName(): string
    {
        return (string) $this->getData(self::fields_topic_name);
    }

    public function setTopicName(string $name): static
    {
        return $this->setData(self::fields_topic_name, $name);
    }

    public function getTopicGroup(): string
    {
        return (string) $this->getData(self::fields_topic_group);
    }

    public function setTopicGroup(string $group): static
    {
        return $this->setData(self::fields_topic_group, $group);
    }

    public function getTopicGroupName(): string
    {
        return (string) $this->getData(self::fields_topic_group_name);
    }

    public function setTopicGroupName(string $name): static
    {
        return $this->setData(self::fields_topic_group_name, $name);
    }

    public function getDescription(): string
    {
        return (string) $this->getData(self::fields_description);
    }

    public function setDescription(string $desc): static
    {
        return $this->setData(self::fields_description, $desc);
    }

    public function getModule(): string
    {
        return (string) $this->getData(self::fields_module);
    }

    public function setModule(string $module): static
    {
        return $this->setData(self::fields_module, $module);
    }

    public function getIcon(): string
    {
        return (string) $this->getData(self::fields_icon);
    }

    public function setIcon(string $icon): static
    {
        return $this->setData(self::fields_icon, $icon);
    }

    public function getColor(): string
    {
        return (string) $this->getData(self::fields_color);
    }

    public function setColor(string $color): static
    {
        return $this->setData(self::fields_color, $color);
    }

    public function getDefaultChannels(): array
    {
        $json = $this->getData(self::fields_default_channels);
        if (empty($json)) {
            return ['backend'];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : ['backend'];
    }

    public function setDefaultChannels(array $channels): static
    {
        return $this->setData(self::fields_default_channels, json_encode($channels));
    }

    public function isEnabled(): bool
    {
        return (bool) $this->getData(self::fields_is_enabled);
    }

    public function setIsEnabled(bool $enabled): static
    {
        return $this->setData(self::fields_is_enabled, $enabled ? 1 : 0);
    }

    public function getSortOrder(): int
    {
        return (int) $this->getData(self::fields_sort_order);
    }

    public function setSortOrder(int $order): static
    {
        return $this->setData(self::fields_sort_order, $order);
    }
}
