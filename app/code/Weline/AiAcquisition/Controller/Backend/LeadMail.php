<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Controller\Backend;

use Weline\AiAcquisition\Model\Lead as LeadModel;
use Weline\AiAcquisition\Model\LeadConversation;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Model\MailMessage;
use Weline\Mail\Service\MailComposerService;
use Weline\Mail\Service\MailSmtpAccountService;

/**
 * 线索邮件沟通：弹窗内容 + AJAX 发送。
 *
 * 弹窗（index）走独立 ACL（Weline_AiAcquisition::lead_mail）返回 Lead/mail_modal 部页；
 * 发送（postSend）走独立 ACL（Weline_AiAcquisition::lead_mail_send），成功后：
 * - 写 LeadConversation(direction=out, role=human)
 * - lead.status new → contacted
 */
final class LeadMail extends BackendController
{
    private const DEFAULT_ACCOUNT_EMAIL = 'contact@changanhanfu.com';

    #[Acl('Weline_AiAcquisition::lead_mail', '线索邮件沟通', 'message', '打开线索邮件弹窗与往来时间线', 'Weline_AiAcquisition::lead_list')]
    public function index(): string
    {
        $lead = $this->loadLead();
        if ($lead === null) {
            return $this->jsonResponse(['success' => false, 'message' => (string)__('线索不存在')], 404);
        }

        $accounts = $this->activeAccounts();
        $defaultAccountId = $this->defaultAccountId($accounts);
        $timeline = $this->timeline((int)$lead->getId());

        $this->assign('lead', $lead->getData());
        $this->assign('accounts', $accounts);
        $this->assign('defaultAccountId', $defaultAccountId);
        $this->assign('timeline', $timeline);

        return $this->fetch('mail_modal');
    }

    #[Acl('Weline_AiAcquisition::lead_mail_send', '线索邮件发送', 'message', '经企业邮箱给线索发信并写入沟通记录', 'Weline_AiAcquisition::lead_mail')]
    public function postSend(): string
    {
        $payload = $this->request->getBodyParams();
        if (!is_array($payload) || $payload === []) {
            $payload = $this->request->getParams();
        }
        if (!is_array($payload)) {
            $payload = [];
        }

        $lead = $this->loadLead();
        if ($lead === null) {
            return $this->jsonResponse(['success' => false, 'message' => (string)__('线索不存在')], 404);
        }
        $leadId = (int)$lead->getId();
        $to = strtolower(trim((string)$lead->getData(LeadModel::schema_fields_EMAIL)));
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return $this->jsonResponse(['success' => false, 'message' => (string)__('线索未登记有效邮箱，无法发信')], 422);
        }

        $subject = trim((string)($payload['subject'] ?? ''));
        $body = trim((string)($payload['body'] ?? ''));
        if ($subject === '' || $body === '') {
            return $this->jsonResponse(['success' => false, 'message' => (string)__('主题和正文不能为空')], 422);
        }

        $accountId = (int)($payload['account_id'] ?? 0);
        $accounts = $this->activeAccounts();
        if ($accountId <= 0 || !isset($accounts[$accountId])) {
            $accountId = $this->defaultAccountId($accounts);
        }
        if ($accountId <= 0) {
            return $this->jsonResponse(['success' => false, 'message' => (string)__('无可用发件账户，请先在企业邮箱启用账户')], 422);
        }

        $result = ObjectManager::getInstance(MailComposerService::class)->send(
            $accountId,
            $to,
            $subject,
            $body,
            LeadConversation::MAIL_SOURCE,
            $leadId,
        );
        if (empty($result['success'])) {
            return $this->jsonResponse(
                ['success' => false, 'message' => (string)($result['message'] ?? __('邮件发送失败'))],
                (int)($result['code'] ?? 422),
            );
        }

        // send() 不返回 message_id（Mail 侧零改动），按 source/source_id 反查最新一封回填
        $mailMessageId = $this->latestMailMessageId($leadId);

        /** @var LeadConversation $conversation */
        $conversation = ObjectManager::getInstance(LeadConversation::class);
        $conversation->clearData()->reset()->setData([
            LeadConversation::schema_fields_LEAD_ID => $leadId,
            LeadConversation::schema_fields_RUN_ID => 0,
            LeadConversation::schema_fields_DIRECTION => LeadConversation::DIRECTION_OUT,
            LeadConversation::schema_fields_CHANNEL => LeadConversation::CHANNEL_EMAIL,
            LeadConversation::schema_fields_MAIL_MESSAGE_ID => $mailMessageId,
            LeadConversation::schema_fields_ROLE => LeadConversation::ROLE_HUMAN,
            LeadConversation::schema_fields_CONTENT => $body,
            LeadConversation::schema_fields_HUMAN_APPROVED => 1,
            LeadConversation::schema_fields_SENT_AT => time(),
        ])->save();

        $status = (string)$lead->getData(LeadModel::schema_fields_STATUS);
        $statusChanged = false;
        if ($status === LeadModel::STATUS_NEW) {
            $lead->setData(LeadModel::schema_fields_STATUS, LeadModel::STATUS_CONTACTED);
            $lead->save();
            $statusChanged = true;
        }

        return $this->jsonResponse([
            'success' => true,
            'message' => (string)($result['message'] ?? __('邮件已发送')),
            'conversation_id' => (int)$conversation->getId(),
            'status' => (string)$lead->getData(LeadModel::schema_fields_STATUS),
            'status_changed' => $statusChanged,
        ]);
    }

    private function loadLead(): ?LeadModel
    {
        $leadId = (int)$this->request->getGet('lead_id', $this->request->getParam('lead_id', 0));
        if ($leadId <= 0) {
            return null;
        }
        /** @var LeadModel $lead */
        $lead = ObjectManager::getInstance(LeadModel::class);
        $lead = $lead->clear()->load($leadId);

        return $lead && $lead->getId() ? $lead : null;
    }

    /**
     * @return array<int, array<string,mixed>> account_id => 行
     */
    private function activeAccounts(): array
    {
        /** @var MailSmtpAccountService $service */
        $service = ObjectManager::getInstance(MailSmtpAccountService::class);
        $items = [];
        foreach ($service->searchAccounts('', 100) as $config) {
            $accountId = (int)($config['account_id'] ?? 0);
            if ($accountId > 0) {
                $items[$accountId] = $config;
            }
        }

        return $items;
    }

    /**
     * @param array<int, array<string,mixed>> $accounts
     */
    private function defaultAccountId(array $accounts): int
    {
        foreach ($accounts as $accountId => $config) {
            if (strcasecmp((string)($config['email'] ?? ''), self::DEFAULT_ACCOUNT_EMAIL) === 0) {
                return (int)$accountId;
            }
        }
        if ($accounts !== []) {
            return (int)array_key_first($accounts);
        }

        return 0;
    }

    /**
     * 往来时间线：LeadConversation 按 sent_at/created_at desc。
     *
     * @return list<array<string,mixed>>
     */
    private function timeline(int $leadId): array
    {
        /** @var LeadConversation $conversationModel */
        $conversationModel = ObjectManager::getInstance(LeadConversation::class);
        $rows = $conversationModel->clear()
            ->where(LeadConversation::schema_fields_LEAD_ID, $leadId)
            ->order(LeadConversation::schema_fields_SENT_AT, 'DESC')
            ->order(LeadConversation::schema_fields_CREATED_AT, 'DESC')
            ->limit(50)
            ->select()
            ->fetchArray();

        $items = [];
        foreach ((is_array($rows) ? $rows : []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sentAt = (int)($row[LeadConversation::schema_fields_SENT_AT] ?? 0);
            $items[] = [
                'id' => (int)($row[LeadConversation::schema_fields_ID] ?? 0),
                'direction' => (string)($row[LeadConversation::schema_fields_DIRECTION] ?? ''),
                'role' => (string)($row[LeadConversation::schema_fields_ROLE] ?? ''),
                'content' => (string)($row[LeadConversation::schema_fields_CONTENT] ?? ''),
                'model_code' => (string)($row[LeadConversation::schema_fields_AI_MODEL_CODE] ?? ''),
                'sent_at' => $sentAt > 0 ? date('Y-m-d H:i', $sentAt) : '',
            ];
        }

        return $items;
    }

    private function latestMailMessageId(int $leadId): int
    {
        /** @var MailMessage $messageModel */
        $messageModel = ObjectManager::getInstance(MailMessage::class);
        $row = $messageModel->clear()
            ->where(MailMessage::schema_fields_SOURCE, LeadConversation::MAIL_SOURCE)
            ->where(MailMessage::schema_fields_SOURCE_ID, $leadId)
            ->order(MailMessage::schema_fields_ID, 'DESC')
            ->find()
            ->fetch();

        return $row && $row->getId() ? (int)$row->getId() : 0;
    }

    private function jsonResponse(array $data, int $code = 200): string
    {
        $response = $this->request->getResponse();
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');
        $response->setHeader('Cache-Control', 'no-store');
        $response->setCode($code);

        return json_encode($data, JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
