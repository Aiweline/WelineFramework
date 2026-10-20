<?php
declare(strict_types=1);

namespace Weline\Mail\Service\Mailbox;

use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Model\MailAccount;
use Weline\Mail\Model\MailDomain;
use Weline\Mail\Service\MailCustomerAccountService;

/**
 * Backend mailbox use-case facade. Controllers must not call StalwartEngineAdapter for mailbox I/O.
 */
final class MailboxOpsService
{
    /**
     * @return array{account:MailAccount,mailbox:string,client:MailboxClientInterface,is_fake:bool}
     */
    public function resolveAccount(int $accountId): array
    {
        if ($accountId <= 0) {
            throw new \InvalidArgumentException('请选择邮箱账号。');
        }
        /** @var MailAccount $account */
        $account = ObjectManager::getInstance(MailAccount::class)->clear()->load($accountId);
        if (!$account->getId()) {
            throw new \RuntimeException('邮箱账号不存在。');
        }
        $mailbox = strtolower(trim((string)$account->getData(MailAccount::schema_fields_EMAIL)));
        if ($mailbox === '' || !filter_var($mailbox, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('账号邮箱无效。');
        }
        $isFake = $this->isFakeAccount($account);
        $client = $isFake
            ? ObjectManager::getInstance(FakeMailboxClient::class)
            : ObjectManager::getInstance(StalwartMailboxClient::class);
        return [
            'account' => $account,
            'mailbox' => $mailbox,
            'client' => $client,
            'is_fake' => $isFake,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $accountId, string $folder, int $limit = 50, bool $unreadOnly = false): array
    {
        $ctx = $this->resolveAccount($accountId);
        $folder = MailboxFolder::assertValid($folder);
        return $ctx['client']->listMessages($ctx['mailbox'], $folder, $limit, $unreadOnly);
    }

    /**
     * 工作台左侧列表：未读在前。新信留在收件箱；已发送按线程标出回复数。
     *
     * @return list<array<string, mixed>>
     */
    public function listForWorkspace(int $accountId, string $folder, int $limit = 50, bool $unreadOnly = false): array
    {
        return $this->workspace($accountId, $folder, $limit, $unreadOnly)['items'];
    }

    /**
     * 当前邮箱的列表 + 文件夹新消息条数。
     * inbox = 收件箱未读；sent = 已发送里本身未读或有未读回复的封数。
     *
     * @return array{items:list<array<string, mixed>>,counts:array{inbox:int,sent:int}}
     */
    public function workspace(int $accountId, string $folder, int $limit = 50, bool $unreadOnly = false): array
    {
        $folder = MailboxFolder::assertValid($folder);
        $inboxUnread = $this->countUnreadInbox($accountId);
        $inboxRows = [];
        $sentRows = [];
        try {
            $inboxRows = $this->list($accountId, MailboxFolder::INBOX, $limit, false);
        } catch (\Throwable) {
            $inboxRows = [];
        }
        try {
            $sentRows = $this->list($accountId, MailboxFolder::SENT, $limit, false);
        } catch (\Throwable) {
            $sentRows = [];
        }
        $annotatedSent = self::annotateSentReplies($sentRows, $inboxRows);
        if ($folder === MailboxFolder::INBOX) {
            $items = $unreadOnly
                ? array_values(array_filter(
                    $inboxRows,
                    static fn(array $row): bool => !empty($row['unread'])
                ))
                : $inboxRows;
            $items = self::orderUnreadFirst($items);
        } elseif ($folder === MailboxFolder::SENT) {
            $items = self::orderUnreadFirst($annotatedSent);
        } else {
            $items = self::orderUnreadFirst($this->list($accountId, $folder, $limit, false));
        }

        return [
            'items' => $items,
            'counts' => [
                'inbox' => $inboxUnread,
                'sent' => self::countSignaledRows($annotatedSent),
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $unreadInbox
     * @param list<array<string, mixed>> $folderRows
     * @return list<array<string, mixed>>
     */
    public static function prependInboxUnread(array $unreadInbox, array $folderRows): array
    {
        $seen = [];
        $pinned = [];
        foreach ($unreadInbox as $row) {
            if (!is_array($row) || empty($row['unread'])) {
                continue;
            }
            $id = trim((string)($row['id'] ?? ''));
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $row['folder'] = MailboxFolder::INBOX;
            $pinned[] = $row;
            $seen[$id] = true;
        }
        $rest = [];
        foreach ($folderRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string)($row['id'] ?? ''));
            if ($id !== '' && isset($seen[$id])) {
                continue;
            }
            $rest[] = $row;
        }

        return array_merge($pinned, $rest);
    }

    /**
     * 已发送对照收件箱：同一线程或同一主题（去掉回复前缀）记为回复。
     * 有未读回复时，该已发送行也标成新消息。
     *
     * @param list<array<string, mixed>> $sentRows
     * @param list<array<string, mixed>> $inboxRows
     * @return list<array<string, mixed>>
     */
    public static function annotateSentReplies(array $sentRows, array $inboxRows): array
    {
        $byThread = [];
        $bySubject = [];
        foreach ($inboxRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string)($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $threadId = trim((string)($row['thread_id'] ?? ''));
            if ($threadId !== '') {
                $byThread[$threadId][$id] = $row;
            }
            $subjectKey = self::subjectThreadKey((string)($row['subject'] ?? ''));
            if ($subjectKey !== '') {
                $bySubject[$subjectKey][$id] = $row;
            }
        }
        $consumed = [];
        $prepared = [];
        foreach ($sentRows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $selfId = trim((string)($row['id'] ?? ''));
            $matches = [];
            $threadId = trim((string)($row['thread_id'] ?? ''));
            if ($threadId !== '' && isset($byThread[$threadId])) {
                foreach ($byThread[$threadId] as $id => $hit) {
                    if ($id === $selfId) {
                        continue;
                    }
                    $matches[$id] = $hit;
                    $consumed[$id] = true;
                }
            }
            $prepared[] = [
                'index' => $index,
                'row' => $row,
                'matches' => $matches,
            ];
        }
        foreach ($prepared as &$item) {
            if ($item['matches'] !== []) {
                continue;
            }
            $selfId = trim((string)($item['row']['id'] ?? ''));
            $subjectKey = self::subjectThreadKey((string)($item['row']['subject'] ?? ''));
            if ($subjectKey === '' || !isset($bySubject[$subjectKey])) {
                continue;
            }
            foreach ($bySubject[$subjectKey] as $id => $hit) {
                if ($id === $selfId || isset($consumed[$id])) {
                    continue;
                }
                $item['matches'][$id] = $hit;
                $consumed[$id] = true;
            }
        }
        unset($item);
        $out = [];
        foreach ($prepared as $item) {
            $row = $item['row'];
            $matches = $item['matches'];
            $newReplies = 0;
            foreach ($matches as $hit) {
                if (!empty($hit['unread'])) {
                    $newReplies++;
                }
            }
            $row['folder'] = MailboxFolder::SENT;
            $row['reply_count'] = count($matches);
            $row['new_reply_count'] = $newReplies;
            if ($newReplies > 0) {
                $row['unread'] = true;
                $row['has_new_reply'] = true;
            }
            $out[] = $row;
        }

        return $out;
    }

    public static function subjectThreadKey(string $subject): string
    {
        $subject = trim($subject);
        $previous = null;
        while ($previous !== $subject) {
            $previous = $subject;
            $subject = trim((string)preg_replace(
                '/^(re|fwd|fw|回复|答复|答覆|转发)\s*[:：]\s*/iu',
                '',
                $subject
            ));
        }
        $subject = mb_strtolower($subject);
        if ($subject === '' || $subject === '（无主题）' || $subject === '(no subject)') {
            return '';
        }

        return $subject;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public static function countSignaledRows(array $rows): int
    {
        $count = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!empty($row['unread']) || (int)($row['new_reply_count'] ?? 0) > 0) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function orderUnreadFirst(array $rows): array
    {
        $unread = [];
        $read = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!empty($row['unread'])) {
                $unread[] = $row;
            } else {
                $read[] = $row;
            }
        }

        return array_merge($unread, $read);
    }

    public function countUnreadInbox(int $accountId): int
    {
        $ctx = $this->resolveAccount($accountId);
        try {
            return max(0, $ctx['client']->countUnreadInbox($ctx['mailbox']));
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @param list<int> $accountIds
     * @return array<int, int> account_id => unread inbox count
     */
    public function mapInboxUnreadCounts(array $accountIds): array
    {
        $out = [];
        foreach ($accountIds as $accountId) {
            $accountId = (int)$accountId;
            if ($accountId <= 0) {
                continue;
            }
            $out[$accountId] = $this->countUnreadInbox($accountId);
        }
        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function search(int $accountId, string $folder, string $query, int $limit = 50): array
    {
        $ctx = $this->resolveAccount($accountId);
        $folder = MailboxFolder::assertValid($folder);
        return $ctx['client']->searchMessages($ctx['mailbox'], $folder, $query, $limit);
    }

    public function get(int $accountId, string $messageId): ?array
    {
        $ctx = $this->resolveAccount($accountId);
        return $ctx['client']->getMessage($ctx['mailbox'], $messageId);
    }

    /**
     * @param list<string> $ids
     * @return array{success:bool,message:string}
     */
    public function batch(int $accountId, string $action, array $ids, string $folder = MailboxFolder::INBOX): array
    {
        $ctx = $this->resolveAccount($accountId);
        $action = strtolower(trim($action));
        $ids = array_values(array_filter(array_map('strval', $ids), static fn(string $id): bool => $id !== ''));
        if ($ids === []) {
            return ['success' => false, 'message' => (string)__('请选择邮件')];
        }
        if (count($ids) > 50) {
            $ids = array_slice($ids, 0, 50);
        }
        $client = $ctx['client'];
        $mailbox = $ctx['mailbox'];
        try {
            match ($action) {
                'delete' => $client->moveToTrash($mailbox, $ids),
                'restore' => $client->restoreToInbox($mailbox, $ids),
                'destroy' => $client->destroy($mailbox, $ids),
                'seen' => $client->setSeen($mailbox, $ids, true),
                'unseen' => $client->setSeen($mailbox, $ids, false),
                default => throw new \InvalidArgumentException('不支持的批量操作。'),
            };
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
        $msg = match ($action) {
            'delete' => __('已移入废纸篓'),
            'restore' => __('已恢复到收件箱'),
            'destroy' => __('已永久删除'),
            'seen' => __('已标为已读'),
            'unseen' => __('已标为未读'),
            default => __('操作完成'),
        };
        return ['success' => true, 'message' => (string)$msg];
    }

    /**
     * @return array{success:bool,message:mixed,code?:int}
     */
    public function send(
        int $accountId,
        string|array $to,
        string $subject,
        string $body,
        array $attachments = [],
        string|array $cc = []
    ): array {
        try {
            $ctx = $this->resolveAccount($accountId);
            return $ctx['client']->send($ctx['mailbox'], $to, $subject, $body, $attachments, $cc);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'code' => 422];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'code' => 500];
        }
    }

    /**
     * @return array{blob_id:string,name:string,type:string,size:int}|array{success:false,message:string}
     */
    public function upload(int $accountId, string $name, string $type, string $binary): array
    {
        try {
            $ctx = $this->resolveAccount($accountId);
            return $ctx['client']->uploadBlob($ctx['mailbox'], $name, $type, $binary);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{name:string,type:string,size:int,blob_id:string,content:string}
     */
    public function downloadAttachment(int $accountId, string $blobId, string $name = ''): array
    {
        $ctx = $this->resolveAccount($accountId);
        return $ctx['client']->downloadBlob($ctx['mailbox'], $blobId, $name);
    }

    /**
     * @return array{
     *   mode:string,to:string,cc:string,subject:string,body:string,
     *   quote:string,quote_html:string,account_id:int
     * }
     */
    public function buildComposePrefill(int $accountId, string $messageId, string $mode): array
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['reply', 'reply_all', 'forward'], true)) {
            throw new \InvalidArgumentException('回复模式无效。');
        }
        $ctx = $this->resolveAccount($accountId);
        $message = $ctx['client']->getMessage($ctx['mailbox'], $messageId);
        if (!is_array($message) || (string)($message['id'] ?? '') === '') {
            throw new \RuntimeException('邮件不存在。');
        }
        $subject = (string)($message['subject'] ?? '');
        $from = $this->extractPrimaryEmail((string)($message['from'] ?? ''));
        $toList = $this->extractEmails((string)($message['to'] ?? ''));
        $ccList = $this->extractEmails((string)($message['cc'] ?? ''));
        $self = $ctx['mailbox'];
        $quote = MailBodyPresentation::gmailQuotePlain($message);
        $quoteHtml = MailBodyPresentation::gmailQuoteHtml($message);

        if ($mode === 'forward') {
            $fwdSubject = preg_match('/^\s*fwd:/i', $subject) ? $subject : 'Fwd: ' . $subject;
            return [
                'mode' => $mode,
                'to' => '',
                'cc' => '',
                'subject' => $fwdSubject,
                // Forward: leave editor empty; quoted history shown below (Gmail).
                'body' => '',
                'quote' => $quote,
                'quote_html' => $quoteHtml,
                'account_id' => $accountId,
            ];
        }

        $reSubject = preg_match('/^\s*re:/i', $subject) ? $subject : 'Re: ' . $subject;
        // Sent/drafts: From is ourselves — reply to the original To (Gmail behavior).
        $to = ($from !== '' && $from !== $self) ? $from : ($toList[0] ?? '');
        $cc = '';
        if ($mode === 'reply_all') {
            $others = [];
            $replyTarget = $to;
            foreach (array_merge($toList, $ccList, $from !== '' ? [$from] : []) as $email) {
                if ($email === $self || $email === $replyTarget) {
                    continue;
                }
                $others[$email] = $email;
            }
            $cc = implode(', ', array_values($others));
        }

        return [
            'mode' => $mode,
            'to' => $to,
            'cc' => $cc,
            'subject' => $reSubject,
            // Reply: empty editor; full thread quote below (Gmail).
            'body' => '',
            'quote' => $quote,
            'quote_html' => $quoteHtml,
            'account_id' => $accountId,
        ];
    }

    private function isFakeAccount(MailAccount $account): bool
    {
        $domainId = (int)$account->getData(MailAccount::schema_fields_DOMAIN_ID);
        if ($domainId <= 0) {
            return false;
        }
        /** @var MailDomain $domain */
        $domain = ObjectManager::getInstance(MailDomain::class)->clear()->load($domainId);
        if (!$domain->getId()) {
            return false;
        }
        /** @var MailCustomerAccountService $svc */
        $svc = ObjectManager::getInstance(MailCustomerAccountService::class);
        return $svc->isFakeDomain($domain);
    }

    /**
     * @param array<string, mixed> $message
     * @deprecated Prefer MailBodyPresentation::gmailQuotePlain; kept for callers/tests.
     */
    private function quoteBody(array $message): string
    {
        return MailBodyPresentation::gmailQuotePlain($message);
    }

    private function extractPrimaryEmail(string $raw): string
    {
        $emails = $this->extractEmails($raw);
        return $emails[0] ?? '';
    }

    /**
     * @return list<string>
     */
    private function extractEmails(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $raw, $m);
        $out = [];
        foreach ($m[0] ?? [] as $email) {
            $email = strtolower($email);
            $out[$email] = $email;
        }
        return array_values($out);
    }
}
