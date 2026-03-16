<?php
declare(strict_types=1);

namespace Weline\Bot\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 聊天消息模型
 *
 * 存储会话中的每条消息，支持 user/assistant/system/tool 角色
 */
#[Table(comment: '聊天消息表')]
#[Index(name: 'idx_session_id', columns: ['session_id'])]
#[Index(name: 'idx_created_at', columns: ['created_at'])]
class BotChatMessage extends Model
{
    public const schema_table = 'weline_bot_chat_message';
    public const schema_primary_key = 'message_id';

    public array $_unit_primary_keys = ['message_id'];
    public array $_index_sort_keys = ['message_id', 'session_id', 'created_at'];

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '消息ID')]
    public const schema_fields_MESSAGE_ID = 'message_id';

    #[Col('int', 11, nullable: false, comment: '会话ID')]
    public const schema_fields_SESSION_ID = 'session_id';

    #[Col('varchar', 20, nullable: false, comment: '角色：user/assistant/system/tool')]
    public const schema_fields_ROLE = 'role';

    #[Col('text', comment: '消息内容')]
    public const schema_fields_CONTENT = 'content';

    #[Col('text', comment: 'Tool调用记录（JSON）')]
    public const schema_fields_TOOL_CALLS = 'tool_calls';

    #[Col('varchar', 100, comment: 'Tool名称（仅tool角色）')]
    public const schema_fields_TOOL_NAME = 'tool_name';

    #[Col('varchar', 255, comment: 'Tool调用ID')]
    public const schema_fields_TOOL_CALL_ID = 'tool_call_id';

    #[Col('int', comment: '输入Token数')]
    public const schema_fields_INPUT_TOKENS = 'input_tokens';

    #[Col('int', comment: '输出Token数')]
    public const schema_fields_OUTPUT_TOKENS = 'output_tokens';

    #[Col('int', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';
    public const ROLE_SYSTEM = 'system';
    public const ROLE_TOOL = 'tool';

    private ?BotChatSession $session = null;

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_MESSAGE_ID;
    }

    /**
     * 获取Tool调用记录
     */
    public function getToolCalls(): array
    {
        $toolCalls = $this->getData(self::schema_fields_TOOL_CALLS);
        if (is_string($toolCalls)) {
            $decoded = json_decode($toolCalls, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($toolCalls) ? $toolCalls : [];
    }

    /**
     * 设置Tool调用记录
     */
    public function setToolCalls(array $toolCalls): self
    {
        return $this->setData(self::schema_fields_TOOL_CALLS, json_encode($toolCalls, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 获取关联会话
     */
    public function getSession(): ?BotChatSession
    {
        if ($this->session === null && $this->getData(self::schema_fields_SESSION_ID)) {
            $this->session = (new BotChatSession())->load($this->getData(self::schema_fields_SESSION_ID));
        }
        return $this->session;
    }

    /**
     * 转换为 OpenAI 消息格式
     */
    public function toOpenAIMessage(): array
    {
        $message = [
            'role' => $this->getData(self::schema_fields_ROLE),
            'content' => $this->getData(self::schema_fields_CONTENT),
        ];

        // Tool 调用
        $toolCalls = $this->getToolCalls();
        if (!empty($toolCalls)) {
            $message['tool_calls'] = $toolCalls;
        }

        // Tool 响应
        if ($this->getData(self::schema_fields_ROLE) === self::ROLE_TOOL) {
            $message['tool_call_id'] = $this->getData(self::schema_fields_TOOL_CALL_ID);
        }

        return $message;
    }

    public function beforeSave(): self
    {
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_AT, time());
        }

        if (is_array($this->getData(self::schema_fields_TOOL_CALLS))) {
            $this->setData(self::schema_fields_TOOL_CALLS, json_encode(
                $this->getData(self::schema_fields_TOOL_CALLS),
                JSON_UNESCAPED_UNICODE
            ));
        }

        return parent::beforeSave();
    }
}
