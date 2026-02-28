<?php

declare(strict_types=1);

namespace Weline\Backend\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class UserNotificationStatus extends Model
{
    public const fields_ID = 'status_id';
    public const fields_user_id = 'user_id';
    public const fields_notification_id = 'notification_id';
    public const fields_is_read = 'is_read';
    public const fields_read_at = 'read_at';

    public array $_unit_primary_keys = ['status_id'];
    public array $_index_sort_keys = ['status_id', 'user_id', 'notification_id'];

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('用户通知状态表')
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
                    self::fields_notification_id,
                    TableInterface::column_type_INTEGER,
                    0,
                    'not null',
                    '通知 ID'
                )
                ->addColumn(
                    self::fields_is_read,
                    TableInterface::column_type_SMALLINT,
                    1,
                    'default 0',
                    '是否已读'
                )
                ->addColumn(
                    self::fields_read_at,
                    TableInterface::column_type_DATETIME,
                    0,
                    '',
                    '阅读时间'
                )
                ->addIndex(
                    TableInterface::index_type_UNIQUE,
                    'uk_user_notification',
                    'user_id,notification_id',
                    '用户通知唯一'
                )
                ->addIndex(TableInterface::index_type_KEY, 'idx_user_id', self::fields_user_id, '用户索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_notification_id', self::fields_notification_id, '通知索引')
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

    public function getNotificationId(): int
    {
        return (int) $this->getData(self::fields_notification_id);
    }

    public function setNotificationId(int $notificationId): static
    {
        return $this->setData(self::fields_notification_id, $notificationId);
    }

    public function isRead(): bool
    {
        return (bool) $this->getData(self::fields_is_read);
    }

    public function setIsRead(bool $isRead): static
    {
        return $this->setData(self::fields_is_read, $isRead ? 1 : 0);
    }

    public function getReadAt(): ?string
    {
        return $this->getData(self::fields_read_at);
    }

    public function setReadAt(string $readAt): static
    {
        return $this->setData(self::fields_read_at, $readAt);
    }

    public function markAsRead(): static
    {
        $this->setIsRead(true);
        $this->setReadAt(date('Y-m-d H:i:s'));
        return $this;
    }
}
