<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Service;

use Weline\AiAcquisition\Model\Lead;
use Weline\AiAcquisition\Model\LeadConversation;
use Weline\AiAcquisition\Model\LeadRun;
use Weline\AiAcquisition\Service\Model\ModelCatalogService;
use Weline\AiAcquisition\Service\Model\ModelGatewayService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Model\MailAccount;
use Weline\Mail\Model\MailMessage;
use Weline\Mail\Service\MailComposerService;
use Weline\SystemConfig\Api\ConfigReader;

/**
 * AI 自动回复引擎。
 *
 * replyIfDue() 频控口径：
 * - Lead 级：ai_auto_reply=1 且 status ∈ (contacted/replied)
 * - 全局：SystemConfig ai_acquisition/auto_reply/enabled=1（website scope）
 * - 冷却：最近一条 direction=out 会话在 cooldown_days 内不追发
 * - 日限：当日该发件账户发出的 out 会话数 < daily_limit
 * - 轮数：AI 出站会话 ≥3 且 lead 未 converted → 永久停发（skipped=max_rounds）
 *
 * 提示词：SystemConfig ai_acquisition/prompt/{lead_type}，缺省回落 ai_acquisition/prompt/default。
 * 模型：与获客运行一致——取最新 LeadRun 的 prompt_profile → 模型代码，缺省回落 chat 默认模型。
 * 发送：MailComposerService::send()，source='ai_acquisition_lead'，source_id=lead.id；
 *       send() 不返回 message_id（架构风险①，Mail 侧零改动），此处按 source/source_id 反查最新
 *       MailMessage id 回填 mail_message_id。
 */
class AutoReplyEngine
{
    private const MODULE = 'Weline_AiAcquisition';

    /** AI 代发硬约束（不进贸易数据） */
    private const HARD_CONSTRAINTS =
        "硬约束（必须遵守）：\n"
        . "- 绝不报价、不提任何价格/折扣/运费\n"
        . "- 绝不报 MOQ（最小起订量）\n"
        . "- 绝不承诺交期/货期\n"
        . "- 绝不提及库存数量\n"
        . "如客户问到以上内容，统一回复：具体商务条款请与我们的销售同事确认，我会转达。";

    public function __construct(
        private ?ConfigReader $configReader = null,
        private ?ModelCatalogService $catalog = null,
        private ?ModelGatewayService $gateway = null,
    ) {
        $this->configReader = $this->configReader ?? ObjectManager::getInstance(ConfigReader::class);
        $this->catalog = $this->catalog ?? ObjectManager::getInstance(ModelCatalogService::class);
        $this->gateway = $this->gateway ?? ObjectManager::getInstance(ModelGatewayService::class);
    }

    /**
     * 若到期则生成并发送 AI 回复。
     *
     * @return array{skipped?:string, sent?:bool, reason?:string, conversation_id?:int, tokens_in?:int, tokens_out?:int}
     */
    public function replyIfDue(Lead $lead): array
    {
        $leadId = (int)$lead->getId();
        if ($leadId <= 0) {
            return ['skipped' => 'invalid_lead'];
        }
        if ((int)$lead->getData(Lead::schema_fields_AI_AUTO_REPLY) !== 1) {
            return ['skipped' => 'lead_auto_reply_off'];
        }
        $status = (string)$lead->getData(Lead::schema_fields_STATUS);
        if (!in_array($status, [Lead::STATUS_CONTACTED, Lead::STATUS_REPLIED], true)) {
            return ['skipped' => 'status_not_eligible'];
        }
        $to = strtolower(trim((string)$lead->getData(Lead::schema_fields_EMAIL)));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['skipped' => 'invalid_email'];
        }

        $enabled = (int)$this->config('ai_acquisition/auto_reply/enabled', 0);
        if ($enabled !== 1) {
            return ['skipped' => 'global_disabled'];
        }

        $conversationModel = ObjectManager::getInstance(LeadConversation::class);

        // 3 轮未成交停止
        $aiRounds = $this->countBy($conversationModel, $leadId, LeadConversation::DIRECTION_OUT, [LeadConversation::ROLE_AI, LeadConversation::ROLE_AGENT]);
        if ($aiRounds >= 3 && $status !== Lead::STATUS_CONVERTED) {
            return ['skipped' => 'max_rounds'];
        }

        // 频控：冷却天数
        $cooldownDays = max(0, (int)$this->config('ai_acquisition/auto_reply/cooldown_days', 7));
        if ($cooldownDays > 0) {
            $lastSentAt = $this->lastOutSentAt($conversationModel, $leadId);
            if ($lastSentAt > 0 && (time() - $lastSentAt) < $cooldownDays * 86400) {
                return ['skipped' => 'cooldown'];
            }
        }

        // 发件账户：SystemConfig 配置优先，缺省 contact@changanhanfu.com 对应账户
        $accountId = $this->resolveOutboundAccountId();
        if ($accountId <= 0) {
            return ['skipped' => 'no_mail_account'];
        }

        // 频控：发件账户日上限
        $dailyLimit = max(1, (int)$this->config('ai_acquisition/auto_reply/daily_limit', 50));
        $sentToday = $this->countSentTodayByAccount($conversationModel, $accountId);
        if ($sentToday >= $dailyLimit) {
            return ['skipped' => 'daily_limit'];
        }

        // 提示词模板（按 lead_type，缺省回落 default）
        $leadType = (string)($lead->getData(Lead::schema_fields_LEAD_TYPE) ?: Lead::LEAD_TYPE_UNKNOWN);
        $template = trim((string)$this->config('ai_acquisition/prompt/' . $leadType, ''));
        if ($template === '') {
            $template = trim((string)$this->config('ai_acquisition/prompt/default', ''));
        }
        if ($template === '') {
            return ['skipped' => 'no_prompt_template'];
        }

        // 上下文：公司/国家/类型/证据/最近 3 条会话
        $recent = $this->recentConversations($conversationModel, $leadId, 3);
        $contextLines = [
            '公司：' . (string)$lead->getData(Lead::schema_fields_COMPANY_NAME),
            '国家：' . (string)$lead->getData(Lead::schema_fields_COUNTRY_NAME),
            '线索类型：' . $leadType,
            '来源证据：' . mb_substr((string)$lead->getData(Lead::schema_fields_SOURCE_EXCERPT), 0, 500),
            '最近沟通：' . implode(' | ', $recent),
        ];
        $systemPrompt = $template . "\n\n" . self::HARD_CONSTRAINTS . "\n\n【线索上下文】\n" . implode("\n", $contextLines);

        // 模型：与获客运行一致（最新 LeadRun 的 prompt_profile → 模型代码），缺省 chat 默认模型
        $model = $this->resolveModel();
        if ($model === null) {
            return ['skipped' => 'no_model'];
        }

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => (string)__('请根据以上线索上下文，撰写一封面向该客户的英文跟进邮件正文。')],
        ];

        try {
            $result = $this->gateway->chat($model, $messages);
        } catch (\Throwable $e) {
            return ['skipped' => 'ai_call_failed', 'reason' => $e->getMessage()];
        }

        $content = trim((string)($result['content'] ?? ''));
        if ($content === '') {
            return ['skipped' => 'ai_empty_reply'];
        }

        $usage = is_array($result['usage'] ?? null) ? $result['usage'] : [];
        $tokensIn = (int)($usage['prompt_tokens'] ?? 0);
        $tokensOut = (int)($usage['completion_tokens'] ?? 0);

        // 发送（AI 生成即发送，无人工确认步骤；require_approval 默认 1 仅作记录标记）
        $requireApproval = (int)$this->config('ai_acquisition/auto_reply/require_approval', 1);
        $subject = (string)__('【%1】感谢您的关注 — 跟进', [(string)$lead->getData(Lead::schema_fields_COMPANY_NAME) ?: $to]);
        $sendResult = ObjectManager::getInstance(MailComposerService::class)->send(
            $accountId,
            $to,
            $subject,
            $content,
            LeadConversation::MAIL_SOURCE,
            $leadId,
        );
        if (empty($sendResult['success'])) {
            return ['skipped' => 'send_failed', 'reason' => (string)($sendResult['message'] ?? '')];
        }

        // send() 不返回 message_id，按 source/source_id 反查最新一封
        $mailMessageId = $this->latestMailMessageId($leadId);

        /** @var LeadConversation $conversation */
        $conversation = clone $conversationModel;
        $conversation->clearData()->reset()->setData([
            LeadConversation::schema_fields_LEAD_ID => $leadId,
            LeadConversation::schema_fields_RUN_ID => 0,
            LeadConversation::schema_fields_DIRECTION => LeadConversation::DIRECTION_OUT,
            LeadConversation::schema_fields_CHANNEL => LeadConversation::CHANNEL_EMAIL,
            LeadConversation::schema_fields_MAIL_MESSAGE_ID => $mailMessageId,
            LeadConversation::schema_fields_ROLE => LeadConversation::ROLE_AI,
            LeadConversation::schema_fields_CONTENT => $content,
            LeadConversation::schema_fields_AI_MODEL_CODE => (string)($result['model_code'] ?? ''),
            LeadConversation::schema_fields_TOKENS_IN => $tokensIn,
            LeadConversation::schema_fields_TOKENS_OUT => $tokensOut,
            LeadConversation::schema_fields_HUMAN_APPROVED => $requireApproval === 1 ? 0 : 1,
            LeadConversation::schema_fields_SENT_AT => time(),
        ])->save();

        // 首次发出：new → contacted
        if ($status === Lead::STATUS_NEW) {
            $lead->setData(Lead::schema_fields_STATUS, Lead::STATUS_CONTACTED);
            $lead->save();
        }

        return [
            'sent' => true,
            'conversation_id' => (int)$conversation->getId(),
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
        ];
    }

    private function config(string $path, mixed $default): mixed
    {
        return $this->configReader->get($path, self::MODULE, ConfigReader::area_BACKEND, $default);
    }

    private function resolveOutboundAccountId(): int
    {
        $configured = (int)$this->config('ai_acquisition/outreach/mail_account_id', 0);
        if ($configured > 0) {
            return $configured;
        }
        // 缺省：contact@changanhanfu.com 对应的启用账户
        /** @var MailAccount $account */
        $account = ObjectManager::getInstance(MailAccount::class)->clear()
            ->where(MailAccount::schema_fields_EMAIL, 'contact@changanhanfu.com')
            ->where(MailAccount::schema_fields_STATUS, 'active')
            ->find()
            ->fetch();
        return (int)$account->getId();
    }

    private function resolveModel(): array|null
    {
        $modelCode = '';
        try {
            $run = ObjectManager::getInstance(LeadRun::class)->clearData()->reset()
                ->order(LeadRun::schema_fields_ID, 'DESC')
                ->find()
                ->fetch();
            if ($run && $run->getId()) {
                // LeadRun.prompt_profile_id 同款模型口径：直接取 run 的 model_code
                $modelCode = (string)$run->getData(LeadRun::schema_fields_MODEL_CODE);
            }
        } catch (\Throwable) {
            // 缺省回落 chat 默认模型
        }
        return $this->catalog->resolve($modelCode !== '' ? $modelCode : null);
    }

    /** @return list<string> 最近 N 条会话内容（含进出） */
    private function recentConversations(object $conversationModel, int $leadId, int $limit): array
    {
        try {
            $rows = clone $conversationModel;
            $items = $rows->clearData()->reset()
                ->where(LeadConversation::schema_fields_LEAD_ID, $leadId)
                ->order(LeadConversation::schema_fields_ID, 'DESC')
                ->limit($limit)
                ->select()
                ->fetchArray();
            $out = [];
            foreach (is_array($items) ? $items : [] as $row) {
                $content = trim((string)($row[LeadConversation::schema_fields_CONTENT] ?? ''));
                if ($content !== '') {
                    $out[] = mb_substr($content, 0, 300);
                }
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    private function countBy(object $conversationModel, int $leadId, string $direction, array $roles): int
    {
        try {
            $query = clone $conversationModel;
            $query = $query->clearData()->reset()
                ->where(LeadConversation::schema_fields_LEAD_ID, $leadId)
                ->where(LeadConversation::schema_fields_DIRECTION, $direction);
            foreach ($roles as $role) {
                $query = $query->where(LeadConversation::schema_fields_ROLE, $role, '=', 'OR');
            }
            // 多角色 OR：SQL 语义为 lead AND direction AND (role=r1 OR role=r2)
            return count($query->select()->fetchArray() ?: []);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function lastOutSentAt(object $conversationModel, int $leadId): int
    {
        try {
            $row = clone $conversationModel;
            $items = $row->clearData()->reset()
                ->where(LeadConversation::schema_fields_LEAD_ID, $leadId)
                ->where(LeadConversation::schema_fields_DIRECTION, LeadConversation::DIRECTION_OUT)
                ->order(LeadConversation::schema_fields_SENT_AT, 'DESC')
                ->limit(1)
                ->select()
                ->fetchArray();
            return (int)(($items[0] ?? [])[LeadConversation::schema_fields_SENT_AT] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /** 当日该发件账户已发出的 out 会话数（按 mail_message_id 关联账户不现实，退化为按日全账户计数并注明） */
    private function countSentTodayByAccount(object $conversationModel, int $accountId): int
    {
        // 架构文档第 5 节：daily_limit 为全站每日自动外发上限；conversation 未落 account_id，
        // 按“当日 direction=out 全量计数”实现（量级可接受；并发超发风险见架构文档风险⑤）。
        try {
            $start = strtotime(date('Y-m-d 00:00:00'));
            $rows = clone $conversationModel;
            $items = $rows->clearData()->reset()
                ->where(LeadConversation::schema_fields_DIRECTION, LeadConversation::DIRECTION_OUT)
                ->where(LeadConversation::schema_fields_SENT_AT, $start, '>=')
                ->select()
                ->fetchArray();
            return count(is_array($items) ? $items : []);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function latestMailMessageId(int $leadId): int
    {
        try {
            /** @var MailMessage $message */
            $message = ObjectManager::getInstance(MailMessage::class)->clear()
                ->where(MailMessage::schema_fields_SOURCE, LeadConversation::MAIL_SOURCE)
                ->where(MailMessage::schema_fields_SOURCE_ID, $leadId)
                ->order(MailMessage::schema_fields_ID, 'DESC')
                ->find()
                ->fetch();
            return (int)$message->getId();
        } catch (\Throwable) {
            return 0;
        }
    }
}
