<?php

declare(strict_types=1);

namespace Weline\CustomerService\Extends\Module\Weline_Admin\BackendMenuSignalProvider;

use Weline\Admin\Api\BackendMenuSignalItemsProviderInterface;
use Weline\Admin\Api\BackendMenuSignalProviderInterface;
use Weline\Admin\Service\BackendMenuSignalAggregator;
use Weline\CustomerService\Model\ServiceAgent;
use Weline\CustomerService\Service\ChatService;
use Weline\Framework\Http\UrlInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * Backend sidebar unread badge + session deep links for Weline_CustomerService::console.
 */
final class CustomerServiceConsoleMenuSignalProvider implements
    BackendMenuSignalProviderInterface,
    BackendMenuSignalItemsProviderInterface
{
    public const MENU_SOURCE_ID = 'Weline_CustomerService::console';

    public function code(): string
    {
        return self::MENU_SOURCE_ID;
    }

    public function count(int $backendUserId): int
    {
        $agentId = $this->resolveActiveAgentId($backendUserId);
        if ($agentId <= 0) {
            return 0;
        }

        try {
            /** @var ChatService $chatService */
            $chatService = ObjectManager::getInstance(ChatService::class);

            return max(0, $chatService->countUnreadForAgent($agentId));
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return list<array{id: string, title: string, href: string, count: int}>
     */
    public function items(int $backendUserId): array
    {
        $agentId = $this->resolveActiveAgentId($backendUserId);
        if ($agentId <= 0) {
            return [];
        }

        try {
            /** @var ChatService $chatService */
            $chatService = ObjectManager::getInstance(ChatService::class);
            $sessions = $chatService->listUnreadSessionsForAgent(
                $agentId,
                BackendMenuSignalAggregator::MAX_ITEMS_PER_CODE
            );
        } catch (\Throwable) {
            return [];
        }

        /** @var UrlInterface $url */
        $url = ObjectManager::getInstance(UrlInterface::class);
        $items = [];
        foreach ($sessions as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sessionId = (int)($row['session_id'] ?? 0);
            $unread = max(0, (int)($row['unread_count'] ?? 0));
            if ($sessionId <= 0 || $unread <= 0) {
                continue;
            }
            $href = trim((string)$url->getBackendUrl('customerservice/backend/console', [
                'session_id' => $sessionId,
            ]));
            if ($href === '') {
                continue;
            }
            $title = trim((string)($row['list_title'] ?? ''));
            if ($title === '') {
                $title = '#' . $sessionId;
            }
            $items[] = [
                'id' => (string)$sessionId,
                'title' => $title,
                'href' => $href,
                'count' => $unread,
                'unread' => true,
            ];
        }

        return $items;
    }

    private function resolveActiveAgentId(int $backendUserId): int
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

            return (int)$agent->getId();
        } catch (\Throwable) {
            return 0;
        }
    }
}
