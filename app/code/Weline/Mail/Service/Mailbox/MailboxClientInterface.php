<?php
declare(strict_types=1);

namespace Weline\Mail\Service\Mailbox;

/**
 * Mailbox transport for a single mailbox address (live JMAP or fake ORM).
 * Install/env remains on MailEngineInterface — do not merge.
 */
interface MailboxClientInterface
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listMessages(string $mailbox, string $folder, int $limit = 50): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function searchMessages(string $mailbox, string $folder, string $query, int $limit = 50): array;

    public function getMessage(string $mailbox, string $messageId): ?array;

    /**
     * @param list<string> $messageIds
     */
    public function setSeen(string $mailbox, array $messageIds, bool $seen): void;

    /**
     * @param list<string> $messageIds
     */
    public function moveToTrash(string $mailbox, array $messageIds): void;

    /**
     * @param list<string> $messageIds
     */
    public function restoreToInbox(string $mailbox, array $messageIds): void;

    /**
     * @param list<string> $messageIds
     */
    public function destroy(string $mailbox, array $messageIds): void;

    /**
     * @return array{name:string,type:string,size:int,blob_id:string,content:string}
     */
    public function downloadBlob(string $mailbox, string $blobId, string $name = ''): array;

    /**
     * @return array{blob_id:string,name:string,type:string,size:int}
     */
    public function uploadBlob(string $mailbox, string $name, string $type, string $binary): array;

    /**
     * @param string|list<string> $to
     * @param list<array{blob_id:string,name:string,type:string,size?:int}> $attachments
     * @return array{success:bool,message:mixed}
     */
    public function send(
        string $mailbox,
        string|array $to,
        string $subject,
        string $body,
        array $attachments = [],
        string|array $cc = []
    ): array;
}
