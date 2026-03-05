<?php
declare(strict_types=1);
namespace Weline\Backend\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '消息主题表')]
#[Index(name: 'uk_topic_code', columns: ['topic_code'], type: 'UNIQUE', comment: '主题标识唯一')]
#[Index(name: 'idx_topic_group', columns: ['topic_group'], comment: '分组索引')]
#[Index(name: 'idx_module', columns: ['module'], comment: '模块索引')]
class NotificationTopic extends Model
{
    public const schema_table = 'weline_backend_notification_topic';
    public const schema_primary_key = 'topic_id';
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '主键')]
    public const schema_fields_ID = 'topic_id';
    #[Col(type: 'varchar', length: 50, nullable: false, comment: '主题标识')]
    public const schema_fields_topic_code = 'topic_code';
    #[Col(type: 'varchar', length: 100, nullable: false, comment: '主题名称')]
    public const schema_fields_topic_name = 'topic_name';
    #[Col(type: 'varchar', length: 50, default: '', comment: '主题分组')]
    public const schema_fields_topic_group = 'topic_group';
    #[Col(type: 'varchar', length: 100, default: '', comment: '分组名称')]
    public const schema_fields_topic_group_name = 'topic_group_name';
    #[Col(type: 'varchar', length: 500, default: '', comment: '描述')]
    public const schema_fields_description = 'description';
    #[Col(type: 'varchar', length: 100, default: '', comment: '来源模块')]
    public const schema_fields_module = 'module';
    #[Col(type: 'varchar', length: 100, default: 'ri-notification-line', comment: '图标')]
    public const schema_fields_icon = 'icon';
    #[Col(type: 'varchar', length: 20, default: '#50a5f1', comment: '主题色')]
    public const schema_fields_color = 'color';
    #[Col(type: 'text', comment: '默认渠道 JSON')]
    public const schema_fields_default_channels = 'default_channels';
    #[Col(type: 'smallint', length: 1, default: 1, comment: '是否启用')]
    public const schema_fields_is_enabled = 'is_enabled';
    #[Col(type: 'int', default: 0, comment: '排序')]
    public const schema_fields_sort_order = 'sort_order';
    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = ['topic_id', 'topic_code', 'topic_group'];

    public function getTopicCode(): string
    {
        return (string) $this->getData(self::schema_fields_topic_code);
    }
    public function setTopicCode(string $code): static
    {
        return $this->setData(self::schema_fields_topic_code, $code);
    }
    public function getTopicName(): string
    {
        return (string) $this->getData(self::schema_fields_topic_name);
    }
    public function setTopicName(string $name): static
    {
        return $this->setData(self::schema_fields_topic_name, $name);
    }
    public function getTopicGroup(): string
    {
        return (string) $this->getData(self::schema_fields_topic_group);
    }
    public function setTopicGroup(string $group): static
    {
        return $this->setData(self::schema_fields_topic_group, $group);
    }
    public function getTopicGroupName(): string
    {
        return (string) $this->getData(self::schema_fields_topic_group_name);
    }
    public function setTopicGroupName(string $name): static
    {
        return $this->setData(self::schema_fields_topic_group_name, $name);
    }
    public function getDescription(): string
    {
        return (string) $this->getData(self::schema_fields_description);
    }
    public function setDescription(string $desc): static
    {
        return $this->setData(self::schema_fields_description, $desc);
    }
    public function getModule(): string
    {
        return (string) $this->getData(self::schema_fields_module);
    }
    public function setModule(string $module): static
    {
        return $this->setData(self::schema_fields_module, $module);
    }
    public function getIcon(): string
    {
        return (string) $this->getData(self::schema_fields_icon);
    }
    public function setIcon(string $icon): static
    {
        return $this->setData(self::schema_fields_icon, $icon);
    }
    public function getColor(): string
    {
        return (string) $this->getData(self::schema_fields_color);
    }
    public function setColor(string $color): static
    {
        return $this->setData(self::schema_fields_color, $color);
    }
    public function getDefaultChannels(): array
    {
        $json = $this->getData(self::schema_fields_default_channels);
        if (empty($json)) {
            return ['backend'];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : ['backend'];
    }
    public function setDefaultChannels(array $channels): static
    {
        return $this->setData(self::schema_fields_default_channels, json_encode($channels));
    }
    public function isEnabled(): bool
    {
        return (bool) $this->getData(self::schema_fields_is_enabled);
    }
    public function setIsEnabled(bool $enabled): static
    {
        return $this->setData(self::schema_fields_is_enabled, $enabled ? 1 : 0);
    }
    public function getSortOrder(): int
    {
        return (int) $this->getData(self::schema_fields_sort_order);
    }
    public function setSortOrder(int $order): static
    {
        return $this->setData(self::schema_fields_sort_order, $order);
    }
}
