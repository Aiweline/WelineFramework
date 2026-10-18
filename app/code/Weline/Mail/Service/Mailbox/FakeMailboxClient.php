<?php
declare(strict_types=1);

namespace Weline\Mail\Service\Mailbox;

use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Model\MailAccount;
use Weline\Mail\Model\MailMessage;
use Weline\Mail\Service\MailFakeMailboxService;

/**
 * Fake-domain mailbox client backed by MailMessage ORM.
 * Attachment upload is intentionally unsupported.
 */
final class FakeMailboxClient implements MailboxClientInterface
{
    public function listMessages(string $mailbox, string $folder, int $limit = 50): array
    {
        $folder = MailboxFolder::assertValid($folder);
        $account = $this->loadAccountByMailbox($mailbox);
        $limit = max(1, min(100, $limit));
        /** @var MailMessage $model */
        $model = ObjectManager::getInstance(MailMessage::class);
        $items = $model->clear()
            ->where(MailMessage::schema_fields_ACCOUNT_ID, (int)$account->getId())
            ->where(MailMessage::schema_fields_FOLDER, $folder)
            ->order(MailMessage::schema_fields_ID, 'DESC')
            ->limit($limit)
            ->select()
            ->fetch()
            ->getItems();
        $out = [];
        foreach ($items as $item) {
            $out[] = $this->normalizeRow($item, false);
        }
        return $out;
    }

    public function countUnreadInbox(string $mailbox): int
    {
        $account = $this->loadAccountByMailbox($mailbox);
        /** @var MailMessage $model */
        $model = ObjectManager::getInstance(MailMessage::class);
        $items = $model->clear()
            ->where(MailMessage::schema_fields_ACCOUNT_ID, (int)$account->getId())
            ->where(MailMessage::schema_fields_FOLDER, MailboxFolder::INBOX)
            ->where(MailMessage::schema_fields_IS_READ, 0)
            ->select()
            ->fetch()
            ->getItems();

        return is_array($items) ? count($items) : 0;
    }

    public function searchMessages(string $mailbox, string $folder, string $query, int $limit = 50): array
    {
        $query = trim($query);
        $rows = $this->listMessages($mailbox, $folder, $limit);
        if ($query === '') {
            return $rows;
        }
        $needle = mb_strtolower($query);
        return array_values(array_filter(
            $rows,
            static function (array $row) use ($needle): bool {
                $hay = mb_strtolower(
                    ($row['subject'] ?? '') . ' ' . ($row['from'] ?? '') . ' '
                    . ($row['to'] ?? '') . ' ' . ($row['preview'] ?? '')
                );
                return str_contains($hay, $needle);
            }
        ));
    }

    public function getMessage(string $mailbox, string $messageId): ?array
    {
        $account = $this->loadAccountByMailbox($mailbox);
        /** @var MailMessage $model */
        $model = ObjectManager::getInstance(MailMessage::class)->clear()->load($messageId);
        if (!$model->getId()
            || (int)$model->getData(MailMessage::schema_fields_ACCOUNT_ID) !== (int)$account->getId()
        ) {
            return null;
        }
        return $this->normalizeRow($model, true);
    }

    public function setSeen(string $mailbox, array $messageIds, bool $seen): void
    {
        $account = $this->loadAccountByMailbox($mailbox);
        foreach ($this->normalizeIds($messageIds) as $id) {
            /** @var MailMessage $model */
            $model = ObjectManager::getInstance(MailMessage::class)->clear()->load($id);
            if (!$model->getId()
                || (int)$model->getData(MailMessage::schema_fields_ACCOUNT_ID) !== (int)$account->getId()
            ) {
                continue;
            }
            $model->setData(MailMessage::schema_fields_IS_READ, $seen ? 1 : 0)->save();
        }
    }

    public function moveToTrash(string $mailbox, array $messageIds): void
    {
        $this->moveFolder($mailbox, $messageIds, MailboxFolder::TRASH);
    }

    public function restoreToInbox(string $mailbox, array $messageIds): void
    {
        $this->moveFolder($mailbox, $messageIds, MailboxFolder::INBOX);
    }

    public function destroy(string $mailbox, array $messageIds): void
    {
        $account = $this->loadAccountByMailbox($mailbox);
        foreach ($this->normalizeIds($messageIds) as $id) {
            /** @var MailMessage $model */
            $model = ObjectManager::getInstance(MailMessage::class)->clear()->load($id);
            if (!$model->getId()
                || (int)$model->getData(MailMessage::schema_fields_ACCOUNT_ID) !== (int)$account->getId()
            ) {
                continue;
            }
            $model->delete();
        }
    }

    public function downloadBlob(string $mailbox, string $blobId, string $name = ''): array
    {
        throw new \RuntimeException('测试邮箱不支持附件下载。');
    }

    public function uploadBlob(string $mailbox, string $name, string $type, string $binary): array
    {
        throw new \RuntimeException('测试邮箱不支持附件上传。');
    }

    public function send(
        string $mailbox,
        string|array $to,
        string $subject,
        string $body,
        array $attachments = [],
        string|array $cc = []
    ): array {
        if ($attachments !== []) {
            throw new \RuntimeException('测试邮箱不支持附件发送。');
        }
        $account = $this->loadAccountByMailbox($mailbox);
        /** @var MailFakeMailboxService $fake */
        $fake = ObjectManager::getInstance(MailFakeMailboxService::class);
        return $fake->sendFromAccount((int)$account->getId(), $to, $subject, $body);
    }

    private function moveFolder(string $mailbox, array $messageIds, string $folder): void
    {
        $folder = MailboxFolder::assertValid($folder);
        $account = $this->loadAccountByMailbox($mailbox);
        foreach ($this->normalizeIds($messageIds) as $id) {
            /** @var MailMessage $model */
            $model = ObjectManager::getInstance(MailMessage::class)->clear()->load($id);
            if (!$model->getId()
                || (int)$model->getData(MailMessage::schema_fields_ACCOUNT_ID) !== (int)$account->getId()
            ) {
                continue;
            }
            $model->setData(MailMessage::schema_fields_FOLDER, $folder)->save();
        }
    }

    private function loadAccountByMailbox(string $mailbox): MailAccount
    {
        $mailbox = strtolower(trim($mailbox));
        /** @var MailAccount $account */
        $account = ObjectManager::getInstance(MailAccount::class)
            ->clear()
            ->where(MailAccount::schema_fields_EMAIL, $mailbox)
            ->find()
            ->fetch();
        if (!$account->getId()) {
            throw new \RuntimeException('测试邮箱账号不存在。');
        }
        return $account;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeRow(MailMessage $item, bool $includeBody): array
    {
        $body = (string)$item->getData(MailMessage::schema_fields_BODY);
        $preview = strlen($body) > 120 ? substr($body, 0, 117) . '...' : $body;
        $presentation = MailBodyPresentation::fromStoredBody($body, $includeBody);
        return [
            'id' => (string)$item->getId(),
            'thread_id' => '',
            'folder' => (string)$item->getData(MailMessage::schema_fields_FOLDER),
            'from' => (string)$item->getData(MailMessage::schema_fields_FROM_EMAIL),
            'to' => (string)$item->getData(MailMessage::schema_fields_TO_EMAIL),
            'cc' => '',
            'bcc' => '',
            'subject' => (string)$item->getData(MailMessage::schema_fields_SUBJECT),
            'received_at' => (string)$item->getData(MailMessage::schema_fields_CREATED_AT),
            'preview' => $preview,
            'body' => $presentation['body'],
            'html_body' => $presentation['html_body'],
            'body_is_html' => $presentation['body_is_html'],
            'has_attachment' => false,
            'attachments' => [],
            'size' => strlen($body),
            'unread' => !(bool)$item->getData(MailMessage::schema_fields_IS_READ),
        ];
    }

    /**
     * @param list<string> $messageIds
     * @return list<string>
     */
    private function normalizeIds(array $messageIds): array
    {
        $out = [];
        foreach ($messageIds as $id) {
            $id = trim((string)$id);
            if ($id === '') {
                continue;
            }
            $out[$id] = $id;
            if (count($out) >= 50) {
                break;
            }
        }
        return array_values($out);
    }
}
