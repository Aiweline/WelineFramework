<?php
declare(strict_types=1);
namespace Weline\Backend\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '用户通知状态表')]
#[Index(name: 'uk_user_notification', columns: ['user_id', 'notification_id'], type: 'UNIQUE', comment: '用户通知唯一')]
#[Index(name: 'idx_user_id', columns: ['user_id'], comment: '用户索引')]
#[Index(name: 'idx_notification_id', columns: ['notification_id'], comment: '通知索引')]
class UserNotificationStatus extends Model
{
    public const schema_table = 'weline_backend_user_notification_status';
    public const schema_primary_key = 'status_id';
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '主键')]
    public const schema_fields_ID = 'status_id';
    #[Col(type: 'int', nullable: false, comment: '后台用户 ID')]
    public const schema_fields_user_id = 'user_id';
    #[Col(type: 'int', nullable: false, comment: '通知 ID')]
    public const schema_fields_notification_id = 'notification_id';
    #[Col(type: 'smallint', length: 1, default: 0, comment: '是否已读')]
    public const schema_fields_is_read = 'is_read';
    #[Col(type: 'datetime', comment: '阅读时间')]
    public const schema_fields_read_at = 'read_at';
    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = ['status_id', 'user_id', 'notification_id'];

    public function getUserId(): int
    {
        return (int) $this->getData(self::schema_fields_user_id);
    }
    public function setUserId(int $userId): static
    {
        return $this->setData(self::schema_fields_user_id, $userId);
    }
    public function getNotificationId(): int
    {
        return (int) $this->getData(self::schema_fields_notification_id);
    }
    public function setNotificationId(int $notificationId): static
    {
        return $this->setData(self::schema_fields_notification_id, $notificationId);
    }
    public function isRead(): bool
    {
        return (bool) $this->getData(self::schema_fields_is_read);
    }
    public function setIsRead(bool $isRead): static
    {
        return $this->setData(self::schema_fields_is_read, $isRead ? 1 : 0);
    }
    public function getReadAt(): ?string
    {
        return $this->getData(self::schema_fields_read_at);
    }
    public function setReadAt(string $readAt): static
    {
        return $this->setData(self::schema_fields_read_at, $readAt);
    }
    public function markAsRead(): static
    {
        $this->setIsRead(true);
        $this->setReadAt(date('Y-m-d H:i:s'));
        return $this;
    }
}
