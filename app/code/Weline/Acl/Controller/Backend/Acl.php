<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/1/7 20:39:18
 */

namespace Weline\Acl\Controller\Backend;

use Weline\Acl\Model\Acl as AclModel;
use Weline\Acl\Model\AclTag;
use Weline\Acl\Service\Resource\AclResourcePresentation;
use Weline\Framework\Manager\ObjectManager;

#[\Weline\Framework\Acl\Acl('Weline_Acl::acl', '管理权限','shield', '')]
class Acl extends \Weline\Framework\App\Controller\BackendPageController
{
    /**
     * 模块维度资源列表：搜索 + 类型 + 模块。
     */
    #[\Weline\Framework\Acl\Acl('Weline_Acl::acl_source', '模块资源', 'puzzle', '')]
    public function getIndex()
    {
        return $this->renderResourceList('module');
    }

    /**
     * 标签维度资源列表：搜索 + 类型 + 标签多选。
     */
    #[\Weline\Framework\Acl\Acl('Weline_Acl::acl_source_by_tag', '标签资源', 'search', '')]
    public function getByTag()
    {
        return $this->renderResourceList('tag');
    }

    /**
     * @param 'module'|'tag' $dimension
     */
    private function renderResourceList(string $dimension): string
    {
        /** @var AclModel $aclModel */
        $aclModel = ObjectManager::getInstance(AclModel::class);
        $search = \trim((string)$this->request->getGet('search', ''));
        $typeFilter = \trim((string)$this->request->getGet('type', ''));
        $moduleFilter = $dimension === 'module'
            ? \trim((string)$this->request->getGet('module', ''))
            : '';
        $tagFilters = $dimension === 'tag'
            ? AclResourcePresentation::parseTagFilterParam($this->request->getGet('tags', ''))
            : [];
        $listBaseRoute = $dimension === 'tag' ? '*/backend/acl/by-tag' : '*/backend/acl';
        $filterTypes = [
            AclModel::type_MENUS,
            AclModel::type_PC,
            AclModel::type_API,
            AclModel::type_QUERY,
            AclModel::type_TASK,
            AclModel::type_OPERATION,
        ];

        $applyFilters = static function (
            AclModel $model,
            string $search,
            string $typeFilter,
            string $moduleFilter,
            array $tagFilters,
            bool $applyType
        ): void {
            if ($search !== '') {
                $connector = $model->getConnection()->getConnector();
                $quotedFields = \array_map(
                    static fn(string $f): string => $connector->quoteIdentifier($f),
                    $model->getModelFields()
                );
                $model->where('CONCAT(' . \implode(',', $quotedFields) . ')', '%' . $search . '%', 'like');
            }
            if ($applyType && $typeFilter !== '') {
                $model->where(AclModel::schema_fields_TYPE, $typeFilter);
            }
            if ($moduleFilter !== '') {
                $model->where(AclModel::schema_fields_MODULE, $moduleFilter);
            }
            if ($tagFilters === []) {
                return;
            }
            /** @var AclModel $probe */
            $probe = ObjectManager::getInstance(AclModel::class, [], false);
            $probe->reset();
            if ($search !== '') {
                $connector = $probe->getConnection()->getConnector();
                $quotedFields = \array_map(
                    static fn(string $f): string => $connector->quoteIdentifier($f),
                    $probe->getModelFields()
                );
                $probe->where('CONCAT(' . \implode(',', $quotedFields) . ')', '%' . $search . '%', 'like');
            }
            if ($applyType && $typeFilter !== '') {
                $probe->where(AclModel::schema_fields_TYPE, $typeFilter);
            }
            if ($moduleFilter !== '') {
                $probe->where(AclModel::schema_fields_MODULE, $moduleFilter);
            }
            $matchedIds = [];
            foreach (
                $probe->fields([
                    AclModel::schema_fields_SOURCE_ID,
                    AclModel::schema_fields_RESOURCE_METADATA,
                ])->select()->fetchArray() as $row
            ) {
                $sourceId = (string)($row[AclModel::schema_fields_SOURCE_ID] ?? '');
                if ($sourceId === '') {
                    continue;
                }
                $resourceTags = AclResourcePresentation::tagsFromSourceId(
                    $sourceId,
                    (string)($row[AclModel::schema_fields_RESOURCE_METADATA] ?? ''),
                );
                if (AclResourcePresentation::resourceMatchesTagFilter($resourceTags, $tagFilters)) {
                    $matchedIds[] = $sourceId;
                }
            }
            if ($matchedIds === []) {
                $model->where(AclModel::schema_fields_SOURCE_ID, ['__acl_tag_filter_none__'], 'IN');
            } else {
                $model->where(AclModel::schema_fields_SOURCE_ID, $matchedIds, 'IN');
            }
        };

        // 分类型统计：在当前维度筛选下（不含 type）
        /** @var AclModel $typeStatModel */
        $typeStatModel = ObjectManager::getInstance(AclModel::class, [], false);
        $typeStatModel->reset();
        $applyFilters($typeStatModel, $search, $typeFilter, $moduleFilter, $tagFilters, false);
        $typeFacetRows = $typeStatModel->fields([
            AclModel::schema_fields_SOURCE_ID,
            AclModel::schema_fields_TYPE,
            AclModel::schema_fields_MODULE,
        ])->select()->fetchArray();
        $typeStats = \array_fill_keys($filterTypes, 0);
        $facetModuleStats = [];
        foreach ($typeFacetRows as $row) {
            $type = (string)($row[AclModel::schema_fields_TYPE] ?? '');
            if ($type === '') {
                $type = 'other';
            }
            $typeStats[$type] = ($typeStats[$type] ?? 0) + 1;
            $module = (string)($row[AclModel::schema_fields_MODULE] ?? '');
            if ($module === '') {
                $module = 'Unknown';
            }
            $facetModuleStats[$module] = ($facetModuleStats[$module] ?? 0) + 1;
        }

        // 列表结果统计：含当前 type
        /** @var AclModel $resultStatModel */
        $resultStatModel = ObjectManager::getInstance(AclModel::class, [], false);
        $resultStatModel->reset();
        $applyFilters($resultStatModel, $search, $typeFilter, $moduleFilter, $tagFilters, true);
        $resultRows = $resultStatModel->fields([
            AclModel::schema_fields_SOURCE_ID,
            AclModel::schema_fields_TYPE,
            AclModel::schema_fields_MODULE,
        ])->select()->fetchArray();
        $resultModuleStats = [];
        foreach ($resultRows as $row) {
            $module = (string)($row[AclModel::schema_fields_MODULE] ?? '');
            if ($module === '') {
                $module = 'Unknown';
            }
            $resultModuleStats[$module] = ($resultModuleStats[$module] ?? 0) + 1;
        }

        $aclModel->reset();
        $applyFilters($aclModel, $search, $typeFilter, $moduleFilter, $tagFilters, true);
        $aclModel->pagination()->select()->fetch();
        $this->assign('acls', $aclModel->getItems());
        unset($aclModel->pagination['html']);
        $this->assign('pagination', $aclModel->getPagination('pagination-rounded', $listBaseRoute, true));
        $this->assign('filter_types', $filterTypes);
        $this->assign('current_type', $typeFilter);
        $this->assign('current_module', $moduleFilter);
        $this->assign('current_tags', $tagFilters);
        $this->assign('current_tags_value', \implode(',', $tagFilters));
        $this->assign('stat_total', \count($resultRows));
        $this->assign('stat_module_count', \count($resultModuleStats));
        $this->assign('stat_type_count', \count(\array_filter($typeStats, static fn(int $n): bool => $n > 0)));
        $this->assign('type_stats', $typeStats);
        $this->assign('module_stats', $resultModuleStats);
        $this->assign('facet_module_count', \count($facetModuleStats));
        $this->assign('list_dimension', $dimension);
        $this->assign('list_base_route', $listBaseRoute);

        if ($dimension === 'tag') {
            $allRows = ObjectManager::getInstance(AclModel::class, [], false)
                ->reset()
                ->fields([
                    AclModel::schema_fields_SOURCE_ID,
                    AclModel::schema_fields_RESOURCE_METADATA,
                ])
                ->select()
                ->fetchArray();
            $metaRows = ObjectManager::getInstance(AclTag::class, [], false)->reset()->select()->fetchArray();
            $metaByTag = [];
            foreach ($metaRows as $meta) {
                $metaByTag[(string)($meta[AclTag::schema_fields_TAG] ?? '')] = $meta;
            }
            $tagOptions = AclResourcePresentation::buildTagSelectOptions($allRows, $metaByTag);
            $this->assign('acl_tag_filter_options', $tagOptions);
            $this->assign(
                'acl_tag_filter_options_json',
                \json_encode($tagOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]'
            );
        } else {
            $this->assign('acl_tag_filter_options', []);
            $this->assign('acl_tag_filter_options_json', '[]');
        }

        return $this->fetch('index');
    }
}
