<?php
declare(strict_types=1);

namespace Weline\Mail\Service\Mailbox;

final class StalwartMailboxClient implements MailboxClientInterface
{
    private StalwartJmapSession $session;

    public function __construct(?StalwartJmapSession $session = null)
    {
        $this->session = $session ?? new StalwartJmapSession();
    }

    public function listMessages(string $mailbox, string $folder, int $limit = 50, bool $unreadOnly = false): array
    {
        return $this->queryMessages($mailbox, $folder, null, $limit, $unreadOnly);
    }

    public function countUnreadInbox(string $mailbox): int
    {
        $context = $this->session->openMailboxSession($mailbox);
        $mailboxId = $this->session->findMailboxIdByRole($context, 'inbox');
        $result = $this->session->jmapCall($context, 'Mailbox/get', [
            'accountId' => $context['account_id'],
            'ids' => [$mailboxId],
            'properties' => ['unreadEmails', 'totalEmails', 'role'],
        ]);
        $row = $result['list'][0] ?? null;
        if (!is_array($row)) {
            return 0;
        }
        return max(0, (int)($row['unreadEmails'] ?? 0));
    }

    public function searchMessages(string $mailbox, string $folder, string $query, int $limit = 50): array
    {
        $query = trim($query);
        if ($query === '') {
            return $this->listMessages($mailbox, $folder, $limit);
        }
        return $this->queryMessages($mailbox, $folder, $query, $limit, false);
    }

    public function getMessage(string $mailbox, string $messageId): ?array
    {
        $messageId = trim($messageId);
        if ($messageId === '' || strlen($messageId) > 255) {
            throw new \InvalidArgumentException('邮件标识无效。');
        }
        $context = $this->session->openMailboxSession($mailbox);
        $result = $this->session->jmapCall($context, 'Email/get', [
            'accountId' => $context['account_id'],
            'ids' => [$messageId],
            'properties' => [
                'id', 'threadId', 'from', 'to', 'cc', 'bcc', 'subject',
                'receivedAt', 'sentAt', 'preview', 'hasAttachment', 'size', 'keywords',
                'bodyValues', 'textBody', 'htmlBody', 'bodyStructure', 'attachments',
            ],
            'fetchTextBodyValues' => true,
            'fetchHTMLBodyValues' => true,
            'maxBodyValueBytes' => 131072,
        ]);
        $message = $result['list'][0] ?? null;
        return is_array($message) ? $this->session->normalizeMessage($message, true, true) : null;
    }

    public function setSeen(string $mailbox, array $messageIds, bool $seen): void
    {
        $ids = $this->normalizeIds($messageIds);
        if ($ids === []) {
            return;
        }
        $context = $this->session->openMailboxSession($mailbox);
        $update = [];
        foreach ($ids as $id) {
            $update[$id] = [
                'keywords/$seen' => $seen,
            ];
        }
        $this->session->jmapCall($context, 'Email/set', [
            'accountId' => $context['account_id'],
            'update' => $update,
        ]);
    }

    public function moveToTrash(string $mailbox, array $messageIds): void
    {
        $this->moveToRole($mailbox, $messageIds, 'trash');
    }

    public function restoreToInbox(string $mailbox, array $messageIds): void
    {
        $this->moveToRole($mailbox, $messageIds, 'inbox');
    }

    public function destroy(string $mailbox, array $messageIds): void
    {
        $ids = $this->normalizeIds($messageIds);
        if ($ids === []) {
            return;
        }
        $context = $this->session->openMailboxSession($mailbox);
        $this->session->jmapCall($context, 'Email/set', [
            'accountId' => $context['account_id'],
            'destroy' => $ids,
        ]);
    }

    public function downloadBlob(string $mailbox, string $blobId, string $name = ''): array
    {
        $context = $this->session->openMailboxSession($mailbox);
        $binary = $this->session->downloadBlobBinary($context, $blobId);
        return [
            'name' => $name !== '' ? $name : 'attachment',
            'type' => 'application/octet-stream',
            'size' => strlen($binary),
            'blob_id' => $blobId,
            'content' => $binary,
        ];
    }

    public function uploadBlob(string $mailbox, string $name, string $type, string $binary): array
    {
        $context = $this->session->openMailboxSession($mailbox);
        $uploaded = $this->session->uploadBlobBinary($context, $type, $binary);
        return [
            'blob_id' => $uploaded['blobId'],
            'name' => $name !== '' ? $name : 'attachment',
            'type' => $uploaded['type'],
            'size' => $uploaded['size'],
        ];
    }

    public function send(
        string $mailbox,
        string|array $to,
        string $subject,
        string $body,
        array $attachments = [],
        string|array $cc = []
    ): array {
        $mailbox = strtolower(trim($mailbox));
        if (!filter_var($mailbox, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('发件邮箱格式无效。');
        }
        $recipients = $this->session->normalizeEmailList($to);
        $ccList = $cc === [] || $cc === '' ? [] : $this->session->normalizeEmailList($cc);
        $subject = trim($subject);
        $body = trim($body);
        if ($recipients === []) {
            throw new \InvalidArgumentException('收件邮箱格式不正确。');
        }
        if ($subject === '' || $body === '') {
            throw new \InvalidArgumentException('主题和正文不能为空。');
        }

        $context = $this->session->openMailboxSession($mailbox);
        $draftsId = $this->session->findMailboxIdByRole($context, 'drafts');
        $sentId = $this->session->findMailboxIdByRole($context, 'sent');
        $capabilities = [
            StalwartJmapSession::JMAP_CORE_CAPABILITY,
            StalwartJmapSession::JMAP_MAIL_CAPABILITY,
            StalwartJmapSession::JMAP_SUBMISSION_CAPABILITY,
        ];
        $identities = $this->session->jmapCallWithCapabilities(
            $context,
            'Identity/get',
            ['accountId' => $context['account_id']],
            $capabilities
        );
        $identityId = '';
        foreach ((array)($identities['list'] ?? []) as $identity) {
            if (!is_array($identity)) {
                continue;
            }
            if ($identityId === '') {
                $identityId = (string)($identity['id'] ?? '');
            }
            if (strtolower((string)($identity['email'] ?? '')) === $mailbox) {
                $identityId = (string)($identity['id'] ?? '');
                break;
            }
        }
        if ($identityId === '') {
            throw new \RuntimeException('目标邮箱没有可用的发件身份。');
        }

        $bodyValues = ['body' => ['value' => $body]];
        $bodyStructure = ['type' => 'text/plain', 'partId' => 'body'];
        if ($attachments !== []) {
            $subParts = [
                ['type' => 'text/plain', 'partId' => 'body'],
            ];
            foreach ($attachments as $i => $attachment) {
                if (!is_array($attachment)) {
                    continue;
                }
                $blobId = (string)($attachment['blob_id'] ?? '');
                if ($blobId === '') {
                    continue;
                }
                $partId = 'att' . $i;
                $subParts[] = [
                    'type' => (string)($attachment['type'] ?? 'application/octet-stream'),
                    'name' => (string)($attachment['name'] ?? 'attachment'),
                    'blobId' => $blobId,
                    'disposition' => 'attachment',
                    'size' => (int)($attachment['size'] ?? 0),
                ];
            }
            $bodyStructure = [
                'type' => 'multipart/mixed',
                'subParts' => $subParts,
            ];
        }

        $draft = [
            'mailboxIds' => (object)[$draftsId => true],
            'keywords' => (object)['$draft' => true],
            'from' => [['email' => $mailbox]],
            'to' => array_map(static fn(string $email): array => ['email' => $email], $recipients),
            'subject' => $subject,
            'bodyStructure' => $bodyStructure,
            'bodyValues' => $bodyValues,
        ];
        if ($ccList !== []) {
            $draft['cc'] = array_map(static fn(string $email): array => ['email' => $email], $ccList);
        }

        $create = $this->session->jmapCallWithCapabilities(
            $context,
            'Email/set',
            [
                'accountId' => $context['account_id'],
                'create' => ['draft' => $draft],
            ],
            $capabilities
        );
        $emailId = (string)($create['created']['draft']['id'] ?? '');
        if ($emailId === '') {
            $type = (string)($create['notCreated']['draft']['type'] ?? '');
            throw new \RuntimeException('邮件草稿创建失败' . ($type !== '' ? '：' . $type : '。'));
        }

        $submission = $this->session->jmapCallWithCapabilities(
            $context,
            'EmailSubmission/set',
            [
                'accountId' => $context['account_id'],
                'create' => [
                    'submission' => [
                        'identityId' => $identityId,
                        'emailId' => $emailId,
                    ],
                ],
            ],
            $capabilities
        );
        if (empty($submission['created']['submission']['id'])) {
            $type = (string)($submission['notCreated']['submission']['type'] ?? '');
            throw new \RuntimeException('邮件提交失败' . ($type !== '' ? '：' . $type : '。'));
        }

        $this->session->jmapCallWithCapabilities(
            $context,
            'Email/set',
            [
                'accountId' => $context['account_id'],
                'update' => [
                    $emailId => [
                        'mailboxIds' => (object)[$sentId => true],
                        'keywords' => (object)['$seen' => true],
                    ],
                ],
            ],
            $capabilities
        );

        return ['success' => true, 'message' => __('邮件已提交发送')];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function queryMessages(string $mailbox, string $folder, ?string $text, int $limit, bool $unreadOnly = false): array
    {
        $folder = MailboxFolder::assertValid($folder);
        $limit = max(1, min(100, $limit));
        $context = $this->session->openMailboxSession($mailbox);
        $mailboxId = $this->session->findMailboxIdByRole($context, MailboxFolder::jmapRole($folder));
        $conditions = [['inMailbox' => $mailboxId]];
        if ($unreadOnly) {
            // JMAP：未读 = 没有 $seen 关键字
            $conditions[] = [
                'operator' => 'NOT',
                'conditions' => [
                    ['hasKeyword' => '$seen'],
                ],
            ];
        }
        if ($text !== null && $text !== '') {
            $conditions[] = ['text' => $text];
        }
        $filter = count($conditions) === 1
            ? $conditions[0]
            : [
                'operator' => 'AND',
                'conditions' => $conditions,
            ];
        $sortProp = $folder === MailboxFolder::SENT ? 'sentAt' : 'receivedAt';
        $query = $this->session->jmapCall($context, 'Email/query', [
            'accountId' => $context['account_id'],
            'filter' => $filter,
            'sort' => [['property' => $sortProp, 'isAscending' => false]],
            'limit' => $limit,
        ]);
        $ids = array_values(array_filter(
            (array)($query['ids'] ?? []),
            static fn($id): bool => is_string($id) && $id !== ''
        ));
        if ($ids === []) {
            return [];
        }
        $result = $this->session->jmapCall($context, 'Email/get', [
            'accountId' => $context['account_id'],
            'ids' => $ids,
            'properties' => [
                'id', 'threadId', 'from', 'to', 'cc', 'bcc', 'subject',
                'receivedAt', 'sentAt', 'preview', 'hasAttachment', 'size', 'keywords',
            ],
        ]);
        $messages = [];
        foreach ((array)($result['list'] ?? []) as $message) {
            if (is_array($message)) {
                $row = $this->session->normalizeMessage($message, false);
                $row['folder'] = $folder;
                $messages[] = $row;
            }
        }
        return $messages;
    }

    /**
     * @param list<string> $messageIds
     */
    private function moveToRole(string $mailbox, array $messageIds, string $role): void
    {
        $ids = $this->normalizeIds($messageIds);
        if ($ids === []) {
            return;
        }
        $context = $this->session->openMailboxSession($mailbox);
        $targetId = $this->session->findMailboxIdByRole($context, $role);
        $update = [];
        foreach ($ids as $id) {
            $update[$id] = [
                'mailboxIds' => (object)[$targetId => true],
            ];
        }
        $this->session->jmapCall($context, 'Email/set', [
            'accountId' => $context['account_id'],
            'update' => $update,
        ]);
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
            if ($id === '' || strlen($id) > 255) {
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
