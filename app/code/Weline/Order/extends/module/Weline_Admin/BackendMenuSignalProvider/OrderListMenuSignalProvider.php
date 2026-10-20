<?php

declare(strict_types=1);

namespace Weline\Order\Extends\Module\Weline_Admin\BackendMenuSignalProvider;

use Weline\Admin\Api\BackendMenuSignalInboxInterface;
use Weline\Admin\Api\BackendMenuSignalItemsProviderInterface;
use Weline\Admin\Api\BackendMenuSignalProviderInterface;
use Weline\Admin\Service\BackendMenuSignalAggregator;
use Weline\Admin\Service\BackendMenuSignalInbox;
use Weline\Framework\Http\UrlInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * Unread order menu messages for Weline_Order::order_list.
 *
 * Rows come from the shared menu inbox. Already-read orders are not badged.
 */
final class OrderListMenuSignalProvider implements
    BackendMenuSignalProviderInterface,
    BackendMenuSignalItemsProviderInterface
{
    public const MENU_SOURCE_ID = 'Weline_Order::order_list';

    public function code(): string
    {
        return self::MENU_SOURCE_ID;
    }

    public function count(int $backendUserId): int
    {
        if ($backendUserId <= 0) {
            return 0;
        }

        try {
            return $this->inbox()->unreadCount(self::MENU_SOURCE_ID, $backendUserId);
        } catch (\Throwable) {
            return 0;
        }
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
            $rows = $this->inbox()->unreadItems(
                self::MENU_SOURCE_ID,
                $backendUserId,
                BackendMenuSignalAggregator::MAX_ITEMS_PER_CODE
            );
        } catch (\Throwable) {
            return [];
        }

        /** @var UrlInterface $url */
        $url = ObjectManager::getInstance(UrlInterface::class);
        $items = [];
        foreach ($rows as $row) {
            $orderId = (int)($row['id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }
            $href = trim((string)$url->getBackendUrl('order/backend/order/view', [
                'id' => $orderId,
            ]));
            if ($href === '') {
                $href = trim((string)($row['href'] ?? ''));
            }
            if ($href === '') {
                continue;
            }
            $title = trim((string)($row['title'] ?? ''));
            $items[] = [
                'id' => (string)$orderId,
                'title' => $title !== '' ? $title : ((string)__('订单') . ' #' . $orderId),
                'href' => $href,
                'count' => 1,
                'unread' => true,
            ];
        }

        return $items;
    }

    private function inbox(): BackendMenuSignalInboxInterface
    {
        /** @var BackendMenuSignalInbox $inbox */
        $inbox = ObjectManager::getInstance(BackendMenuSignalInbox::class);

        return $inbox;
    }
}
