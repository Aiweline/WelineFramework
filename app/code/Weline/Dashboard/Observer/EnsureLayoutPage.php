<?php

declare(strict_types=1);

namespace Weline\Dashboard\Observer;

use Weline\Dashboard\Model\DashboardView;
use Weline\Dashboard\Service\DashboardViewService;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class EnsureLayoutPage implements ObserverInterface
{
    public function __construct(
        private readonly DashboardViewService $dashboardViewService
    ) {
    }

    public function execute(Event &$event): void
    {
        $pageType = trim((string)($event->getData('page_type') ?? $event->getData('layout_type') ?? DashboardView::PAGE_TYPE));
        if ($pageType !== DashboardView::PAGE_TYPE) {
            $event->setData('result', [
                'success' => false,
                'status' => 'unsupported_page_type',
                'page_type' => $pageType,
            ]);
            return;
        }

        $code = trim((string)($event->getData('code') ?? ''));
        $name = trim((string)($event->getData('name') ?? ''));
        if ($code === '' || $name === '') {
            $event->setData('result', [
                'success' => false,
                'status' => 'missing_code_or_name',
            ]);
            return;
        }

        $layout = $event->getData('layout');
        $layoutData = is_array($layout) ? $layout : [];
        $options = [
            'visibility' => (string)($event->getData('visibility') ?? DashboardView::VISIBILITY_SYSTEM),
            'sort_order' => (int)($event->getData('sort_order') ?? 100),
            'replace_layout' => (bool)($event->getData('replace_layout') ?? false),
            'copy_default_layout' => (bool)($event->getData('copy_default_layout') ?? ($layoutData === [])),
            'update_name' => (bool)($event->getData('update_name') ?? false),
            'update_visibility' => (bool)($event->getData('update_visibility') ?? false),
        ];

        $items = [];
        foreach ($this->targetWebsiteIds($event) as $websiteId) {
            try {
                $items[] = $this->dashboardViewService->ensureSharedLayoutPage(
                    $websiteId,
                    $code,
                    $name,
                    $layoutData,
                    $options
                );
            } catch (\Throwable $throwable) {
                $items[] = [
                    'success' => false,
                    'website_id' => $websiteId,
                    'message' => $throwable->getMessage(),
                ];
            }
        }

        $success = $items !== [];
        foreach ($items as $item) {
            if (empty($item['success'])) {
                $success = false;
                break;
            }
        }

        $event->setData('result', [
            'success' => $success,
            'status' => $success ? 'ensured' : 'partial_failed',
            'module' => (string)($event->getData('module') ?? ''),
            'code' => $code,
            'items' => $items,
        ]);
    }

    /**
     * @return list<int>
     */
    private function targetWebsiteIds(Event $event): array
    {
        $targetIds = $event->getData('target_ids');
        if (is_array($targetIds)) {
            $ids = [];
            foreach ($targetIds as $targetId) {
                $id = (int)$targetId;
                if ($id >= DashboardViewService::DEFAULT_WEBSITE_ID) {
                    $ids[$id] = $id;
                }
            }
            return array_values($ids);
        }

        $targetId = $event->getData('target_id') ?? $event->getData('website_id') ?? '*';
        if ($targetId === '*' || $targetId === 'all') {
            $ids = [];
            foreach ($this->dashboardViewService->listWebsites() as $website) {
                $id = (int)($website['website_id'] ?? -1);
                if ($id >= DashboardViewService::DEFAULT_WEBSITE_ID) {
                    $ids[$id] = $id;
                }
            }
            if ($ids === []) {
                $ids[DashboardViewService::DEFAULT_WEBSITE_ID] = $this->dashboardViewService->getDefaultWebsiteId();
            }
            return array_values($ids);
        }

        $id = (int)$targetId;
        return [$id >= DashboardViewService::DEFAULT_WEBSITE_ID ? $id : $this->dashboardViewService->getDefaultWebsiteId()];
    }
}
