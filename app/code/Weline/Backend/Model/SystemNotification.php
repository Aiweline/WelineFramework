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
#[Index(name: 'uk_topic_scope_dedupe', columns: ['topic_code', 'scope_hash', 'dedupe_key'], type: 'UNIQUE', comment: 'Scope topic 去重')]
class SystemNotification extends Model
{
    public const schema_table = 'weline_backend_system_notification';
    public const schema_primary_key = 'notification_id';
    public const SCOPE_HASH_GLOBAL = 'global';

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
    #[Col(type: 'varchar', length: 255, default: 'bell', comment: '头像/图标')]
    public const schema_fields_avatar = 'avatar';
    #[Col(type: 'smallint', length: 1, default: 0, comment: '是否已通知外部')]
    public const schema_fields_external_notified = 'external_notified';
    #[Col(type: 'text', comment: '已通知渠道 JSON')]
    public const schema_fields_external_channels = 'external_channels';
    /** P1b：通知归属 Scope（website.store.channel 三段串，空串 = 全局通知） */
    #[Col(type: 'varchar', length: 200, default: '', comment: '归属 Scope（三段串，空=全局）')]
    public const schema_fields_scope = 'scope';
    /** P1B-004-NOTIFY：Scope 身份哈希（global 或 ScopeIdentity::canonicalKey 摘要） */
    #[Col(type: 'varchar', length: 64, nullable: false, default: 'global', comment: 'Scope hash')]
    public const schema_fields_SCOPE_HASH = 'scope_hash';
    /** P1b：会话/主题去重键（空=不去重；与 topic+scope_hash 唯一） */
    #[Col(type: 'varchar', length: 191, nullable: true, comment: '会话展示去重键')]
    public const schema_fields_dedupe_key = 'dedupe_key';
    #[Col(type: 'int', nullable: false, default: 1, comment: '同一 dedupe 出现次数')]
    public const schema_fields_OCCURRENCE_COUNT = 'occurrence_count';
    #[Col(type: 'datetime', nullable: true, comment: '最近一次出现时间')]
    public const schema_fields_LAST_OCCURRENCE_AT = 'last_occurrence_at';
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

    /** 归属 Scope 三段串；空串 = 全局通知 */
    public function getScope(): string
    {
        return (string)$this->getData(self::schema_fields_scope);
    }

    public function setScope(?string $scope): static
    {
        return $this->setData(self::schema_fields_scope, trim((string)$scope));
    }

    public function getDedupeKey(): string
    {
        $value = $this->getData(self::schema_fields_dedupe_key);
        return ($value === null || $value === '') ? '' : (string)$value;
    }

    /** 空字符串写 NULL（稀疏索引友好）；超长截断到 191 字节 */
    public function setDedupeKey(?string $dedupeKey): static
    {
        $value = trim((string)$dedupeKey);
        if ($value === '') {
            return $this->setData(self::schema_fields_dedupe_key, null);
        }
        if (strlen($value) > 191) {
            $value = substr($value, 0, 191);
        }
        return $this->setData(self::schema_fields_dedupe_key, $value);
    }

    public function getScopeHash(): string
    {
        $value = \trim((string)$this->getData(self::schema_fields_SCOPE_HASH));

        return $value === '' ? self::SCOPE_HASH_GLOBAL : $value;
    }

    public function setScopeHash(?string $scopeHash): static
    {
        $value = \trim((string)$scopeHash);
        if ($value === '') {
            $value = self::SCOPE_HASH_GLOBAL;
        }
        if (\strlen($value) > 64) {
            $value = \substr($value, 0, 64);
        }

        return $this->setData(self::schema_fields_SCOPE_HASH, $value);
    }

    public function getOccurrenceCount(): int
    {
        return \max(1, (int)$this->getData(self::schema_fields_OCCURRENCE_COUNT));
    }

    public function setOccurrenceCount(int $count): static
    {
        return $this->setData(self::schema_fields_OCCURRENCE_COUNT, \max(1, $count));
    }

    public function getLastOccurrenceAt(): string
    {
        return (string)($this->getData(self::schema_fields_LAST_OCCURRENCE_AT) ?? '');
    }

    public function setLastOccurrenceAt(?string $at): static
    {
        return $this->setData(self::schema_fields_LAST_OCCURRENCE_AT, $at);
    }

    public function save_before()
    {
        parent::save_before();
        $isNew = !$this->hasData(self::schema_fields_ID) || (int)$this->getData(self::schema_fields_ID) <= 0;
        if ($isNew && trim((string)$this->getData(self::schema_fields_scope)) === '') {
            try {
                $websiteCode = trim(\Weline\Framework\Runtime\RequestContext::getWelineWebsiteCode());
                if ($websiteCode !== '') {
                    $this->setData(self::schema_fields_scope, \Weline\Framework\Runtime\ScopeContext::getScope());
                }
            } catch (\Throwable) {
                // 无请求上下文（CLI/Worker）：保持全局
            }
        }
        if ($isNew) {
            if (\trim((string)$this->getData(self::schema_fields_SCOPE_HASH)) === '') {
                $this->setScopeHash(self::SCOPE_HASH_GLOBAL);
            }
            if ((int)$this->getData(self::schema_fields_OCCURRENCE_COUNT) <= 0) {
                $this->setOccurrenceCount(1);
            }
            if ($this->getData(self::schema_fields_LAST_OCCURRENCE_AT) === null
                || $this->getData(self::schema_fields_LAST_OCCURRENCE_AT) === '') {
                $this->setLastOccurrenceAt(\date('Y-m-d H:i:s'));
            }
        }
    }
}
