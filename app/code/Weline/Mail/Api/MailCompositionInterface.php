<?php

declare(strict_types=1);

namespace Weline\Mail\Api;

/**
 * Public mail composition boundary for modules that send mail through a
 * configured mailbox without depending on Mail's internal services.
 */
interface MailCompositionInterface
{
    /**
     * @return array<string, mixed>
     */
    public function send(
        int $accountId,
        string $to,
        string $subject,
        string $body,
        string $source = '',
        int $sourceId = 0,
        string $cc = ''
    ): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function searchAccounts(string $query = '', int $limit = 50): array;
}
