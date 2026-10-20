<?php

declare(strict_types=1);

namespace Weline\Mail\Service\Mailbox;

use Weline\Admin\Api\BackendMenuSignalInboxInterface;
use Weline\Admin\Service\BackendMenuSignalAggregator;
use Weline\Admin\Service\BackendMenuSignalInbox;
use Weline\Framework\Http\UrlInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Mail\Model\MailAccount;

/**
 * 把收件箱未读信写进后台菜单收件箱，并给出打开该信的深链。
 * 已读信不发布。邮局引擎暂时连不上时，沿用上一轮已写入的未读行。
 */
final class MailMenuSignalPublisher
{
    public const MENU_SOURCE_ID = 'Weline_Mail::mail';

    private ?string $memoRequestId = null;

    private int $memoUserId = 0;

    /** @var list<array{id: string, title: string, href: string, count: int, unread: true}> */
    private array $memoItems = [];

    public static function itemId(int $accountId, string $messageId): string
    {
        $messageId = trim($messageId);
        $raw = $accountId . ':' . $messageId;
        if (strlen($raw) <= 64) {
            return $raw;
        }

        return $accountId . '~' . substr(hash('sha256', $messageId), 0, 40);
    }

    /**
     * @return list<array{id: string, title: string, href: string, count: int, unread: true}>
     */
    public function unreadItems(int $backendUserId): array
    {
        $backendUserId = max(0, $backendUserId);
        if ($backendUserId <= 0 || !class_exists(BackendMenuSignalInbox::class)) {
            return [];
        }

        $requestId = RequestContext::getId();
        if (
            is_string($requestId)
            && $requestId !== ''
            && $this->memoRequestId === $requestId
            && $this->memoUserId === $backendUserId
        ) {
            return $this->memoItems;
        }

        $items = $this->buildUnreadItems($backendUserId);
        if (is_string($requestId) && $requestId !== '') {
            $this->memoRequestId = $requestId;
            $this->memoUserId = $backendUserId;
            $this->memoItems = $items;
        }

        return $items;
    }

    public function markRead(int $accountId, string $messageId, int $backendUserId): void
    {
        $this->flag($accountId, $messageId, $backendUserId, true);
    }

    public function markUnread(int $accountId, string $messageId, int $backendUserId): void
    {
        $this->flag($accountId, $messageId, $backendUserId, false);
    }

    /**
     * @return list<array{id: string, title: string, href: string, count: int, unread: true}>
     */
    private function buildUnreadItems(int $backendUserId): array
    {
        $liveIds = null;
        try {
            $liveIds = $this->publishLiveUnread();
        } catch (\Throwable $e) {
            w_log_error('Mail menu signal sync: ' . $e->getMessage(), [], 'mail_menu_signal');
        }

        try {
            $rows = $this->inbox()->unreadItems(
                self::MENU_SOURCE_ID,
                $backendUserId,
                BackendMenuSignalAggregator::MAX_ITEMS_PER_CODE
            );
        } catch (\Throwable) {
            return [];
        }

        $items = [];
        foreach ($rows as $row) {
            $itemId = trim((string)($row['id'] ?? ''));
            if ($itemId === '') {
                continue;
            }
            if (is_array($liveIds) && !isset($liveIds[$itemId])) {
                continue;
            }
            $href = $this->freshHref($itemId);
            if ($href === '') {
                $href = trim((string)($row['href'] ?? ''));
            }
            if ($href === '') {
                continue;
            }
            $title = trim((string)($row['title'] ?? ''));
            $items[] = [
                'id' => $itemId,
                'title' => $title !== '' ? $title : (string)__('（无主题）'),
                'href' => $href,
                'count' => 1,
                'unread' => true,
            ];
        }

        return $items;
    }

    /**
     * @return array<string, true>
     */
    private function publishLiveUnread(): array
    {
        $inbox = $this->inbox();
        $ops = ObjectManager::getInstance(MailboxOpsService::class);
        /** @var MailAccount $accountModel */
        $accountModel = ObjectManager::getInstance(MailAccount::class);
        $accounts = $accountModel->clear()
            ->order(MailAccount::schema_fields_ID, 'DESC')
            ->select()
            ->fetch()
            ->getItems();

        $live = [];
        $remaining = BackendMenuSignalAggregator::MAX_ITEMS_PER_CODE;
        foreach ($accounts as $account) {
            if ($remaining <= 0) {
                break;
            }
            if (!$account instanceof MailAccount) {
                continue;
            }
            $accountId = (int)$account->getId();
            $email = strtolower(trim((string)$account->getData(MailAccount::schema_fields_EMAIL)));
            if ($accountId <= 0 || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            try {
                if ($ops->countUnreadInbox($accountId) <= 0) {
                    continue;
                }
                $rows = $ops->list($accountId, MailboxFolder::INBOX, $remaining, true);
            } catch (\Throwable) {
                continue;
            }
            foreach ($rows as $row) {
                if ($remaining <= 0 || !is_array($row)) {
                    break;
                }
                if (empty($row['unread'])) {
                    continue;
                }
                $messageId = trim((string)($row['id'] ?? ''));
                if ($messageId === '') {
                    continue;
                }
                $itemId = self::itemId($accountId, $messageId);
                $href = $this->hrefFor($accountId, $messageId);
                if ($href === '') {
                    continue;
                }
                $subject = trim((string)($row['subject'] ?? ''));
                $title = $subject !== '' ? $subject : (string)__('（无主题）');
                $title .= ' · ' . $email;
                if (mb_strlen($title) > 250) {
                    $title = mb_substr($title, 0, 250);
                }
                try {
                    $inbox->publish(self::MENU_SOURCE_ID, $itemId, $title, $href, true);
                } catch (\Throwable) {
                    continue;
                }
                $live[$itemId] = true;
                $remaining--;
            }
        }

        return $live;
    }

    private function flag(int $accountId, string $messageId, int $backendUserId, bool $read): void
    {
        $messageId = trim($messageId);
        $backendUserId = max(0, $backendUserId);
        if ($accountId <= 0 || $messageId === '' || $backendUserId <= 0 || !class_exists(BackendMenuSignalInbox::class)) {
            return;
        }
        $this->memoRequestId = null;
        try {
            $inbox = $this->inbox();
            $itemId = self::itemId($accountId, $messageId);
            if ($read) {
                $inbox->markRead(self::MENU_SOURCE_ID, $itemId, $backendUserId);
                return;
            }
            $inbox->markUnread(self::MENU_SOURCE_ID, $itemId, $backendUserId);
        } catch (\Throwable $e) {
            w_log_error('Mail menu signal flag: ' . $e->getMessage(), [], 'mail_menu_signal');
        }
    }

    private function freshHref(string $itemId): string
    {
        $pos = strpos($itemId, ':');
        if ($pos === false || $pos <= 0 || str_contains($itemId, '~')) {
            return '';
        }
        $accountId = (int)substr($itemId, 0, $pos);
        $messageId = substr($itemId, $pos + 1);
        if ($accountId <= 0 || $messageId === '') {
            return '';
        }

        return $this->hrefFor($accountId, $messageId);
    }

    private function hrefFor(int $accountId, string $messageId): string
    {
        try {
            /** @var UrlInterface $url */
            $url = ObjectManager::getInstance(UrlInterface::class);

            return trim((string)$url->getBackendUrl('weline_mail/backend', [
                'view' => 'mailbox',
                'account' => $accountId,
                'folder' => MailboxFolder::INBOX,
                'message' => $messageId,
            ]));
        } catch (\Throwable) {
            return '';
        }
    }

    private function inbox(): BackendMenuSignalInboxInterface
    {
        /** @var BackendMenuSignalInbox $inbox */
        $inbox = ObjectManager::getInstance(BackendMenuSignalInbox::class);

        return $inbox;
    }
}
