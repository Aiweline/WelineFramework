<?php

declare(strict_types=1);

namespace Weline\Inquiry\Extends\Module\Weline_Admin\BackendMenuSignalProvider;

use Weline\Admin\Api\BackendMenuSignalItemsProviderInterface;
use Weline\Admin\Api\BackendMenuSignalProviderInterface;
use Weline\Admin\Service\BackendMenuSignalAggregator;
use Weline\Admin\Service\BackendMenuSignalInbox;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Manager\ObjectManager;
use Weline\Inquiry\Model\Submission;
use Weline\Inquiry\Service\InquiryWebsiteScopeService;

/**
 * 询盘提交记录的侧栏未读角标 + 单条未读深链。
 * 普通运营只看当前站点；超管（user_id=1 / role_id=1）看全部站点。
 */
final class InquirySubmissionMenuSignalProvider implements
    BackendMenuSignalProviderInterface,
    BackendMenuSignalItemsProviderInterface
{
    public const MENU_SOURCE_ID = 'Weline_Inquiry::submissions';

    public function __construct(
        private readonly ?InquiryWebsiteScopeService $websiteScope = null,
    ) {
    }

    public function code(): string
    {
        return self::MENU_SOURCE_ID;
    }

    public function count(int $backendUserId): int
    {
        if ($backendUserId <= 0) {
            return 0;
        }
        if ($this->isSuperAdmin($backendUserId)) {
            try {
                return $this->inbox()->unreadCount(self::MENU_SOURCE_ID, $backendUserId);
            } catch (\Throwable $e) {
                w_log_error('Inquiry menu signal count failed: ' . $e->getMessage(), [], 'inquiry_menu_signal');

                return 0;
            }
        }

        return \count($this->siteScopedItems($backendUserId, 500));
    }

    /**
     * @return list<array{id: string, title: string, href: string, count: int, unread: true}>
     */
    public function items(int $backendUserId): array
    {
        if ($backendUserId <= 0) {
            return [];
        }
        $items = $this->siteScopedItems($backendUserId, BackendMenuSignalAggregator::MAX_ITEMS_PER_CODE);
        if ($items === []) {
            return [];
        }

        $url = ObjectManager::getInstance(\Weline\Framework\Http\UrlInterface::class);
        $out = [];
        foreach ($items as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $href = trim((string)$url->getBackendUrl('inquiry/backend/submission', ['focus' => $id]));
            if ($href === '') {
                $href = trim((string)($row['href'] ?? ''));
            }
            if ($href === '') {
                continue;
            }
            if (!str_contains($href, '#')) {
                $href .= '#inquiry-submission-' . $id;
            }
            $title = trim((string)($row['title'] ?? ''));
            $out[] = [
                'id' => (string)$id,
                'title' => $title !== '' ? $title : ((string)__('询盘') . ' #' . $id),
                'href' => $href,
                'count' => 1,
                'unread' => true,
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function siteScopedItems(int $backendUserId, int $limit): array
    {
        try {
            $rows = $this->inbox()->unreadItems(
                self::MENU_SOURCE_ID,
                $backendUserId,
                max(1, min(500, $limit))
            );
        } catch (\Throwable $e) {
            w_log_error('Inquiry menu signal items failed: ' . $e->getMessage(), [], 'inquiry_menu_signal');

            return [];
        }
        if ($this->isSuperAdmin($backendUserId)) {
            return $rows;
        }

        $websiteId = $this->websiteScope()->websiteIdForBackendUser($backendUserId);
        $filtered = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $submission = ObjectManager::getInstance(Submission::class);
            $submission->reset()->clearData()->load($id);
            if (!$submission->getId()) {
                continue;
            }
            if ((int)$submission->getData(Submission::schema_fields_WEBSITE_ID) === $websiteId) {
                $filtered[] = $row;
            }
        }

        return $filtered;
    }

    private function isSuperAdmin(int $backendUserId): bool
    {
        if ($backendUserId === 1) {
            return true;
        }
        if ($backendUserId <= 0) {
            return false;
        }
        try {
            $context = BackendUser::getAclContext($backendUserId);

            return (int)($context['role_id'] ?? 0) === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    private function websiteScope(): InquiryWebsiteScopeService
    {
        return $this->websiteScope ?? ObjectManager::getInstance(InquiryWebsiteScopeService::class);
    }

    private function inbox(): BackendMenuSignalInbox
    {
        return ObjectManager::getInstance(BackendMenuSignalInbox::class);
    }
}
