<?php
declare(strict_types=1);
namespace Weline\Backend\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '通知渠道配置表')]
#[Index(name: 'uk_channel_code', columns: ['channel_code'], type: 'UNIQUE', comment: '渠道标识唯一')]
class NotificationChannel extends Model
{
    #[Col('int', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: '主键')]
    public const schema_fields_ID = 'channel_id';
    #[Col('varchar', 50, nullable: false, comment: '渠道标识')]
    public const schema_fields_channel_code = 'channel_code';
    #[Col('varchar', 100, nullable: false, comment: '渠道名称')]
    public const schema_fields_channel_name = 'channel_name';
    #[Col('text', comment: '配置 JSON')]
    public const schema_fields_channel_config = 'channel_config';
    #[Col('text', comment: '订阅主题 JSON（空=全部）')]
    public const schema_fields_subscribed_topics = 'subscribed_topics';
    #[Col('varchar', 20, default: 'warning', comment: '最低级别')]
    public const schema_fields_min_type = 'min_type';
    #[Col('smallint', 1, default: 1, comment: '是否启用')]
    public const schema_fields_is_enabled = 'is_enabled';
    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = ['channel_id', 'channel_code'];
public function getChannelCode(): string
    {
        return (string) $this->getData(self::schema_fields_channel_code);
    }
    public function setChannelCode(string $code): static
    {
        return $this->setData(self::schema_fields_channel_code, $code);
    }
    public function getChannelName(): string
    {
        return (string) $this->getData(self::schema_fields_channel_name);
    }
    public function setChannelName(string $name): static
    {
        return $this->setData(self::schema_fields_channel_name, $name);
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
    public function getSubscribedTopics(): array
    {
        $json = $this->getData(self::schema_fields_subscribed_topics);
        if (empty($json)) {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
    public function setSubscribedTopics(array $topics): static
    {
        return $this->setData(self::schema_fields_subscribed_topics, json_encode($topics));
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
    public function isSubscribedToTopic(string $topicCode): bool
    {
        $topics = $this->getSubscribedTopics();
        if (empty($topics)) {
            return true;
        }
        return in_array($topicCode, $topics, true);
    }
}
