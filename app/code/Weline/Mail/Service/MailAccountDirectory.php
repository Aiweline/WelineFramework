<?php

declare(strict_types=1);

namespace Weline\Mail\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Api\MailAccountDirectoryInterface;
use Weline\Mail\Model\MailAccount;

/**
 * Adapter exposing outbound account lookup by email.
 */
final class MailAccountDirectory implements MailAccountDirectoryInterface
{
    public function activeAccountIdByEmail(string $email): int
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return 0;
        }
        try {
            /** @var MailAccount $account */
            $account = ObjectManager::getInstance(MailAccount::class)->clear()
                ->where(MailAccount::schema_fields_EMAIL, $email)
                ->where(MailAccount::schema_fields_STATUS, 'active')
                ->find()
                ->fetch();
            return (int)($account->getId() ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }
}
