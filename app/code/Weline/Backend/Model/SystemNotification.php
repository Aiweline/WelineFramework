<?php

declare(strict_types=1);

namespace Weline\Backend\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class SystemNotification extends Model
{
    public const fields_ID = 'notification_id';
    public const fields_topic_code = 'topic_code';
    public const fields_type = 'type';
    public const fields_title = 'title';
    public const fields_content = 'content';
    public const fields_priority = 'priority';
    public const fields_source_module = 'source_module';
    public const fields_metadata = 'metadata';
    public const fields_is_icon = 'is_icon';
    public const fields_is_img = 'is_img';
    public const fields_avatar = 'avatar';
    public const fields_external_notified = 'external_notified';
    public const fields_external_channels = 'external_channels';

    public array $_unit_primary_keys = ['notification_id'];
    public array $_index_sort_keys = ['notification_id', 'topic_code', 'type'];

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('系统通知表')
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
                    "default 'system_info'",
                    '消息主题'
                )
                ->addColumn(
                    self::fields_type,
                    TableInterface::column_type_VARCHAR,
                    20,
                    "default 'info'",
                    '类型：info/success/warning/error/urgent'
                )
                ->addColumn(
                    self::fields_title,
                    TableInterface::column_type_VARCHAR,
                    200,
                    'not null',
                    '标题'
                )
                ->addColumn(
                    self::fields_content,
                    TableInterface::column_type_TEXT,
                    0,
                    '',
                    '内容'
                )
                ->addColumn(
                    self::fields_priority,
                    TableInterface::column_type_SMALLINT,
                    1,
                    'default 5',
                    '优先级 1-10'
                )
                ->addColumn(
                    self::fields_source_module,
                    TableInterface::column_type_VARCHAR,
                    100,
                    "default ''",
                    '来源模块'
                )
                ->addColumn(
                    self::fields_metadata,
                    TableInterface::column_type_TEXT,
                    0,
                    '',
                    '扩展数据 JSON'
                )
                ->addColumn(
                    self::fields_is_icon,
                    TableInterface::column_type_SMALLINT,
                    1,
                    'default 1',
                    '是否图标'
                )
                ->addColumn(
                    self::fields_is_img,
                    TableInterface::column_type_SMALLINT,
                    1,
                    'default 0',
                    '是否图片'
                )
                ->addColumn(
                    self::fields_avatar,
                    TableInterface::column_type_VARCHAR,
                    255,
                    "default 'ri-notification-line'",
                    '头像/图标'
                )
                ->addColumn(
                    self::fields_external_notified,
                    TableInterface::column_type_SMALLINT,
                    1,
                    'default 0',
                    '是否已通知外部'
                )
                ->addColumn(
                    self::fields_external_channels,
                    TableInterface::column_type_TEXT,
                    0,
                    '',
                    '已通知渠道 JSON'
                )
                ->addIndex(TableInterface::index_type_KEY, 'idx_topic_code', self::fields_topic_code, '主题索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_type', self::fields_type, '类型索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_priority', self::fields_priority, '优先级索引')
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

    public function getType(): string
    {
        return (string) $this->getData(self::fields_type);
    }

    public function setType(string $type): static
    {
        return $this->setData(self::fields_type, $type);
    }

    public function getTitle(): string
    {
        return (string) $this->getData(self::fields_title);
    }

    public function setTitle(string $title): static
    {
        return $this->setData(self::fields_title, $title);
    }

    public function getContent(): string
    {
        return (string) $this->getData(self::fields_content);
    }

    public function setContent(string $content): static
    {
        return $this->setData(self::fields_content, $content);
    }

    public function getPriority(): int
    {
        return (int) $this->getData(self::fields_priority);
    }

    public function setPriority(int $priority): static
    {
        return $this->setData(self::fields_priority, $priority);
    }

    public function getSourceModule(): string
    {
        return (string) $this->getData(self::fields_source_module);
    }

    public function setSourceModule(string $module): static
    {
        return $this->setData(self::fields_source_module, $module);
    }

    public function getMetadata(): array
    {
        $json = $this->getData(self::fields_metadata);
        if (empty($json)) {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function setMetadata(array $data): static
    {
        return $this->setData(self::fields_metadata, json_encode($data));
    }

    public function isIcon(): bool
    {
        return (bool) $this->getData(self::fields_is_icon);
    }

    public function setIsIcon(bool $isIcon): static
    {
        return $this->setData(self::fields_is_icon, $isIcon ? 1 : 0);
    }

    public function isImg(): bool
    {
        return (bool) $this->getData(self::fields_is_img);
    }

    public function setIsImg(bool $isImg): static
    {
        return $this->setData(self::fields_is_img, $isImg ? 1 : 0);
    }

    public function getAvatar(): string
    {
        return (string) $this->getData(self::fields_avatar);
    }

    public function setAvatar(string $avatar): static
    {
        return $this->setData(self::fields_avatar, $avatar);
    }

    public function isExternalNotified(): bool
    {
        return (bool) $this->getData(self::fields_external_notified);
    }

    public function setExternalNotified(bool $notified): static
    {
        return $this->setData(self::fields_external_notified, $notified ? 1 : 0);
    }

    public function getExternalChannels(): array
    {
        $json = $this->getData(self::fields_external_channels);
        if (empty($json)) {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function setExternalChannels(array $channels): static
    {
        return $this->setData(self::fields_external_channels, json_encode($channels));
    }
}
