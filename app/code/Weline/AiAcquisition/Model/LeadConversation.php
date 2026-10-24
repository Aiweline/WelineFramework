<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * AI 获客线索沟通记录。
 *
 * 一条记录对应一次线索沟通（我方发出/客户来信/人工/系统备注），
 * mail_message_id UNIQUE 指向 weline_mail_message，保证一封邮件至多映射一条会话记录。
 */
#[Table(comment: 'AI获客线索沟通记录')]
#[Index(name: 'idx_ai_acquisition_conv_lead', columns: ['lead_id', 'created_at'])]
#[Index(name: 'idx_ai_acquisition_conv_run', columns: ['run_id'])]
#[Index(name: 'idx_ai_acquisition_conv_mail_message', columns: ['mail_message_id'], type: 'UNIQUE')]
#[Index(name: 'idx_ai_acquisition_conv_direction_channel', columns: ['direction', 'channel'])]
class LeadConversation extends Model
{
    public const schema_table = 'ai_acquisition_conversation';
    public const schema_primary_key = 'id';

    public const DIRECTION_OUT = 'out';   // 我方发出（首次触达/AI回复/人工回复）
    public const DIRECTION_IN  = 'in';    // 客户来信

    public const CHANNEL_EMAIL = 'email';

    public const ROLE_AGENT   = 'agent';    // AI 生成并已发送
    public const ROLE_HUMAN   = 'human';    // 人工编写/改写后发送
    public const ROLE_CUSTOMER = 'customer';// 客户来信原文
    public const ROLE_SYSTEM  = 'system';   // 系统备注/状态流转说明

    /** 需求侧对 AI 角色的别名（spec 用 role='ai'，与架构文档 role='agent' 同义） */
    public const ROLE_AI = 'ai';

    public const MAIL_SOURCE = 'ai_acquisition_lead';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col('int', default: 0, comment: '线索ID')]
    public const schema_fields_LEAD_ID = 'lead_id';
    #[Col('int', default: 0, comment: '关联运行ID')]
    public const schema_fields_RUN_ID = 'run_id';
    #[Col('varchar', 8, default: 'out', comment: '方向 in/out')]
    public const schema_fields_DIRECTION = 'direction';
    #[Col('varchar', 32, default: 'email', comment: '沟通渠道')]
    public const schema_fields_CHANNEL = 'channel';
    #[Col('int', nullable: true, comment: '关联MailMessage ID')]
    public const schema_fields_MAIL_MESSAGE_ID = 'mail_message_id';
    #[Col('varchar', 20, default: 'agent', comment: '角色 agent/ai/human/customer/system')]
    public const schema_fields_ROLE = 'role';
    #[Col('text', nullable: true, comment: '内容')]
    public const schema_fields_CONTENT = 'content';
    #[Col('varchar', 100, default: '', comment: 'AI模型代码')]
    public const schema_fields_AI_MODEL_CODE = 'ai_model_code';
    #[Col('int', default: 0, comment: '输入token')]
    public const schema_fields_TOKENS_IN = 'tokens_in';
    #[Col('int', default: 0, comment: '输出token')]
    public const schema_fields_TOKENS_OUT = 'tokens_out';
    #[Col('smallint', default: 0, comment: '是否人工审批后发送')]
    public const schema_fields_HUMAN_APPROVED = 'human_approved';
    #[Col('int', default: 0, comment: '实际发送时间')]
    public const schema_fields_SENT_AT = 'sent_at';
    #[Col('int', default: 0, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('int', default: 0, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['id', 'lead_id', 'created_at'];

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }

    public function save_before(): void
    {
        parent::save_before();
        $now = time();
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
        $this->setData(self::schema_fields_UPDATED_AT, $now);
    }
}
