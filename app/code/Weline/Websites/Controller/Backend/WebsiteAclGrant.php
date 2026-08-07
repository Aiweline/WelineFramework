<?php

declare(strict_types=1);

namespace Weline\Websites\Controller\Backend;

use Weline\Acl\Model\Acl;
use Weline\Acl\Model\Role;
use Weline\Acl\Service\Resource\AclResourcePresentation;
use Weline\Acl\Service\ResourceTreeService;
use Weline\Framework\Acl\Acl as AclAttr;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Website;
use Weline\Websites\Service\WebsiteAclGrantService;

#[AclAttr('Weline_Websites::website_acl_grant', '网站功能授权', 'mdi mdi-shield-key-outline', '为子站授权可用菜单与 ACL', 'Weline_Websites::website_service')]
class WebsiteAclGrant extends BackendController
{
    public function __construct(
        private readonly WebsiteAclGrantService $grantService,
        private readonly Website $websiteModel,
    ) {
    }

    #[AclAttr('Weline_Websites::website_acl_grant_index', '网站功能授权列表', 'mdi mdi-shield-key-outline', '选择网站授权')]
    public function getIndex()
    {
        if (!$this->grantService->isDefaultWebsite()) {
            $this->getMessageManager()->addError(__('仅默认站后台可管理网站功能授权'));
            $this->redirect('*/admin/website/index');
        }

        $allWebsites = $this->websiteModel->clearQuery()
            ->order(Website::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();
        $websites = [];
        foreach ($allWebsites as $row) {
            if ((int)($row[Website::schema_fields_ID] ?? -1) !== Website::ID_DEFAULT) {
                $websites[] = $row;
            }
        }

        $rows = [];
        foreach ($websites as $row) {
            $websiteId = (int)($row[Website::schema_fields_ID] ?? 0);
            $rows[] = [
                'website_id' => $websiteId,
                'name' => (string)($row[Website::schema_fields_NAME] ?? ''),
                'code' => (string)($row[Website::schema_fields_CODE] ?? ''),
                'grant_count' => \count($this->grantService->getGrantedSourceIds($websiteId)),
            ];
        }
        $this->assign('websites', $rows);
        return $this->fetch('index');
    }

    #[AclAttr('Weline_Websites::website_acl_grant_edit', '编辑网站功能授权', 'mdi mdi-shield-edit-outline', '勾选子站授权包')]
    public function getEdit()
    {
        if (!$this->grantService->isDefaultWebsite()) {
            $this->getMessageManager()->addError(__('仅默认站后台可管理网站功能授权'));
            $this->redirect('*/backend/website-acl-grant/index');
        }

        $websiteId = (int)$this->request->getGet('website_id', 0);
        if ($websiteId <= Website::ID_DEFAULT) {
            $this->getMessageManager()->addError(__('请选择非默认网站'));
            $this->redirect('*/backend/website-acl-grant/index');
        }

        $website = ObjectManager::getInstance(Website::class, [], false)->load($websiteId);
        if ((int)$website->getId() !== $websiteId) {
            $this->getMessageManager()->addError(__('网站不存在'));
            $this->redirect('*/backend/website-acl-grant/index');
        }

        /** @var Role $probeRole */
        $probeRole = ObjectManager::getInstance(Role::class, [], false);
        $probeRole->setData(Role::schema_fields_ROLE_ID, 0);
        $probeRole->setData(Role::schema_fields_WEBSITE_ID, Website::ID_DEFAULT);

        /** @var ResourceTreeService $treeService */
        $treeService = ObjectManager::getInstance(ResourceTreeService::class);
        // Force platform (default) context for full tree: observer skips filter when website_id=0
        $trees = $treeService->getAclAssignmentTree($probeRole);

        $selected = \array_fill_keys($this->grantService->getGrantedSourceIds($websiteId), true);
        foreach ($trees as $tree) {
            $this->applyGrantSelectionToTree($tree, $selected);
        }

        /** @var Acl $aclModel */
        $aclModel = ObjectManager::getInstance(Acl::class, [], false);
        $allRows = $aclModel->reset()->select()->fetchArray();
        $tagTree = AclResourcePresentation::buildTagTree($allRows, $selected);

        $statistics = [];
        $moduleSet = [];
        $typeSet = [];
        foreach ($trees as $tree) {
            $sid = (string)$tree->getSourceId();
            $stats = $this->countNodeStats($tree, $moduleSet, $typeSet);
            $statistics[$sid] = [
                'source_id' => $sid,
                'source_name' => $tree->getSourceName(),
                'module' => \explode('::', $sid)[0] ?? $sid,
                'type' => $tree->getType(),
                'total' => $stats['total'],
                'selected' => $stats['selected'],
            ];
        }
        foreach ($allRows as $row) {
            $rowType = (string)($row['type'] ?? '');
            if (\in_array($rowType, ['query', 'task', 'operation'], true)) {
                $typeSet[$rowType] = true;
                $rowModule = (string)($row['module'] ?? (\explode('::', (string)($row['source_id'] ?? ''))[0] ?? ''));
                if ($rowModule !== '') {
                    $moduleSet[$rowModule] = true;
                }
            }
        }

        $this->assign('website', $website->getData());
        $this->assign('website_id', $websiteId);
        $this->assign('trees', $trees);
        $this->assign('tag_tree', $tagTree);
        $this->assign('selected_source_ids', \array_keys($selected));
        $this->assign('tag_path_leaves', AclResourcePresentation::buildTagPathLeaves($allRows));
        $this->assign('tag_grants', []);
        $this->assign('tree_statistics', $statistics);
        $this->assign('module_list', \array_keys($moduleSet));
        $this->assign('type_list', \array_keys($typeSet));
        $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl('*/backend/website-acl-grant/save'));
        return $this->fetch('edit');
    }

    /**
     * Mark tree nodes selected according to website grant package (reuses role_id flag for jstree).
     */
    private function applyGrantSelectionToTree(object $node, array $selected): void
    {
        $sid = (string)$node->getSourceId();
        $node->setData('role_id', isset($selected[$sid]) ? true : null);
        foreach ($node->getSub() ?: [] as $sub) {
            $this->applyGrantSelectionToTree($sub, $selected);
        }
    }

    /**
     * Recursively count nodes and collect module/type filters (same as Role::getAssign).
     *
     * @return array{total:int,selected:int}
     */
    private function countNodeStats(object $node, array &$moduleSet, array &$typeSet): array
    {
        $total = 1;
        $selected = $node->getData('role_id') ? 1 : 0;

        $module = $node->getModule();
        $type = $node->getType();
        if ($module !== '' && $module !== null) {
            $moduleSet[$module] = true;
        }
        if ($type !== '' && $type !== null) {
            $typeSet[$type] = true;
        }

        foreach ($node->getSub() ?: [] as $sub) {
            $subStats = $this->countNodeStats($sub, $moduleSet, $typeSet);
            $total += $subStats['total'];
            $selected += $subStats['selected'];
        }

        return ['total' => $total, 'selected' => $selected];
    }

    #[AclAttr('Weline_Websites::website_acl_grant_save', '保存网站功能授权', 'mdi mdi-content-save', '保存子站授权包')]
    public function postSave()
    {
        if (!$this->grantService->isDefaultWebsite()) {
            $this->getMessageManager()->addError(__('仅默认站后台可管理网站功能授权'));
            $this->redirect('*/backend/website-acl-grant/index');
        }

        $websiteId = (int)$this->request->getPost('website_id', 0);
        if ($websiteId <= Website::ID_DEFAULT) {
            $this->getMessageManager()->addError(__('请选择非默认网站'));
            $this->redirect('*/backend/website-acl-grant/index');
        }

        $aclIds = $this->request->getPost('ids', []);
        if (!\is_array($aclIds)) {
            $aclIds = [];
        }
        $tagPaths = $this->request->getPost('tag_paths', []);
        if (!\is_array($tagPaths)) {
            $tagPaths = [];
        }

        /** @var Acl $aclModel */
        $aclModel = ObjectManager::getInstance(Acl::class, [], false);
        $allRows = $aclModel->reset()->select()->fetchArray();
        $byTagPath = [];
        foreach ($allRows as $row) {
            $sourceId = (string)($row[Acl::schema_fields_SOURCE_ID] ?? '');
            $tags = AclResourcePresentation::tagsFromSourceId(
                $sourceId,
                (string)($row[Acl::schema_fields_RESOURCE_METADATA] ?? ''),
            );
            for ($i = 1, $n = \count($tags); $i <= $n; ++$i) {
                $path = \implode(':', \array_slice($tags, 0, $i));
                $byTagPath[$path][$sourceId] = true;
            }
        }

        $leafSet = [];
        foreach ($aclIds as $aclId) {
            $aclId = \trim((string)$aclId);
            if ($aclId !== '' && !\str_starts_with($aclId, 'tag:')) {
                $leafSet[$aclId] = true;
            }
        }
        foreach ($tagPaths as $tagPath) {
            $tagPath = \trim((string)$tagPath);
            if ($tagPath === '') {
                continue;
            }
            foreach (\array_keys($byTagPath[$tagPath] ?? []) as $sourceId) {
                $leafSet[$sourceId] = true;
            }
        }
        $finalIds = AclResourcePresentation::expandMenusAncestors(\array_keys($leafSet), $allRows);

        try {
            $this->grantService->replaceGrants($websiteId, $finalIds);
            w_cache('acl')->clear();
            $this->getMessageManager()->addSuccess(__('网站功能授权已保存'));
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError($e->getMessage());
        }

        $this->redirect('*/backend/website-acl-grant/edit', ['website_id' => $websiteId]);
    }
}
