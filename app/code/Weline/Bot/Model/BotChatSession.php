<?php
declare(strict_types=1);

namespace Weline\Bot\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 聊天会话模型
 *
 * 管理用户与 AI 的对话会话，支持多渠道、多上下文
 */
#[Table(comment: '聊天会话表')]
#[Index(name: 'idx_role_id', columns: ['role_id'])]
#[Index(name: 'idx_channel_context', columns: ['channel', 'context_id'])]
#[Index(name: 'idx_updated_at', columns: ['updated_at'])]
class BotChatSession extends Model
{
    public const schema_table = 'weline_bot_chat_session';
    public const schema_primary_key = 'session_id';

    public array $_unit_primary_keys = ['session_id'];
    public array $_index_sort_keys = ['session_id', 'role_id', 'updated_at'];

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '会话ID')]
    public const schema_fields_SESSION_ID = 'session_id';

    #[Col('int', 11, nullable: false, comment: '角色ID')]
    public const schema_fields_ROLE_ID = 'role_id';

    #[Col('varchar', 100, nullable: false, comment: '来源渠道：web/dingtalk/feishu/telegram/webhook')]
    public const schema_fields_CHANNEL = 'channel';

    #[Col('varchar', 255, nullable: false, comment: '上下文标识（用户ID/群ID等）')]
    public const schema_fields_CONTEXT_ID = 'context_id';

    #[Col('varchar', 255, comment: '会话标题')]
    public const schema_fields_TITLE = 'title';

    #[Col('text', comment: '会话元数据（JSON）')]
    public const schema_fields_METADATA = 'metadata';

    #[Col('varchar', 50, default: 'active', comment: '状态：active/archived/deleted')]
    public const schema_fields_STATUS = 'status';

    #[Col('int', comment: '消息数量')]
    public const schema_fields_MESSAGE_COUNT = 'message_count';

    #[Col('int', comment: '总Token消耗')]
    public const schema_fields_TOTAL_TOKENS = 'total_tokens';

    #[Col('int', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('int', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';
    public const STATUS_DELETED = 'deleted';

    public const CHANNEL_WEB = 'web';
    public const CHANNEL_DINGTALK = 'dingtalk';
    public const CHANNEL_FEISHU = 'feishu';
    public const CHANNEL_TELEGRAM = 'telegram';
    public const CHANNEL_WEBHOOK = 'webhook';

    private ?BotRole $roleModel = null;

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_SESSION_ID;
    }

    /**
     * 获取元数据
     */
    public function getMetadata(): array
    {
        $metadata = $this->getData(self::schema_fields_METADATA);
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($metadata) ? $metadata : [];
    }

    /**
     * 设置元数据
     */
    public function setMetadata(array $metadata): self
    {
        return $this->setData(self::schema_fields_METADATA, json_encode($metadata, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 获取关联角色
     */
    public function getRole(): ?BotRole
    {
        if ($this->roleModel === null && $this->getData(self::schema_fields_ROLE_ID)) {
            $this->roleModel = (new BotRole())->load($this->getData(self::schema_fields_ROLE_ID));
        }
        return $this->roleModel;
    }

    /**
     * 是否活跃
     */
    public function isActive(): bool
    {
        return $this->getData(self::schema_fields_STATUS) === self::STATUS_ACTIVE;
    }

    /**
     * 增加消息计数
     */
    public function incrementMessageCount(int $tokens = 0): self
    {
        $count = (int) $this->getData(self::schema_fields_MESSAGE_COUNT) + 1;
        $totalTokens = (int) $this->getData(self::schema_fields_TOTAL_TOKENS) + $tokens;
        $this->setData(self::schema_fields_MESSAGE_COUNT, $count);
        $this->setData(self::schema_fields_TOTAL_TOKENS, $totalTokens);
        $this->setData(self::schema_fields_UPDATED_AT, time());
        return $this;
    }

    public function beforeSave(): self
    {
        $now = time();
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
            $this->setData(self::schema_fields_MESSAGE_COUNT, 0);
            $this->setData(self::schema_fields_TOTAL_TOKENS, 0);
        }
        $this->setData(self::schema_fields_UPDATED_AT, $now);

        if (is_array($this->getData(self::schema_fields_METADATA))) {
            $this->setData(self::schema_fields_METADATA, json_encode(
                $this->getData(self::schema_fields_METADATA),
                JSON_UNESCAPED_UNICODE
            ));
        }

        return parent::beforeSave();
    }
}
