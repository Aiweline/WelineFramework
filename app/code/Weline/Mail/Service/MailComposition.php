<?php

declare(strict_types=1);

namespace Weline\Mail\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Api\MailCompositionInterface;

/**
 * Adapter exposing mail composition/search through the public Mail API.
 */
final class MailComposition implements MailCompositionInterface
{
    public function send(
        int $accountId,
        string $to,
        string $subject,
        string $body,
        string $source = '',
        int $sourceId = 0,
        string $cc = ''
    ): array {
        return ObjectManager::getInstance(MailComposerService::class)
            ->send($accountId, $to, $subject, $body, $source, $sourceId, $cc);
    }

    public function searchAccounts(string $query = '', int $limit = 50): array
    {
        return ObjectManager::getInstance(MailSmtpAccountService::class)->searchAccounts($query, $limit);
    }
}
