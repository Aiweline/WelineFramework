<?php

declare(strict_types=1);

namespace Weline\Acl\Controller\Backend\Acl;

use Weline\Acl\Model\Acl;
use Weline\Acl\Model\AclTag;
use Weline\Acl\Model\RoleTagGrant;
use Weline\Acl\Service\Resource\AclResourcePresentation;
use Weline\Framework\App\Controller\BackendPageController;
use Weline\Framework\Manager\ObjectManager;

#[\Weline\Framework\Acl\Acl('Weline_Acl::acl_tag', 'ACL标签管理', 'mdi mdi-tag-multiple', '管理 ACL source_id 标签元数据')]
class Tag extends BackendPageController
{
    #[\Weline\Framework\Acl\Acl('Weline_Acl::acl_tag_index', '标签列表', '', '')]
    public function getIndex(): string
    {
        /** @var Acl $aclModel */
        $aclModel = ObjectManager::getInstance(Acl::class);
        $rows = $aclModel->reset()
            ->fields([Acl::schema_fields_SOURCE_ID, Acl::schema_fields_RESOURCE_METADATA])
            ->select()
            ->fetchArray();
        $discovered = [];
        foreach ($rows as $row) {
            foreach (AclResourcePresentation::tagsFromSourceId(
                (string)($row[Acl::schema_fields_SOURCE_ID] ?? ''),
                (string)($row[Acl::schema_fields_RESOURCE_METADATA] ?? ''),
            ) as $tag) {
                $discovered[$tag] = ($discovered[$tag] ?? 0) + 1;
            }
        }

        /** @var AclTag $tagModel */
        $tagModel = ObjectManager::getInstance(AclTag::class);
        $metaRows = $tagModel->reset()->order(AclTag::schema_fields_SORT_ORDER, 'ASC')->select()->fetchArray();
        $metaByTag = [];
        foreach ($metaRows as $meta) {
            $metaByTag[(string)$meta[AclTag::schema_fields_TAG]] = $meta;
        }

        $tags = [];
        foreach ($discovered as $tag => $count) {
            $meta = $metaByTag[$tag] ?? [];
            $tags[] = [
                'tag' => $tag,
                'resource_count' => $count,
                'display_name' => (string)($meta[AclTag::schema_fields_DISPLAY_NAME] ?? $tag),
                'description' => (string)($meta[AclTag::schema_fields_DESCRIPTION] ?? ''),
                'color' => (string)($meta[AclTag::schema_fields_COLOR] ?? ''),
                'sort_order' => (int)($meta[AclTag::schema_fields_SORT_ORDER] ?? 0),
                'has_meta' => isset($metaByTag[$tag]),
            ];
        }
        \usort($tags, static fn($a, $b) => ($a['sort_order'] <=> $b['sort_order']) ?: strcmp($a['tag'], $b['tag']));
        $this->assign('tags', $tags);
        return $this->fetch('index');
    }

    #[\Weline\Framework\Acl\Acl('Weline_Acl::acl_tag_save', '保存标签元数据', '', '')]
    public function postSave(): string
    {
        $tag = \trim((string)$this->request->getPost('tag', ''));
        if ($tag === '' || \preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D', $tag) !== 1) {
            $this->getMessageManager()->addError(__('标签词非法'));
            $this->redirect('*/backend/acl/tag');
        }
        /** @var AclTag $tagModel */
        $tagModel = ObjectManager::getInstance(AclTag::class);
        $existing = $tagModel->reset()->where(AclTag::schema_fields_TAG, $tag)->find()->fetch();
        $data = [
            AclTag::schema_fields_TAG => $tag,
            AclTag::schema_fields_DISPLAY_NAME => \trim((string)$this->request->getPost('display_name', $tag)),
            AclTag::schema_fields_DESCRIPTION => \trim((string)$this->request->getPost('description', '')),
            AclTag::schema_fields_COLOR => \trim((string)$this->request->getPost('color', '')),
            AclTag::schema_fields_SORT_ORDER => (int)$this->request->getPost('sort_order', 0),
        ];
        if ((string)$existing->getData(AclTag::schema_fields_TAG) === $tag) {
            foreach ($data as $k => $v) {
                $existing->setData($k, $v);
            }
            $existing->save();
        } else {
            $tagModel->clear()->setData($data)->save();
        }
        $this->getMessageManager()->addSuccess(__('标签元数据已保存'));
        $this->redirect('*/backend/acl/tag');
        return '';
    }

    #[\Weline\Framework\Acl\Acl('Weline_Acl::acl_tag_delete', '删除标签元数据', '', '')]
    public function postDelete(): string
    {
        $tag = \trim((string)$this->request->getPost('tag', ''));
        /** @var Acl $aclModel */
        $aclModel = ObjectManager::getInstance(Acl::class);
        $inUse = false;
        foreach ($aclModel->reset()->fields([Acl::schema_fields_SOURCE_ID, Acl::schema_fields_RESOURCE_METADATA])->select()->fetchArray() as $row) {
            if (\in_array($tag, AclResourcePresentation::tagsFromSourceId(
                (string)$row[Acl::schema_fields_SOURCE_ID],
                (string)($row[Acl::schema_fields_RESOURCE_METADATA] ?? ''),
            ), true)) {
                $inUse = true;
                break;
            }
        }
        /** @var RoleTagGrant $grantModel */
        $grantModel = ObjectManager::getInstance(RoleTagGrant::class);
        $grantCount = \count($grantModel->reset()->where(RoleTagGrant::schema_fields_TAG_PATH, $tag . '%', 'like')->select()->fetchArray());
        // Also exact path match
        $grantExact = $grantModel->reset()->where(RoleTagGrant::schema_fields_TAG_PATH, $tag)->find()->fetch();
        if ($inUse || (string)$grantExact->getData(RoleTagGrant::schema_fields_TAG_PATH) === $tag || $grantCount > 0) {
            $this->getMessageManager()->addError(__('标签仍被资源或角色订阅引用，不能删除元数据'));
            $this->redirect('*/backend/acl/tag');
        }
        ObjectManager::getInstance(AclTag::class)->reset()->where(AclTag::schema_fields_TAG, $tag)->delete()->fetch();
        $this->getMessageManager()->addSuccess(__('标签元数据已删除'));
        $this->redirect('*/backend/acl/tag');
        return '';
    }
}
