<?php
declare(strict_types=1);

namespace Weline\Cms\Extends\Module\Weline_Trash\TrashProvider;

use Weline\BackendActivity\Api\BusinessContextInterface;
use Weline\Cms\Model\PathGroup;
use Weline\Cms\Service\PageService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Trash\Api\TrashProviderInterface;

class CmsPathGroupTrashProvider implements TrashProviderInterface
{
    public static function code(): string
    {
        return 'weline_cms.path_group';
    }

    public static function label(): string
    {
        return (string)__('CMS 一级 path');
    }

    public static function trash(array $data, array $context = []): array
    {
        $pageService = self::pageService();
        $group = self::resolveGroup($data, true);
        if ($group === null) {
            return [
                'success' => false,
                'message' => (string)__('CMS 一级 path 不存在。'),
                'code' => 'cms_path_group_missing',
            ];
        }

        $activePages = $pageService->listPages([
            'website_id' => $group->getWebsiteId(),
            'path_group' => $group->getPathGroup(),
            'page' => 1,
            'page_size' => 1,
        ]);
        if (!empty($activePages['items'])) {
            return [
                'success' => false,
                'message' => (string)__('该一级 path 下还有页面，不能直接移入回收站。'),
                'code' => 'cms_path_group_has_pages',
            ];
        }

        $rawData = self::snapshotGroup($group);
        if (!$group->isDeleted()) {
            $pageService->savePathGroup([
                'group_id' => $group->getGroupId(),
                'website_id' => $group->getWebsiteId(),
                'website_code' => $group->getWebsiteCode(),
                'path_group' => $group->getPathGroup(),
                'alias' => $group->getAlias(),
                'sort_order' => (int)$group->getData(PathGroup::schema_fields_SORT_ORDER),
                'deleted_at' => date('Y-m-d H:i:s'),
            ]);
        }
        self::markActivity('cms_path_group', $group->getGroupId(), 'trash', self::labelForGroup($group), [
            'website_id' => $group->getWebsiteId(),
            'website_code' => $group->getWebsiteCode(),
            'path_group' => $group->getPathGroup(),
            'alias' => $group->getAlias(),
            'context' => $context,
        ]);

        return [
            'success' => true,
            'message' => (string)__('CMS 一级 path 已移入回收站。'),
            'entity_id' => (string)$group->getGroupId(),
            'entity_key' => 'path_group:' . $group->getGroupId(),
            'label' => ($group->getAlias() !== '' ? $group->getAlias() : $group->getPathGroup()) . ' / ' . $group->getPathGroup(),
            'summary' => [
                'group_id' => $group->getGroupId(),
                'website_id' => $group->getWebsiteId(),
                'website_code' => $group->getWebsiteCode(),
                'path_group' => $group->getPathGroup(),
                'alias' => $group->getAlias(),
            ],
            'raw_data' => $rawData,
            'scope' => [
                'website_id' => $group->getWebsiteId(),
                'website_code' => $group->getWebsiteCode(),
            ],
            'provider_version' => '1',
        ];
    }

    public static function restore(array $item, array $context = []): array
    {
        $rawData = $item['raw_data'] ?? null;
        $row = self::extractRow($rawData);
        $groupId = (int)($row[PathGroup::schema_fields_ID] ?? $row['group_id'] ?? $item['entity_id'] ?? 0);
        if ($groupId <= 0) {
            return [
                'success' => false,
                'message' => (string)__('回收站快照中缺少 CMS 一级 path ID，无法恢复。'),
                'code' => 'missing_group_id',
            ];
        }

        $pageService = self::pageService();
        $group = $pageService->getPathGroupModel($groupId, true);
        if ($group === null) {
            return [
                'success' => false,
                'message' => (string)__('原 CMS 一级 path 记录不存在，无法自动恢复；请从原始数据手动处理。'),
                'code' => 'cms_path_group_row_missing',
            ];
        }

        try {
            $restored = $pageService->restorePathGroupFromTrashRow($row);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'code' => 'cms_path_group_restore_failed',
                'exception' => $e::class,
            ];
        }

        self::markActivity('cms_path_group', $restored->getGroupId(), 'restore', self::labelForGroup($restored), [
            'website_id' => $restored->getWebsiteId(),
            'website_code' => $restored->getWebsiteCode(),
            'path_group' => $restored->getPathGroup(),
            'alias' => $restored->getAlias(),
            'trash_id' => (int)($item['trash_id'] ?? 0),
            'context' => $context,
        ]);

        return [
            'success' => true,
            'message' => (string)__('CMS 一级 path 已恢复。'),
            'path_group' => $restored->toApiArray(),
        ];
    }

    public static function purge(array $item, array $context = []): array
    {
        $rawData = $item['raw_data'] ?? null;
        $row = self::extractRow($rawData);
        $groupId = (int)($row[PathGroup::schema_fields_ID] ?? $row['group_id'] ?? $item['entity_id'] ?? 0);
        if ($groupId <= 0) {
            return [
                'success' => false,
                'message' => (string)__('回收站快照中缺少 CMS 一级 path ID，无法永久清理。'),
                'code' => 'missing_group_id',
            ];
        }

        $pageService = self::pageService();
        $group = $pageService->getPathGroupModel($groupId, true);
        if ($group !== null) {
            if (!$group->isDeleted()) {
                return [
                    'success' => false,
                    'message' => (string)__('CMS 一级 path 当前未处于删除状态，不能永久清理。'),
                    'code' => 'cms_path_group_not_deleted',
                ];
            }
            $group->delete();
        }

        self::markActivity('cms_path_group', $groupId, 'purge', self::titleFromRow($row, 'Path Group #' . $groupId), [
            'website_id' => (int)($row[PathGroup::schema_fields_WEBSITE_ID] ?? $row['website_id'] ?? 0),
            'website_code' => (string)($row[PathGroup::schema_fields_WEBSITE_CODE] ?? $row['website_code'] ?? ''),
            'path_group' => (string)($row[PathGroup::schema_fields_PATH_GROUP] ?? $row['path_group'] ?? ''),
            'alias' => (string)($row[PathGroup::schema_fields_ALIAS] ?? $row['alias'] ?? ''),
            'trash_id' => (int)($item['trash_id'] ?? 0),
            'context' => $context,
        ]);

        return [
            'success' => true,
            'message' => (string)__('CMS 一级 path 记录已永久清理。'),
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

    private static function labelForGroup(PathGroup $group): string
    {
        $pathGroup = $group->getPathGroup();
        $alias = $group->getAlias();
        return ($alias !== '' ? $alias : $pathGroup) . ' / ' . $pathGroup;
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function titleFromRow(array $row, string $fallback): string
    {
        $pathGroup = trim((string)($row[PathGroup::schema_fields_PATH_GROUP] ?? $row['path_group'] ?? ''));
        $alias = trim((string)($row[PathGroup::schema_fields_ALIAS] ?? $row['alias'] ?? ''));
        if ($pathGroup === '') {
            return $fallback;
        }

        return ($alias !== '' ? $alias : $pathGroup) . ' / ' . $pathGroup;
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function resolveGroup(array $data, bool $includeDeleted): ?PathGroup
    {
        $pageService = self::pageService();
        $groupId = (int)($data['group_id'] ?? $data['path_group_id'] ?? $data['id'] ?? 0);
        if ($groupId > 0) {
            return $pageService->getPathGroupModel($groupId, $includeDeleted);
        }

        $websiteId = (int)($data['website_id'] ?? 0);
        $pathGroup = trim((string)($data['path_group'] ?? $data['path'] ?? ''));
        if ($websiteId > 0 && $pathGroup !== '') {
            return $pageService->getPathGroupByPath($websiteId, $pathGroup, $includeDeleted);
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private static function snapshotGroup(PathGroup $group): array
    {
        return [
            'module' => 'Weline_Cms',
            'model' => PathGroup::class,
            'table' => PathGroup::schema_table,
            'primary_key' => PathGroup::schema_primary_key,
            'row' => $group->getData(),
            'api' => $group->toApiArray(),
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
