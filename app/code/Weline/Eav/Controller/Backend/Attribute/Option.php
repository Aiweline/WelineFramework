<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/3/24 22:44:09
 */

namespace Weline\Eav\Controller\Backend\Attribute;

use Weline\Eav\Model\EavAttribute;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;

class
Option extends BackendController
{
    /**
     * @var \Weline\Eav\Model\EavAttribute\Option
     */
    private EavAttribute\Option $option;

    function __construct(EavAttribute\Option $option)
    {
        $this->option = $option;
    }

    public function search()
    {
        $field          = $this->request->getGet('field');
        $limit          = $this->request->getGet('limit');
        $entity_id    = $this->request->getGet('entity_id');
        $attribute_id = $this->request->getGet('attribute_id');
        $search         = $this->request->getGet('search');
        $json           = ['items'  => [], 'entity_id' => $entity_id, 'attribute_id' => $attribute_id, 'limit' => $limit,
                           'search' => $search];
        if (empty($entity_id)) {
            $json['msg'] = __('请先选择实体后操作！');
            return $this->fetchJson($json);
        }
        if (empty($attribute_id)) {
            $json['msg'] = __('请先选择属性后操作！');
            return $this->fetchJson($json);
        }
        $this->option->where('entity_id', $entity_id)
                     ->where('attribute_id', $attribute_id);
        if ($field && $search) {
            $this->option->where($field, $search);
            if ($limit) {
                $this->option->limit(1);
            } else {
                $this->option->limit(100);
            }
        } else if (empty($field) && $search) {
            $this->option->where('concat(`code`,`name`)', "%{$search}%", 'like');
            return $this->fetchJson($json);
        }
        $attributes    = $this->option->select()
                                      ->fetchOrigin();
        $json['items'] = $attributes;
        return $this->fetchJson($json);
    }

    // FIXME 持续完成配置项
    function getForm()
    {

    }

    function postForm()
    {
        // 检测属性
        $attribute = ObjectManager::getInstance(EavAttribute::class)->load('code', $this->request->getPost('attribute'));
        if (!$attribute->getId()) {
            $this->getMessageManager()->addWarning(__('属性已不存在！'));
            $this->redirect('*/backend/attribute/option');
        }
        /**@var \Weline\Eav\Model\EavAttribute\Option $optionModel */
        $optionModel = ObjectManager::getInstance(EavAttribute\Option::class);
        try {
            $result = $optionModel->setData($this->request->getPost())
                                  ->forceCheck(true,
                                               [EavAttribute\Option::fields_entity_id, EavAttribute\Option::fields_attribute_id,
                                                EavAttribute\Option::fields_CODE,]
                                  )->save();
            $this->getMessageManager()->addSuccess(__('添加配置项成功！'));
        } catch (\Exception $exception) {
            $this->getMessageManager()->addWarning(__('添加配置项失败！'));
            if (DEV) {
                $this->getMessageManager()->addException($exception);
            }
            $this->session->setData('eav_attribute_option', $this->request->getPost());
        }
    }

    function postAdd()
    {
        $json = ['code' => 0,];
        // 检测属性
        $attribute = ObjectManager::getInstance(EavAttribute::class)->load('code', $this->request->getPost('attribute_id'));
        if (!$attribute->getId()) {
            $json['msg'] = __('属性已不存在！');
            return $this->fetchJson($json);
        }
        /**@var \Weline\Eav\Model\EavAttribute\Option $optionModel */
        $optionModel = ObjectManager::getInstance(EavAttribute\Option::class);
        try {
            $result       = $optionModel->setData($this->request->getPost())
                                        ->forceCheck(true,
                                                     [EavAttribute\Option::fields_entity_id, EavAttribute\Option::fields_attribute_id, EavAttribute\Option::fields_entity_id]
                                        )->save();
            $json['code'] = 1;
            $json['msg']  = __('添加配置项成功！');
        } catch (\Exception $exception) {
            $json['msg'] = $exception->getMessage();
            $this->session->setData('eav_attribute_option', $this->request->getPost());
        }
        return $this->fetchJson($json);
    }

    function postDelete()
    {
        $json = ['code' => 0, 'msg' => ''];
        $code = $this->request->getPost('code');
        if (empty($code)) {
            $json['msg'] = __('请选择要操作的配置项！');
            return $this->fetchJson($json);
        }
        $option = $this->option->where([
                                           'code'           => $code,
                                           'attribute_id' => $this->request->getPost('attribute_id'),
                                           'entity_id'    => $this->request->getPost('entity_id'),
                                       ])->find()->fetch();
        if (!$option->getId()) {
            $json['msg'] = __('配置项不存在！');
            return $this->fetchJson($json);
        }
        try {
            $option->delete();
            $json['code'] = 1;
            $json['msg']  = __('操作成功！');
        } catch (\Exception $exception) {
            $json['msg'] = $exception->getMessage();
        }
        return $this->fetchJson($json);
    }

    function postEdit()
    {
        $json = ['code' => 0,'msg' => ''];
        $code = $this->request->getPost('origin_code');
        if (empty($code)) {
            $json['msg'] = __('请选择要操作的配置项！');
            return $this->fetchJson($json);
        }
        $option = $this->option->where([
                                           'code'           => $code,
                                           'attribute_id' => $this->request->getPost('attribute_id'),
                                           'entity_id'    => $this->request->getPost('entity_id'),
                                       ])->find()->fetch();
        if (!$option->getId()) {
            $json['msg'] = __('配置项不存在！');
            return $this->fetchJson($json);
        }
        try {
            $this->option->setData([
                                       'code'           => $this->request->getPost('code'),
                                       'attribute_id' => $this->request->getPost('attribute_id'),
                                       'entity_id'    => $this->request->getPost('entity_id'),
                                       'name'            => $this->request->getPost('name'),
                                   ])->save();
            $json['code'] = 1;
            $json['msg']  = __('操作成功！');
        }catch (\Exception $exception){
            $json['msg'] = DEBUG?$exception->getMessage():__('操作失败，请检查编码或配置！');
        }
        return $this->fetchJson($json);
    }
}