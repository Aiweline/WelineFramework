<?php

declare(strict_types=1);

namespace Weline\CustomerService\Extends\Module\Weline_Admin\BackendMenuSignalProvider;

use Weline\Admin\Api\BackendMenuSignalProviderInterface;
use Weline\CustomerService\Model\ServiceAgent;
use Weline\CustomerService\Service\ChatService;
use Weline\Framework\Manager\ObjectManager;

/**
 * Backend sidebar unread badge for Weline_CustomerService::console (JS paint only).
 */
final class CustomerServiceConsoleMenuSignalProvider implements BackendMenuSignalProviderInterface
{
    public const MENU_SOURCE_ID = 'Weline_CustomerService::console';

    public function code(): string
    {
        return self::MENU_SOURCE_ID;
    }

    public function count(int $backendUserId): int
    {
        $backendUserId = max(0, $backendUserId);
        if ($backendUserId <= 0) {
            return 0;
        }

        try {
            /** @var ServiceAgent $agent */
            $agent = ObjectManager::getInstance(ServiceAgent::class);
            $agent->reset()
                ->where(ServiceAgent::schema_fields_USER_ID, $backendUserId)
                ->where(ServiceAgent::schema_fields_IS_ACTIVE, 1)
                ->find()
                ->fetch();
            if (!$agent->getId()) {
                return 0;
            }

            /** @var ChatService $chatService */
            $chatService = ObjectManager::getInstance(ChatService::class);

            return max(0, $chatService->countUnreadForAgent((int)$agent->getId()));
        } catch (\Throwable) {
            return 0;
        }
    }
}
