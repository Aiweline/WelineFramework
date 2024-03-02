<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/4/9 15:48:02
 */

namespace Weline\Eav\Controller\Backend\Attribute;

use Weline\Eav\Model\EavAttribute\Set\LocalDescription;
use Weline\Eav\Model\EavEntity;
use Weline\Framework\App\Exception;
use Weline\Framework\Exception\Core;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;

class Group extends \Weline\Framework\App\Controller\BackendController
{
    private \Weline\Eav\Model\EavAttribute\Group $group;

    function __construct(
        \Weline\Eav\Model\EavAttribute\Group $group
    )
    {
        $this->group = $group;
    }

    function index()
    {
        $this->group->addLocalDescription()
            ->joinModel(EavEntity::class, 'entity', 'main_table.entity_id=entity.entity_id', 'left', 'entity.name as entity_name')
            ->joinModel(\Weline\Eav\Model\EavAttribute\Set::class, 'set', 'main_table.set_id=set.set_id', 'left', 'set.name as set_name')
            ->joinModel(EavEntity\LocalDescription::class, 'entity_local', 'main_table.entity_id=entity_local.entity_id and entity_local.local_code=\''.Cookie::getLangLocal().'\'', 'left', 'entity_local.name as entity_local_name')
            ->joinModel(LocalDescription::class, 'set_local', 'main_table.set_id=set_local.set_id and set_local.local_code=\''.Cookie::getLangLocal().'\'', 'left', 'set_local.name as set_local_name');
        if ($search = $this->request->getGet('search')) {
            $this->group->where('concat(local.name,main_table.name,entity.name,entity.code)', "%$search%", 'like');
        }
        $groups = $this->group->pagination()->select()->fetch()->getItems();
        $this->assign('groups', $groups);
        $this->assign('columns', $this->group->columns());
        $this->assign('pagination', $this->group->getPagination());
        return $this->fetch();
    }

    function getSearch(): string
    {
        $field       = $this->request->getGet('field');
        $limit       = $this->request->getGet('limit');
        $entity_id = $this->request->getGet('entity_id');
        $set_id    = $this->request->getGet('set_id');
        $search      = $this->request->getGet('search');
        $json        = ['items'  => [], 'entity_id' => $entity_id, 'set_id' => $set_id, 'limit' => $limit,
            'search' => $search];
        if (empty($entity_id)) {
            $json['msg'] = __('请先选择实体后操作！');
            return $this->fetchJson($json);
        }
        if (empty($set_id)) {
            $json['msg'] = __('请先选择属性后操作！');
            return $this->fetchJson($json);
        }
        $this->group->where('entity_id', $entity_id)
            ->where('set_id', $set_id);
        if ($field && $search) {
            $this->group->where($field, $search);
            if ($limit) {
                $this->group->limit(1);
            } else {
                $this->group->limit(100);
            }
        } else if (empty($field) && $search) {
            $this->group->where('concat(`code`,`name`)', "%{$search}%", 'like');
            return $this->fetchJson($json);
        }
        $attributes    = $this->group->select()
            ->fetchOrigin();
        $json['items'] = $attributes;
        return $this->fetchJson($json);
    }

    function add()
    {
        $this->assign('title','新增分组-属性组管理');
        if ($this->request->isPost()) {
            try {
                $this->validatePost();
                $group_id = $this->group->setData($this->request->getPost())->save();
                $this->getMessageManager()->addSuccess(__('添加成功！'));
                $this->session->delete('eav_group');
                $this->redirect($this->_url->getBackendUrl('*/backend/attribute/group/edit', [
                    'group_id'        => $group_id
                ]));
            } catch (\Exception $exception) {
                $this->getMessageManager()->addWarning(__('添加异常！信息可能已经存在！'));
                $this->session->setData('eav_group', $this->request->getPost());
                if (DEBUG || DEV) $this->getMessageManager()->addException($exception);
            }
        }
        $this->init_form();
        return $this->fetch('form');
    }

    function edit()
    {
        $this->assign('title','编辑分组-属性组管理');
        if ($this->request->isPost()) {
            try {
                $this->validatePost();
                $this->group->setData($this->request->getPost())
                    ->forceCheck(true, [$this->group::fields_code, $this->group::fields_entity_id, $this->group::fields_set_id])
                    ->save();
                $this->getMessageManager()->addSuccess(__('修改成功！'));
                $this->session->delete('eav_group');
                $this->redirect($this->_url->getBackendUrl('*/backend/attribute/group/edit', [
                    'group_id'        => $this->request->getPost('group_id')
                ]));
            } catch (\Exception $exception) {
                $this->getMessageManager()->addWarning(__('修改异常！'));
                $this->session->setData('eav_group', $this->request->getPost());
                if (DEBUG || DEV) $this->getMessageManager()->addException($exception);
                $this->redirect($this->_url->getCurrentUrl());
            }
        }
        if($this->request->getGet('group_id')){
            $group = $this->group->load($this->request->getGet('group_id'));
            $this->assign('group',$group);
            $this->assign('group_set',ObjectManager::getInstance(\Weline\Eav\Model\EavAttribute\Set::class)->load($group->getData($group::fields_set_id)));
            $this->assign('group_entity',ObjectManager::getInstance(\Weline\Eav\Model\EavEntity::class)->load($group->getData
            ($group::fields_entity_id)));
        }
        $this->init_form();
        return $this->fetch('form');
    }

    function getDelete()
    {
        // 属性组
        $group_id        = $this->request->getGet('id');
        if ($group_id) {
            $group = $this->group->load($group_id);
            if (!$group->getId()) {
                $this->getMessageManager()->addError(__('属性组已不存在！'));
            } else {
                if($group->hasAttributes()){
                    $this->getMessageManager()->addError(__('该属性组中有属性，不可删除！'));
                    $this->redirect($this->_url->getBackendUrl('*/backend/attribute/group'));
                }
                try {
                    $group->delete();
                    $this->getMessageManager()->addSuccess(__('删除成功！'));
                } catch (\ReflectionException|Core|Exception $e) {
                    if (DEV) $this->getMessageManager()->addException($e);
                    $this->getMessageManager()->addWarning(__('删除失败！'));
                }
            }
        }
        $this->redirect($this->_url->getBackendUrl('*/backend/attribute/group'));
    }

    protected function init_form()
    {
        if ($eav_group = $this->session->getData('eav_group')) {
            $this->assign('group', $eav_group);
        }
        // 属性组
        $code        = $this->request->getGet('code');
        $entity_id = $this->request->getGet('entity_id');
        $set_id    = $this->request->getGet('set_id');
        if ($code && $entity_id && $set_id) {
            $group = $this->group->where('code', $code)
                ->where('set_id', $set_id)
                ->where('entity_id', $entity_id)
                ->find()
                ->fetch();
            $this->assign('group', $group);
        }
        // 实体
        /**@var \Weline\Eav\Model\EavEntity $eavEntityModel */
        $eavEntityModel = ObjectManager::getInstance(EavEntity::class);
        $entities       = $eavEntityModel->select()->fetchOrigin();
        $this->assign('entities', $entities);
        // 链接
        $this->assign('action', $this->_url->getCurrentUrl());
    }

    protected function validatePost(): void
    {
        $code        = $this->request->getPost('code');
        $entity_id = $this->request->getPost('entity_id');
        $set_id    = $this->request->getPost('set_id');
        if (empty($set_id) || empty($code) || empty($entity_id)) {
            $this->getMessageManager()->addWarning(__('参数异常！'));
            $this->session->setData('eav_group', $this->request->getPost());
            $this->redirect($this->_url->getCurrentUrl());
        }
    }
}