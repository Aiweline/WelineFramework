<?php

declare(strict_types=1);

namespace Weline\Cms\Service\Setup;

use Weline\Cms\Model\Page;
use Weline\Cms\Model\PathGroup;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;

/**
 * Data half of add_cms_site_path_groups_20260701-v1.0.2.
 *
 * Page columns/indexes are declared on the Page model; this service only splits
 * legacy identifiers into path_group + slug and seeds path groups idempotently.
 */
final class PagePathGroupBackfiller
{
    public function __construct(private readonly ConnectionFactory $connectionFactory)
    {
    }

    public function backfill(): void
    {
        $connection = $this->connectionFactory->getConnection();
        /** @var Page $pageModel */
        $pageModel = ObjectManager::getInstance(Page::class);
        /** @var PathGroup $pathGroupModel */
        $pathGroupModel = ObjectManager::getInstance(PathGroup::class);
        $canCreatePathGroups = $connection->tableExist($pathGroupModel->getTable());

        $rows = (clone $pageModel)->clearData()->reset()->select()->fetchArray();
        if (!is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $identifier = trim((string)($row[Page::schema_fields_IDENTIFIER] ?? ''), '/');
            if ($identifier === '') {
                continue;
            }
            $parts = explode('/', $identifier);
            $pathGroup = (string)array_shift($parts);
            $slug = implode('/', $parts);
            $alias = trim((string)($row[Page::schema_fields_PATH_GROUP_ALIAS] ?? ''));
            if ($alias === '') {
                $alias = $pathGroup;
            }
            $websiteId = (int)($row[Page::schema_fields_WEBSITE_ID] ?? 0);
            $websiteCode = trim((string)($row[Page::schema_fields_WEBSITE_CODE] ?? ''));
            if ($websiteCode === '') {
                $websiteCode = 'default';
            }

            $page = clone $pageModel;
            $page->clearData()->setData($row);
            $page->setData(Page::schema_fields_WEBSITE_ID, $websiteId);
            $page->setData(Page::schema_fields_WEBSITE_CODE, $websiteCode);
            $page->setData(Page::schema_fields_PATH_GROUP, $pathGroup);
            $page->setData(Page::schema_fields_PATH_GROUP_ALIAS, $alias);
            $page->setData(Page::schema_fields_SLUG, $slug);
            $page->save();

            if ($canCreatePathGroups) {
                $this->upsertPathGroup($websiteId, $websiteCode, $pathGroup, $alias, $pathGroupModel);
            }
        }
    }

    private function upsertPathGroup(
        int $websiteId,
        string $websiteCode,
        string $pathGroup,
        string $alias,
        PathGroup $pathGroupModel,
    ): void {
        $group = clone $pathGroupModel;
        $group->clearData()->reset()
            ->where(PathGroup::schema_fields_WEBSITE_ID, $websiteId)
            ->where(PathGroup::schema_fields_PATH_GROUP, $pathGroup)
            ->find()
            ->fetch();
        if ($group->getGroupId() <= 0) {
            $group->clearData();
        }

        $group->setData(PathGroup::schema_fields_WEBSITE_ID, $websiteId);
        $group->setData(PathGroup::schema_fields_WEBSITE_CODE, $websiteCode);
        $group->setData(PathGroup::schema_fields_PATH_GROUP, $pathGroup);
        $group->setData(PathGroup::schema_fields_ALIAS, $alias);
        $group->setData(PathGroup::schema_fields_DELETED_AT, null);
        $group->save();
    }
}
