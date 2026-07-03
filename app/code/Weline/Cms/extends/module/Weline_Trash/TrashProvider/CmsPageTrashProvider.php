<?php
declare(strict_types=1);

namespace Weline\Cms\Extends\Module\Weline_Trash\TrashProvider;

use Weline\BackendActivity\Api\BusinessContextInterface;
use Weline\Cms\Model\Page;
use Weline\Cms\Service\PageService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Trash\Api\TrashProviderInterface;

class CmsPageTrashProvider implements TrashProviderInterface
{
    public static function code(): string
    {
        return 'weline_cms.page';
    }

    public static function label(): string
    {
        return (string)__('CMS 页面');
    }

    public static function trash(array $data, array $context = []): array
    {
        $pageId = (int)($data['page_id'] ?? $data['id'] ?? 0);
        if ($pageId <= 0) {
            return [
                'success' => false,
                'message' => (string)__('CMS 页面ID不能为空。'),
                'code' => 'missing_page_id',
            ];
        }

        $pageService = self::pageService();
        $page = $pageService->getPageModel($pageId, true);
        if ($page === null) {
            return [
                'success' => false,
                'message' => (string)__('CMS 页面不存在。'),
                'code' => 'cms_page_missing',
            ];
        }

        $rawData = self::snapshotPage($page);
        if (!$page->isDeleted()) {
            $pageService->softDeletePage($pageId);
        }
        self::markActivity('cms_page', $pageId, 'trash', $page->getTitle(), [
            'identifier' => $page->getIdentifier(),
            'status' => $page->getStatus(),
            'website_id' => $page->getWebsiteId(),
            'website_code' => $page->getWebsiteCode(),
            'path_group' => $page->getPathGroup(),
            'slug' => $page->getSlug(),
            'context' => $context,
        ]);

        return [
            'success' => true,
            'message' => (string)__('CMS 页面已移入回收站。'),
            'entity_id' => (string)$pageId,
            'entity_key' => 'page:' . $pageId,
            'label' => $page->getTitle() !== '' ? $page->getTitle() : ('CMS Page #' . $pageId),
            'summary' => [
                'page_id' => $pageId,
                'title' => $page->getTitle(),
                'identifier' => $page->getIdentifier(),
                'status' => $page->getStatus(),
                'website_id' => $page->getWebsiteId(),
                'website_code' => $page->getWebsiteCode(),
                'path_group' => $page->getPathGroup(),
                'slug' => $page->getSlug(),
            ],
            'raw_data' => $rawData,
            'scope' => [
                'website_id' => $page->getWebsiteId(),
                'website_code' => $page->getWebsiteCode(),
                'scope' => $page->getScope(),
            ],
            'provider_version' => '1',
        ];
    }

    public static function restore(array $item, array $context = []): array
    {
        $rawData = $item['raw_data'] ?? null;
        $row = self::extractRow($rawData);
        $pageId = (int)($row[Page::schema_fields_ID] ?? $row['page_id'] ?? $item['entity_id'] ?? 0);
        if ($pageId <= 0) {
            return [
                'success' => false,
                'message' => (string)__('回收站快照中缺少 CMS 页面ID，无法恢复。'),
                'code' => 'missing_page_id',
            ];
        }

        $pageService = self::pageService();
        $page = $pageService->getPageModel($pageId, true);
        if ($page === null) {
            return [
                'success' => false,
                'message' => (string)__('原 CMS 页面记录不存在，无法自动恢复；请从原始数据手动处理。'),
                'code' => 'cms_page_row_missing',
            ];
        }

        try {
            $restored = $pageService->restorePageFromTrashRow($row);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'code' => 'cms_page_restore_failed',
                'exception' => $e::class,
            ];
        }

        self::markActivity('cms_page', $restored->getPageId(), 'restore', $restored->getTitle(), [
            'identifier' => $restored->getIdentifier(),
            'status' => $restored->getStatus(),
            'website_id' => $restored->getWebsiteId(),
            'website_code' => $restored->getWebsiteCode(),
            'path_group' => $restored->getPathGroup(),
            'slug' => $restored->getSlug(),
            'trash_id' => (int)($item['trash_id'] ?? 0),
            'context' => $context,
        ]);

        return [
            'success' => true,
            'message' => (string)__('CMS 页面已恢复。'),
            'page' => $restored->toApiArray(),
        ];
    }

    public static function purge(array $item, array $context = []): array
    {
        $rawData = $item['raw_data'] ?? null;
        $row = self::extractRow($rawData);
        $pageId = (int)($row[Page::schema_fields_ID] ?? $row['page_id'] ?? $item['entity_id'] ?? 0);
        if ($pageId <= 0) {
            return [
                'success' => false,
                'message' => (string)__('回收站快照中缺少 CMS 页面ID，无法永久清理。'),
                'code' => 'missing_page_id',
            ];
        }

        $page = self::pageService()->getPageModel($pageId, true);
        if ($page !== null) {
            if (!$page->isDeleted()) {
                return [
                    'success' => false,
                    'message' => (string)__('CMS 页面当前未处于删除状态，不能永久清理。'),
                    'code' => 'cms_page_not_deleted',
                ];
            }
            $page->delete();
        }

        self::markActivity('cms_page', $pageId, 'purge', self::titleFromRow($row, 'CMS Page #' . $pageId), [
            'identifier' => (string)($row[Page::schema_fields_IDENTIFIER] ?? $row['identifier'] ?? ''),
            'status' => (string)($row[Page::schema_fields_STATUS] ?? $row['status'] ?? ''),
            'website_id' => (int)($row[Page::schema_fields_WEBSITE_ID] ?? $row['website_id'] ?? 0),
            'website_code' => (string)($row[Page::schema_fields_WEBSITE_CODE] ?? $row['website_code'] ?? ''),
            'path_group' => (string)($row[Page::schema_fields_PATH_GROUP] ?? $row['path_group'] ?? ''),
            'slug' => (string)($row[Page::schema_fields_SLUG] ?? $row['slug'] ?? ''),
            'trash_id' => (int)($item['trash_id'] ?? 0),
            'context' => $context,
        ]);

        return [
            'success' => true,
            'message' => (string)__('CMS 页面记录已永久清理，Theme 历史数据保留。'),
        ];
    }

    private static function pageService(): PageService
    {
        /** @var PageService $pageService */
        $pageService = ObjectManager::getInstance(PageService::class);
        return $pageService;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function markActivity(string $entityType, string|int $entityId, string $action, string $title, array $payload): void
    {
        try {
            /** @var BusinessContextInterface $activityContext */
            $activityContext = ObjectManager::getInstance(BusinessContextInterface::class);
            $activityContext->mark('Weline_Cms', $entityType, $entityId, $action, $title, $payload);
        } catch (\Throwable) {
        }
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function titleFromRow(array $row, string $fallback): string
    {
        $title = trim((string)($row[Page::schema_fields_TITLE] ?? $row['title'] ?? ''));
        return $title !== '' ? $title : $fallback;
    }

    /**
     * @return array<string,mixed>
     */
    private static function snapshotPage(Page $page): array
    {
        return [
            'module' => 'Weline_Cms',
            'model' => Page::class,
            'table' => Page::schema_table,
            'primary_key' => Page::schema_primary_key,
            'row' => $page->getData(),
            'api' => $page->toApiArray(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function extractRow(mixed $rawData): array
    {
        if (!is_array($rawData)) {
            return [];
        }
        $row = $rawData['row'] ?? null;
        if (is_array($row)) {
            return $row;
        }
        $api = $rawData['api'] ?? null;
        return is_array($api) ? $api : $rawData;
    }
}
