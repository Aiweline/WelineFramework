<?php
declare(strict_types=1);

namespace Weline\Agent\Service;

use Weline\Agent\Model\AgentRole;
use Weline\Agent\Model\AgentChatSession;

/**
 * 技能执行上下文
 *
 * 封装技能执行时的上下文信息
 */
class SkillContext
{
    public function __construct(
        public readonly AgentRole $role,
        public readonly AgentChatSession $session,
        public readonly array $extra = [],
    ) {}

    /**
     * 获取角色
     */
    public function getRole(): AgentRole
    {
        return $this->role;
    }

    /**
     * 获取会话
     */
    public function getSession(): AgentChatSession
    {
        return $this->session;
    }

    /**
     * 获取上下文 ID
     */
    public function getContextId(): string
    {
        return $this->session->getData(\Weline\Agent\Model\AgentChatSession::schema_fields_CONTEXT_ID);
    }

    /**
     * 获取渠道
     */
    public function getChannel(): string
    {
        return $this->session->getData(\Weline\Agent\Model\AgentChatSession::schema_fields_CHANNEL);
    }

    /**
     * 获取额外数据
     */
    public function getExtra(string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->extra;
        }
        return $this->extra[$key] ?? $default;
    }
}
