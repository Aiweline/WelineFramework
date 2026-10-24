<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Setup;

use Weline\AiAcquisition\Model\Lead;
use Weline\AiAcquisition\Model\LeadConversation;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Db\Setup as DbSetup;
use Weline\Framework\Setup\UpgradeInterface;

/**
 * AiAcquisition 升级（幂等）：
 * - w_ai_acquisition_lead 补 5 新列（lead_type/outreach_target/ai_auto_reply/customer_id/first_seen_date），
 *   本地已手工加列，探测到列存在即跳过
 * - w_ai_acquisition_conversation 表兜底（Model #[Col] 声明由 RSSE 建表，此处表缺失时按声明补建）
 */
class Upgrade implements UpgradeInterface
{
    /** @return array<string, array{type:string, length:string|int, options:string, comment:string, after:string}> */
    private const LEAD_NEW_COLUMNS = [
        'lead_type' => ['type' => 'varchar', 'length' => 32, 'options' => "NOT NULL DEFAULT 'unknown'", 'comment' => '线索类型', 'after' => 'raw_json'],
        'outreach_target' => ['type' => 'smallint', 'length' => '', 'options' => 'NOT NULL DEFAULT 0', 'comment' => '是否外联目标', 'after' => 'lead_type'],
        'ai_auto_reply' => ['type' => 'smallint', 'length' => '', 'options' => 'NOT NULL DEFAULT 0', 'comment' => '是否AI自动回复', 'after' => 'outreach_target'],
        'customer_id' => ['type' => 'int', 'length' => '', 'options' => 'NOT NULL DEFAULT 0', 'comment' => '转化客户ID', 'after' => 'ai_auto_reply'],
        'first_seen_date' => ['type' => 'date', 'length' => '', 'options' => 'NULL DEFAULT NULL', 'comment' => '首次发现日期', 'after' => 'customer_id'],
        'entry_channel' => ['type' => 'varchar', 'length' => 32, 'options' => "NOT NULL DEFAULT 'default'", 'comment' => '录入渠道', 'after' => 'first_seen_date'],
    ];

    public function setup(Setup $setup, Context $context): void
    {
        $this->ensureLeadColumns();
        $this->ensureConversationTable();
    }

    private function ensureLeadColumns(): void
    {
        /** @var Lead $lead */
        $lead = ObjectManager::getInstance(Lead::class);
        $connection = ConnectionFactory::getInstance();
        $dbSetup = (new DbSetup())->setConnection($connection);

        $missing = [];
        foreach (array_keys(self::LEAD_NEW_COLUMNS) as $column) {
            if (!$dbSetup->columnExist($lead->getTable(), $column)) {
                $missing[] = $column;
            }
        }
        if ($missing === []) {
            return;
        }

        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($lead);
        $alter = $modelSetup->alterTable();
        foreach ($missing as $column) {
            $def = self::LEAD_NEW_COLUMNS[$column];
            $alter->addColumn($column, $def['after'], $def['type'], $def['length'], $def['options'], $def['comment']);
        }
    }

    /**
     * conversation 表兜底：Model 声明驱动不自动建时补建（幂等：表存在即跳过）。
     */
    private function ensureConversationTable(): void
    {
        /** @var LeadConversation $conversation */
        $conversation = ObjectManager::getInstance(LeadConversation::class);
        $connection = ConnectionFactory::getInstance();
        $dbSetup = (new DbSetup())->setConnection($connection);
        if ($dbSetup->tableExist($conversation->getTable())) {
            return;
        }

        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($conversation);
        $dbSetup->createTable($conversation->getTable(), 'AI获客线索沟通记录')
            ->addColumn('id', 'int', null, 'auto_increment primary key', 'ID')
            ->addColumn(LeadConversation::schema_fields_LEAD_ID, 'int', null, 'NOT NULL DEFAULT 0', '线索ID')
            ->addColumn(LeadConversation::schema_fields_RUN_ID, 'int', null, 'NOT NULL DEFAULT 0', '关联运行ID')
            ->addColumn(LeadConversation::schema_fields_DIRECTION, 'varchar', 8, "NOT NULL DEFAULT 'out'", '方向 in/out')
            ->addColumn(LeadConversation::schema_fields_CHANNEL, 'varchar', 32, "NOT NULL DEFAULT 'email'", '沟通渠道')
            ->addColumn(LeadConversation::schema_fields_MAIL_MESSAGE_ID, 'int', null, 'NULL DEFAULT NULL', '关联MailMessage ID')
            ->addColumn(LeadConversation::schema_fields_ROLE, 'varchar', 20, "NOT NULL DEFAULT 'agent'", '角色 agent/ai/human/customer/system')
            ->addColumn(LeadConversation::schema_fields_CONTENT, 'text', null, 'NULL', '内容')
            ->addColumn(LeadConversation::schema_fields_AI_MODEL_CODE, 'varchar', 100, "NOT NULL DEFAULT ''", 'AI模型代码')
            ->addColumn(LeadConversation::schema_fields_TOKENS_IN, 'int', null, 'NOT NULL DEFAULT 0', '输入token')
            ->addColumn(LeadConversation::schema_fields_TOKENS_OUT, 'int', null, 'NOT NULL DEFAULT 0', '输出token')
            ->addColumn(LeadConversation::schema_fields_HUMAN_APPROVED, 'smallint', null, 'NOT NULL DEFAULT 0', '是否人工审批后发送')
            ->addColumn(LeadConversation::schema_fields_SENT_AT, 'int', null, 'NOT NULL DEFAULT 0', '实际发送时间')
            ->addColumn(LeadConversation::schema_fields_CREATED_AT, 'int', null, 'NOT NULL DEFAULT 0', '创建时间')
            ->addColumn(LeadConversation::schema_fields_UPDATED_AT, 'int', null, 'NOT NULL DEFAULT 0', '更新时间')
            ->addIndex('UNIQUE', 'idx_ai_acquisition_conv_mail_message', LeadConversation::schema_fields_MAIL_MESSAGE_ID, '邮件消息唯一')
            ->addIndex('INDEX', 'idx_ai_acquisition_conv_lead', [LeadConversation::schema_fields_LEAD_ID, LeadConversation::schema_fields_CREATED_AT], '线索沟通')
            ->create(false);
    }
}
