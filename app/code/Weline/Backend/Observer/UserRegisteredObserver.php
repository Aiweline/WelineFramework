<?php

declare(strict_types=1);

namespace Weline\Backend\Observer;

use Weline\Backend\Service\UserContactService;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class UserRegisteredObserver implements ObserverInterface
{
    private UserContactService $contactService;

    public function __construct(UserContactService $contactService)
    {
        $this->contactService = $contactService;
    }

    /**
     * 用户注册/创建时自动创建默认联系人
     *
     * @param Event $event
     */
    public function execute(Event &$event): void
    {
        $data = $event->getData('data');

        $userId = (int) ($data['user_id'] ?? 0);
        $email = (string) ($data['email'] ?? '');
        $phone = $data['phone'] ?? null;
        $isNew = (bool) ($data['is_new'] ?? true);

        if (!$userId || !$email) {
            return;
        }

        if (!$isNew) {
            return;
        }

        $this->contactService->createDefaultContactsForUser($userId, $email, $phone);
    }
}
