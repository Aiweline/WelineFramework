<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Model;

use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Model\MailMessage;

/**
 * 收信回绑（from_email 精确匹配兜底方案）。
 *
 * 匹配规则：msg.from_email === lead.email 且 source='ai_acquisition_lead' 目标一致。
 * 动作：写 LeadConversation(role=customer, direction=in, mail_message_id)；
 *      lead.status: contacted → replied（不覆盖 converted/rejected）。
 *
 * TODO（架构文档风险①/风险②）：In-Reply-To/References 头匹配逻辑暂缓——
 * Mail 模块收信侧不持久化 MailMessage 且 Stalwart 客户端 getMessage() 不透出
 * In-Reply-To/References 头，需 Mail 模块排期补透出后再实现 Message-ID 反查回绑。
 */
class InboundLeadMailBinder
{
    /**
     * @return array{bound:bool, lead_id?:int, reason?:string}
     */
    public function bind(MailMessage $msg): array
    {
        $msgId = (int)$msg->getId();
        if ($msgId <= 0) {
            return ['bound' => false, 'reason' => 'invalid_message'];
        }

        $source = strtolower(trim((string)$msg->getData(MailMessage::schema_fields_SOURCE)));
        $fromEmail = strtolower(trim((string)$msg->getData(MailMessage::schema_fields_FROM_EMAIL)));
        if ($source !== LeadConversation::MAIL_SOURCE || $fromEmail === '') {
            return ['bound' => false, 'reason' => 'source_mismatch'];
        }

        // 幂等：同一封来信至多绑定一次（mail_message_id UNIQUE）
        $conversationModel = ObjectManager::getInstance(LeadConversation::class);
        $existing = clone $conversationModel;
        $existsRow = $existing->clearData()->reset()
            ->where(LeadConversation::schema_fields_MAIL_MESSAGE_ID, $msgId)
            ->find()
            ->fetchArray();
        if (is_array($existsRow) && $existsRow !== []) {
            return ['bound' => false, 'reason' => 'already_bound', 'lead_id' => (int)($existsRow[LeadConversation::schema_fields_LEAD_ID] ?? 0)];
        }

        // from_email 精确匹配 lead.email
        $leadModel = ObjectManager::getInstance(Lead::class);
        $lead = clone $leadModel;
        /** @var Lead $lead */
        $lead = $lead->clearData()->reset()
            ->where(Lead::schema_fields_EMAIL, $fromEmail)
            ->order(Lead::schema_fields_ID, 'DESC')
            ->find()
            ->fetch();
        if (!$lead || !$lead->getId()) {
            return ['bound' => false, 'reason' => 'lead_not_found'];
        }

        $leadId = (int)$lead->getId();

        /** @var LeadConversation $conversation */
        $conversation = clone $conversationModel;
        $conversation->clearData()->reset()->setData([
            LeadConversation::schema_fields_LEAD_ID => $leadId,
            LeadConversation::schema_fields_RUN_ID => 0,
            LeadConversation::schema_fields_DIRECTION => LeadConversation::DIRECTION_IN,
            LeadConversation::schema_fields_CHANNEL => LeadConversation::CHANNEL_EMAIL,
            LeadConversation::schema_fields_MAIL_MESSAGE_ID => $msgId,
            LeadConversation::schema_fields_ROLE => LeadConversation::ROLE_CUSTOMER,
            LeadConversation::schema_fields_CONTENT => (string)$msg->getData(MailMessage::schema_fields_BODY),
            LeadConversation::schema_fields_SENT_AT => time(),
        ])->save();

        // 状态流转：contacted → replied；converted/rejected 不覆盖
        $status = (string)$lead->getData(Lead::schema_fields_STATUS);
        if ($status === Lead::STATUS_CONTACTED) {
            $lead->setData(Lead::schema_fields_STATUS, Lead::STATUS_REPLIED);
            $lead->save();
        }

        return ['bound' => true, 'lead_id' => $leadId];
    }
}
