<?php

declare(strict_types=1);

namespace Weline\Admin\Api;

/**
 * Shared unread inbox for backend menu badges.
 *
 * Modules write a message here with an explicit unread/read flag.
 * The sidebar badge lists only unread rows. This is not the bell/mail channel.
 */
interface BackendMenuSignalInboxInterface
{
    /**
     * Insert a menu message. A new row starts unread when $unread is true.
     * An already-read row stays read.
     */
    public function publish(
        string $code,
        string $itemId,
        string $title,
        string $href,
        bool $unread,
        int $backendUserId = 0,
    ): void;

    public function markRead(string $code, string $itemId, int $backendUserId): void;

    /**
     * 把这一条重新标成未读。publish 不会把已读行翻回去，只有这里会。
     */
    public function markUnread(string $code, string $itemId, int $backendUserId): void;

    public function unreadCount(string $code, int $backendUserId): int;

    /**
     * @return list<array{id: string, title: string, href: string, count: int, unread: true}>
     */
    public function unreadItems(string $code, int $backendUserId, int $limit): array;
}
