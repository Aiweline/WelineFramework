<?php

declare(strict_types=1);

namespace Weline\Mail\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Api\MailMessageLookupInterface;
use Weline\Mail\Model\MailMessage;

/**
 * Adapter exposing the latest mail message id by business source.
 */
final class MailMessageLookup implements MailMessageLookupInterface
{
    public function latestIdBySource(string $source, int $sourceId): int
    {
        try {
            /** @var MailMessage $message */
            $message = ObjectManager::getInstance(MailMessage::class)->clear()
                ->where(MailMessage::schema_fields_SOURCE, $source)
                ->where(MailMessage::schema_fields_SOURCE_ID, $sourceId)
                ->order(MailMessage::schema_fields_ID, 'DESC')
                ->find();
            return (int)$message->getId();
        } catch (\Throwable) {
            return 0;
        }
    }
}
