<?php

declare(strict_types=1);

namespace Weline\Mail\Service;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Model\MailAccount;
use Weline\Mail\Model\MailMessage;

/**
 * Business-thread composer: list by source + send with source persistence and event.
 */
final class MailComposerService
{
    /**
     * @return list<array<string,mixed>>
     */
    public function listThreadBySource(string $source, int $sourceId, int $limit = 50): array
    {
        $source = $this->normalizeSource($source);
        $sourceId = max(0, $sourceId);
        if ($source === '' || $sourceId <= 0) {
            return [];
        }

        $limit = max(1, min(100, $limit));
        /** @var MailMessage $model */
        $model = ObjectManager::getInstance(MailMessage::class);
        $items = $model->clear()
            ->where(MailMessage::schema_fields_SOURCE, $source)
            ->where(MailMessage::schema_fields_SOURCE_ID, $sourceId)
            ->order(MailMessage::schema_fields_ID, 'ASC')
            ->limit($limit)
            ->select()
            ->fetch()
            ->getItems();

        $out = [];
        foreach ($items as $item) {
            $out[] = [
                'message_id' => (int)$item->getId(),
                'account_id' => (int)$item->getData(MailMessage::schema_fields_ACCOUNT_ID),
                'folder' => (string)$item->getData(MailMessage::schema_fields_FOLDER),
                'from' => (string)$item->getData(MailMessage::schema_fields_FROM_EMAIL),
                'to' => (string)$item->getData(MailMessage::schema_fields_TO_EMAIL),
                'subject' => (string)$item->getData(MailMessage::schema_fields_SUBJECT),
                'body' => (string)$item->getData(MailMessage::schema_fields_BODY),
                'created_at' => (string)$item->getData(MailMessage::schema_fields_CREATED_AT),
                'source' => (string)$item->getData(MailMessage::schema_fields_SOURCE),
                'source_id' => (int)$item->getData(MailMessage::schema_fields_SOURCE_ID),
            ];
        }

        return $out;
    }

    /**
     * @return array{success:bool,message:mixed,code?:int}
     */
    public function send(
        int $accountId,
        string $to,
        string $subject,
        string $body,
        string $source = '',
        int $sourceId = 0
    ): array {
        $accountId = max(0, $accountId);
        $to = strtolower(trim($to));
        $subject = trim($subject);
        $body = trim($body);
        $source = $this->normalizeSource($source);
        $sourceId = max(0, $sourceId);

        if ($accountId <= 0) {
            return ['success' => false, 'message' => __('请选择已启用的本机企业邮箱账号'), 'code' => 422];
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => __('收件邮箱格式不正确'), 'code' => 422];
        }
        if ($subject === '' || $body === '') {
            return ['success' => false, 'message' => __('主题和正文不能为空'), 'code' => 422];
        }

        $smtp = ObjectManager::getInstance(MailSmtpAccountService::class);
        $config = $smtp->getAccountConfig($accountId);
        if ($config === null) {
            return ['success' => false, 'message' => __('请选择已启用的本机企业邮箱账号'), 'code' => 422];
        }

        $result = $smtp->sendViaAuthorizedAccount($accountId, $to, $subject, $body, $source, $sourceId);
        if (empty($result['success'])) {
            return [
                'success' => false,
                'message' => $result['message'] ?? __('邮件发送失败'),
                'code' => (int)($result['code'] ?? 422),
            ];
        }

        // Non-fake path does not persist MailMessage; keep a local thread row for source threads.
        if (empty($config['is_fake']) && $source !== '' && $sourceId > 0) {
            $this->persistThreadRow(
                $accountId,
                (string)$config['email'],
                $to,
                $subject,
                $body,
                $source,
                $sourceId
            );
        }

        $sentEvent = [
            'account_id' => $accountId,
            'from' => (string)($config['email'] ?? ''),
            'to' => $to,
            'subject' => $subject,
            'source' => $source,
            'source_id' => $sourceId,
        ];
        ObjectManager::getInstance(EventsManager::class)
            ->dispatch('Weline_Mail::mail_message_sent', $sentEvent);

        return [
            'success' => true,
            'message' => $result['message'] ?? __('邮件已发送'),
            'code' => 200,
        ];
    }

    private function persistThreadRow(
        int $accountId,
        string $from,
        string $to,
        string $subject,
        string $body,
        string $source,
        int $sourceId
    ): void {
        $now = date('Y-m-d H:i:s');
        /** @var MailMessage $message */
        $message = ObjectManager::getInstance(MailMessage::class);
        $message->clear()
            ->setData(MailMessage::schema_fields_ACCOUNT_ID, $accountId)
            ->setData(MailMessage::schema_fields_FOLDER, 'sent')
            ->setData(MailMessage::schema_fields_FROM_EMAIL, $from)
            ->setData(MailMessage::schema_fields_TO_EMAIL, $to)
            ->setData(MailMessage::schema_fields_SUBJECT, mb_substr($subject, 0, 180))
            ->setData(MailMessage::schema_fields_BODY, $body)
            ->setData(MailMessage::schema_fields_IS_READ, 1)
            ->setData(MailMessage::schema_fields_DELIVERY_STATUS, 'sent')
            ->setData(MailMessage::schema_fields_SOURCE, $source)
            ->setData(MailMessage::schema_fields_SOURCE_ID, $sourceId)
            ->setData(MailMessage::schema_fields_CREATED_AT, $now)
            ->save();
    }

    private function normalizeSource(string $source): string
    {
        return preg_replace('/[^a-z0-9_]/', '', strtolower(trim($source))) ?: '';
    }
}
