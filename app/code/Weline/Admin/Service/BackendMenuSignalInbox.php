<?php

declare(strict_types=1);

namespace Weline\Admin\Service;

use Weline\Admin\Api\BackendMenuSignalInboxInterface;
use Weline\Admin\Model\BackendMenuSignalNotice;
use Weline\Admin\Model\BackendMenuSignalRead;
use Weline\Framework\Manager\ObjectManager;

/**
 * One inbox for menu-badge modules. Only rows still unread for this user are returned.
 */
final class BackendMenuSignalInbox implements BackendMenuSignalInboxInterface
{
    public function publish(
        string $code,
        string $itemId,
        string $title,
        string $href,
        bool $unread,
        int $backendUserId = 0,
    ): void {
        $code = trim($code);
        $itemId = trim($itemId);
        $href = trim($href);
        $title = trim($title);
        if ($code === '' || $itemId === '' || $href === '') {
            return;
        }
        if ($title === '') {
            $title = $itemId;
        }

        $notice = $this->notice();
        $notice->where(BackendMenuSignalNotice::schema_fields_SIGNAL_CODE, $code)
            ->where(BackendMenuSignalNotice::schema_fields_ITEM_ID, $itemId)
            ->find()
            ->fetch();
        $notice->setData(BackendMenuSignalNotice::schema_fields_SIGNAL_CODE, $code);
        $notice->setData(BackendMenuSignalNotice::schema_fields_ITEM_ID, $itemId);
        $notice->setData(BackendMenuSignalNotice::schema_fields_TITLE, $title);
        $notice->setData(BackendMenuSignalNotice::schema_fields_HREF, $href);
        $notice->save();

        $userId = max(0, $backendUserId);
        if ($userId <= 0) {
            return;
        }
        if (!$unread) {
            $this->markRead($code, $itemId, $userId);

            return;
        }
        $this->writeFlag($code, $itemId, $userId, false);
    }

    public function markRead(string $code, string $itemId, int $backendUserId): void
    {
        $code = trim($code);
        $itemId = trim($itemId);
        $backendUserId = max(0, $backendUserId);
        if ($code === '' || $itemId === '' || $backendUserId <= 0) {
            return;
        }
        $this->writeFlag($code, $itemId, $backendUserId, true);
    }

    public function markUnread(string $code, string $itemId, int $backendUserId): void
    {
        $code = trim($code);
        $itemId = trim($itemId);
        $backendUserId = max(0, $backendUserId);
        if ($code === '' || $itemId === '' || $backendUserId <= 0) {
            return;
        }
        $row = $this->read();
        $row->where(BackendMenuSignalRead::schema_fields_SIGNAL_CODE, $code)
            ->where(BackendMenuSignalRead::schema_fields_ITEM_ID, $itemId)
            ->where(BackendMenuSignalRead::schema_fields_USER_ID, $backendUserId)
            ->find()
            ->fetch();
        $row->setData(BackendMenuSignalRead::schema_fields_SIGNAL_CODE, $code);
        $row->setData(BackendMenuSignalRead::schema_fields_ITEM_ID, $itemId);
        $row->setData(BackendMenuSignalRead::schema_fields_USER_ID, $backendUserId);
        $row->setData(BackendMenuSignalRead::schema_fields_IS_READ, 0);
        $row->setData(BackendMenuSignalRead::schema_fields_READ_AT, null);
        $row->save();
    }

    public function unreadCount(string $code, int $backendUserId): int
    {
        return count($this->unreadItems($code, $backendUserId, 500));
    }

    public function unreadItems(string $code, int $backendUserId, int $limit): array
    {
        $code = trim($code);
        $backendUserId = max(0, $backendUserId);
        $limit = max(1, $limit);
        if ($code === '' || $backendUserId <= 0) {
            return [];
        }

        $readIds = $this->readItemIds($code, $backendUserId);
        $notice = $this->notice();
        $notice->where(BackendMenuSignalNotice::schema_fields_SIGNAL_CODE, $code)
            ->order(BackendMenuSignalNotice::schema_fields_ID, 'DESC')
            ->limit(500)
            ->select()
            ->fetch();

        $items = [];
        foreach ($notice->getItems() as $row) {
            if (!$row instanceof BackendMenuSignalNotice) {
                continue;
            }
            $itemId = trim((string)$row->getData(BackendMenuSignalNotice::schema_fields_ITEM_ID));
            $href = trim((string)$row->getData(BackendMenuSignalNotice::schema_fields_HREF));
            if ($itemId === '' || $href === '' || isset($readIds[$itemId])) {
                continue;
            }
            $title = trim((string)$row->getData(BackendMenuSignalNotice::schema_fields_TITLE));
            $items[] = [
                'id' => $itemId,
                'title' => $title !== '' ? $title : $itemId,
                'href' => $href,
                'count' => 1,
                'unread' => true,
            ];
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /**
     * @return array<string, true>
     */
    private function readItemIds(string $code, int $backendUserId): array
    {
        $read = $this->read();
        $read->where(BackendMenuSignalRead::schema_fields_SIGNAL_CODE, $code)
            ->where(BackendMenuSignalRead::schema_fields_USER_ID, $backendUserId)
            ->where(BackendMenuSignalRead::schema_fields_IS_READ, 1)
            ->select()
            ->fetch();
        $ids = [];
        foreach ($read->getItems() as $row) {
            if (!$row instanceof BackendMenuSignalRead) {
                continue;
            }
            $itemId = trim((string)$row->getData(BackendMenuSignalRead::schema_fields_ITEM_ID));
            if ($itemId !== '') {
                $ids[$itemId] = true;
            }
        }

        return $ids;
    }

    private function writeFlag(string $code, string $itemId, int $backendUserId, bool $read): void
    {
        $row = $this->read();
        $row->where(BackendMenuSignalRead::schema_fields_SIGNAL_CODE, $code)
            ->where(BackendMenuSignalRead::schema_fields_ITEM_ID, $itemId)
            ->where(BackendMenuSignalRead::schema_fields_USER_ID, $backendUserId)
            ->find()
            ->fetch();
        if ($read) {
            $row->setData(BackendMenuSignalRead::schema_fields_IS_READ, 1);
            $row->setData(BackendMenuSignalRead::schema_fields_READ_AT, date('Y-m-d H:i:s'));
        } elseif ((int)$row->getData(BackendMenuSignalRead::schema_fields_IS_READ) === 1) {
            return;
        } else {
            $row->setData(BackendMenuSignalRead::schema_fields_IS_READ, 0);
            $row->setData(BackendMenuSignalRead::schema_fields_READ_AT, null);
        }
        $row->setData(BackendMenuSignalRead::schema_fields_SIGNAL_CODE, $code);
        $row->setData(BackendMenuSignalRead::schema_fields_ITEM_ID, $itemId);
        $row->setData(BackendMenuSignalRead::schema_fields_USER_ID, $backendUserId);
        $row->save();
    }

    private function notice(): BackendMenuSignalNotice
    {
        /** @var BackendMenuSignalNotice $model */
        $model = ObjectManager::getInstance(BackendMenuSignalNotice::class);
        $model->clear();

        return $model;
    }

    private function read(): BackendMenuSignalRead
    {
        /** @var BackendMenuSignalRead $model */
        $model = ObjectManager::getInstance(BackendMenuSignalRead::class);
        $model->clear();

        return $model;
    }
}
