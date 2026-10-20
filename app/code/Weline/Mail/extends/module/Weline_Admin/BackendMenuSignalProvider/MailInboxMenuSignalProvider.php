<?php

declare(strict_types=1);

namespace Weline\Mail\Extends\Module\Weline_Admin\BackendMenuSignalProvider;

use Weline\Admin\Api\BackendMenuSignalItemsProviderInterface;
use Weline\Admin\Api\BackendMenuSignalProviderInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Service\Mailbox\MailMenuSignalPublisher;

/**
 * 企业邮局未读信的侧栏角标。点开进到具体一封邮件。
 */
final class MailInboxMenuSignalProvider implements
    BackendMenuSignalProviderInterface,
    BackendMenuSignalItemsProviderInterface
{
    public function code(): string
    {
        return MailMenuSignalPublisher::MENU_SOURCE_ID;
    }

    public function count(int $backendUserId): int
    {
        return count($this->items($backendUserId));
    }

    /**
     * @return list<array{id: string, title: string, href: string, count: int, unread: true}>
     */
    public function items(int $backendUserId): array
    {
        if ($backendUserId <= 0) {
            return [];
        }
        try {
            /** @var MailMenuSignalPublisher $publisher */
            $publisher = ObjectManager::getInstance(MailMenuSignalPublisher::class);

            return $publisher->unreadItems($backendUserId);
        } catch (\Throwable) {
            return [];
        }
    }
}
