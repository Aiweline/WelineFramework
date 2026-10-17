<?php
declare(strict_types=1);

namespace Weline\Mail\Service\Mailbox;

final class MailboxFolder
{
    public const INBOX = 'inbox';
    public const SENT = 'sent';
    public const TRASH = 'trash';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::INBOX, self::SENT, self::TRASH];
    }

    public static function assertValid(string $folder): string
    {
        $folder = strtolower(trim($folder));
        if (!in_array($folder, self::all(), true)) {
            throw new \InvalidArgumentException('邮件夹参数无效。');
        }
        return $folder;
    }

    public static function jmapRole(string $folder): string
    {
        return match (self::assertValid($folder)) {
            self::INBOX => 'inbox',
            self::SENT => 'sent',
            self::TRASH => 'trash',
        };
    }
}
