<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/4/9 15:48:17
 */

namespace Weline\Eav\Controller\Backend\Attribute;

use Gvanda\Store\Model\Store;
use Weline\Eav\Model\EavEntity;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;

class Set extends \Weline\Framework\App\Controller\BackendController
{
    private \Weline\Eav\Model\EavAttribute\Set $set;

    function __construct(
        \Weline\Eav\Model\EavAttribute\Set $set
    )
    {
        $this->set = $set;
    }
    function index()
    {
        $this->set->addLocalDescription()
        ->joinModel(EavEntity::class, 'entity', 'main_table.eav_entity_id=entity.eav_entity_id', 'left', 'entity.name as entity_name')
        ->joinModel(EavEntity\LocalDescription::class, 'entity_local', 'main_table.eav_entity_id=entity_local.eav_entity_id and entity_local.local_code=\''.Cookie::getLangLocal().'\'', 'left', 'entity_local.name as entity_local_name');
    
        if ($search = $this->request->getGet('search')) {
            $this->set->where('concat(local.name,main_table.name,entity.name,entity.code)', "%$search%", 'like');
        }
        $groups = $this->set->pagination()->select()->fetch()->getItems();
        $this->assign('sets', $groups);
        $this->assign('columns', $this->set->columns());
        $this->assign('pagination', $this->set->getPagination());
        return $this->fetch();
    }


    function add()
    {
        if ($this->request->isPost()) {
            try {
                $set_id = $this->set->setData($this->request->getPost())
                                    ->save();
                $this->session->delete('eav_set');
                if ($this->request->getGet('isAjax')) {
                    return $this->fetchJson(array('id' => $set_id, 'msg' => __('添加成功！')));
                }
                $this->getMessageManager()->addSuccess(__('添加成功！'));
                $this->redirect($this->_url->getBackendUrl('*/backend/attribute/set/edit',
                                                           [
                                                               'set_id' => $set_id,
                                                           ]));
            } catch (\Exception $exception) {
                if ($this->request->getGet('isAjax')) {
                    return $this->fetchJson(array('msg' => __('添加异常！可能已存在该属性集！')));
                }
                $this->getMessageManager()->addWarning(__('添加异常！可能已存在该属性集！'));
                $this->session->setData('eav_set', $this->request->getPost());
                if (DEBUG || DEV) $this->getMessageManager()->addException($exception);
                $this->redirect($this->_url->getCurrentUrl());
            }
        }
        if ($set = $this->session->getData('eav_set')) {
            $this->assign('set', $set);
        }
        $this->init_form();
        return $this->fetch('form');
    }

    function edit()
    {
        if ($this->request->isPost()) {
            try {
                $this->set->setModelFieldsData($this->request->getPost())
                          ->save();
                $this->session->delete('eav_set');
                // 如果是ajax
                if ($this->request->getGet('isAjax')) {
                    return $this->fetchJson(array('id' => $this->set->getId()));
                }
                $this->getMessageManager()->addSuccess(__('修改成功！'));
            } catch (\Exception $exception) {
                // 返回is ajax
                if ($this->request->getGet('isAjax')) {
                    return $this->fetchJson(array('msg' => __('编辑异常！'), 'exception' => $exception->getMessage()));
                }
                $this->getMessageManager()->addWarning(__('修改异常！'));
                $this->session->setData('eav_set', $this->request->getPost());
                if (DEBUG || DEV) $this->getMessageManager()->addException($exception);
            }
            $this->redirect($this->_url->getBackendUrl('*/backend/attribute/set/edit', ['set_id' => $this->request->getPost('set_id')]));
        }
        $this->init_form();
        return $this->fetch('form');
    }

    function getDelete()
    {
        if ($id = $this->request->getGet('id')) {
            $this->set->load($id);
            if (!$this->set->getId()) {
                $this->getMessageManager()->addWarning(__('属性集不存在！'));
                $this->redirect($this->_url->getBackendUrl('*/backend/attribute/set'));
            }
            # 检测属性集下是否有属性
            if ($this->set->hasAttributes()) {
                $this->getMessageManager()->addWarning(__('属性集内还有属性！不能删除！'));
                $this->redirect($this->_url->getBackendUrl('*/backend/attribute/set'));
            }
            # 检测属性集下是否有属性组
            if ($this->set->hasAttributes()) {
                $this->getMessageManager()->addWarning(__('属性集内还有属性组！不能删除！'));
                $this->redirect($this->_url->getBackendUrl('*/backend/attribute/set'));
            }
            $this->set->delete()->fetch();
            $this->getMessageManager()->addSuccess(__('删除成功！'));
        } else {
            $this->getMessageManager()->addError(__('找不到要操作的代码！'));
        }
        $this->redirect($this->_url->getBackendUrl('*/backend/attribute/set'));
    }

    function getApiSearch(): string
    {
        $eav_entity_id = $this->request->getGet('eav_entity_id');
        $json      = ['items' => [], 'eav_entity_id' => $eav_entity_id];
        if (empty($eav_entity_id)) {
            return $this->fetchJson($json);
        }
        $sets          = $this->set->where('eav_entity_id', $eav_entity_id)
                                   ->select()
                                   ->fetchArray();
        $json['items'] = $sets;
        return $this->fetchJson($json);
    }

    function getSearch(): string
    {
        $eav_entity_id = $this->request->getGet('eav_entity_id');
        $search    = $this->request->getGet('search');
        $json      = ['items' => [], 'eav_entity_id' => $eav_entity_id, 'search' => $search];
        if (empty($eav_entity_id)) {
            $json['msg'] = __('请先选择实体后操作！');
            return $this->fetchJson($json);
        }
        $this->set->where('eav_entity_id', $eav_entity_id);
        if ($search) {
            $this->set->where('concat(name,code) like \'%' . $search . '%\'');
        }
        $sets          = $this->set->select()
                                   ->fetchArray();
        $json['items'] = $sets;
        return $this->fetchJson($json);
    }

    protected function validatePost(): void
    {
        $code      = $this->request->getPost('code');
        $eav_entity_id = $this->request->getPost('eav_entity_id');
        if (empty($code) || empty($eav_entity_id)) {
            $this->getMessageManager()->addWarning(__('参数异常！'));
            $this->session->setData('eav_set', $this->request->getPost());
            $this->redirect($this->_url->getCurrentUrl());
        }
    }

    protected function init_form()
    {
        // 属性集
        if ($set_id = $this->request->getGet('set_id')) {
            $set = $this->set->load($set_id);
            $this->assign('set', $set);
        } elseif ($set = $this->session->getData('eav_set')) {
            $this->assign('set', $set);
        }

        // 实体
        /**@var \Weline\Eav\Model\EavEntity $eavEntityModel */
        $eavEntityModel = ObjectManager::getInstance(EavEntity::class);
        $entities       = $eavEntityModel->reset()->select()->fetchArray();
        $this->assign('entities', $entities);
        // 链接
        $this->assign('action', $this->_url->getCurrentUrl());
    }
}