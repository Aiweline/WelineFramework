<?php
declare(strict_types=1);
namespace Weline\Backend\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '系统通知表')]
#[Index(name: 'idx_topic_code', columns: ['topic_code'], comment: '主题索引')]
#[Index(name: 'idx_type', columns: ['type'], comment: '类型索引')]
#[Index(name: 'idx_priority', columns: ['priority'], comment: '优先级索引')]
class SystemNotification extends Model
{
    public const schema_table = 'weline_backend_system_notification';
    public const schema_primary_key = 'notification_id';
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '主键')]
    public const schema_fields_ID = 'notification_id';
    #[Col(type: 'varchar', length: 50, default: 'system_info', comment: '消息主题')]
    public const schema_fields_topic_code = 'topic_code';
    #[Col(type: 'varchar', length: 20, default: 'info', comment: '类型：info/success/warning/error/urgent')]
    public const schema_fields_type = 'type';
    #[Col(type: 'varchar', length: 200, nullable: false, comment: '标题')]
    public const schema_fields_title = 'title';
    #[Col(type: 'text', comment: '内容')]
    public const schema_fields_content = 'content';
    #[Col(type: 'smallint', length: 1, default: 5, comment: '优先级 1-10')]
    public const schema_fields_priority = 'priority';
    #[Col(type: 'varchar', length: 100, default: '', comment: '来源模块')]
    public const schema_fields_source_module = 'source_module';
    #[Col(type: 'text', comment: '扩展数据 JSON')]
    public const schema_fields_metadata = 'metadata';
    #[Col(type: 'smallint', length: 1, default: 1, comment: '是否图标')]
    public const schema_fields_is_icon = 'is_icon';
    #[Col(type: 'smallint', length: 1, default: 0, comment: '是否图片')]
    public const schema_fields_is_img = 'is_img';
    #[Col(type: 'varchar', length: 255, default: 'ri-notification-line', comment: '头像/图标')]
    public const schema_fields_avatar = 'avatar';
    #[Col(type: 'smallint', length: 1, default: 0, comment: '是否已通知外部')]
    public const schema_fields_external_notified = 'external_notified';
    #[Col(type: 'text', comment: '已通知渠道 JSON')]
    public const schema_fields_external_channels = 'external_channels';
    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = ['notification_id', 'topic_code', 'type'];

    public function getTopicCode(): string
    {
        return (string) $this->getData(self::schema_fields_topic_code);
    }
    public function setTopicCode(string $code): static
    {
        return $this->setData(self::schema_fields_topic_code, $code);
    }
    public function getType(): string
    {
        return (string) $this->getData(self::schema_fields_type);
    }
    public function setType(string $type): static
    {
        return $this->setData(self::schema_fields_type, $type);
    }
    public function getTitle(): string
    {
        return (string) $this->getData(self::schema_fields_title);
    }
    public function setTitle(string $title): static
    {
        return $this->setData(self::schema_fields_title, $title);
    }
    public function getContent(): string
    {
        return (string) $this->getData(self::schema_fields_content);
    }
    public function setContent(string $content): static
    {
        return $this->setData(self::schema_fields_content, $content);
    }
    public function getPriority(): int
    {
        return (int) $this->getData(self::schema_fields_priority);
    }
    public function setPriority(int $priority): static
    {
        return $this->setData(self::schema_fields_priority, $priority);
    }
    public function getSourceModule(): string
    {
        return (string) $this->getData(self::schema_fields_source_module);
    }
    public function setSourceModule(string $module): static
    {
        return $this->setData(self::schema_fields_source_module, $module);
    }
    public function getMetadata(): array
    {
        $json = $this->getData(self::schema_fields_metadata);
        if (empty($json)) {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
    public function setMetadata(array $data): static
    {
        return $this->setData(self::schema_fields_metadata, json_encode($data));
    }
    public function isIcon(): bool
    {
        return (bool) $this->getData(self::schema_fields_is_icon);
    }
    public function setIsIcon(bool $isIcon): static
    {
        return $this->setData(self::schema_fields_is_icon, $isIcon ? 1 : 0);
    }
    public function isImg(): bool
    {
        return (bool) $this->getData(self::schema_fields_is_img);
    }
    public function setIsImg(bool $isImg): static
    {
        return $this->setData(self::schema_fields_is_img, $isImg ? 1 : 0);
    }
    public function getAvatar(): string
    {
        return (string) $this->getData(self::schema_fields_avatar);
    }
    public function setAvatar(string $avatar): static
    {
        return $this->setData(self::schema_fields_avatar, $avatar);
    }
    public function isExternalNotified(): bool
    {
        return (bool) $this->getData(self::schema_fields_external_notified);
    }
    public function setExternalNotified(bool $notified): static
    {
        return $this->setData(self::schema_fields_external_notified, $notified ? 1 : 0);
    }
    public function getExternalChannels(): array
    {
        $json = $this->getData(self::schema_fields_external_channels);
        if (empty($json)) {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
    public function setExternalChannels(array $channels): static
    {
        return $this->setData(self::schema_fields_external_channels, json_encode($channels));
    }
}
