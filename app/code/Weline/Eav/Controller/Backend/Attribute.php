<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/3/22 21:40:54
 */

namespace Weline\Eav\Controller\Backend;

use Weline\Backend\Model\BackendUserData;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Group;
use Weline\Eav\Model\EavAttribute\Type;
use Weline\Eav\Model\EavEntity;
use Weline\Eav\Model\EavAttributePreCreate;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;

class Attribute extends \Weline\Framework\App\Controller\BackendController
{
    public const        eav_entity = 'eav_entity';
    public const        eav_entity_attribute_set = 'eav_entity_attribute_set';
    public const        eav_entity_attribute_set_group = 'eav_entity_attribute_set_group';
    public const        eav_attribute = 'attribute';

    /**
     * @var \Weline\Eav\Model\EavAttribute
     */
    private EavAttribute $eavAttribute;
    private EavAttributePreCreate $preCreateAttribute;
    /**
     * @var \Weline\Backend\Model\BackendUserData
     */
    private BackendUserData $backendUserData;

    public function __construct(BackendUserData $backendUserData, EavAttribute $eavAttribute, EavAttributePreCreate $preCreateAttribute)
    {
        $this->eavAttribute = $eavAttribute;
        $this->preCreateAttribute = $preCreateAttribute;
        $this->backendUserData = $backendUserData;
    }

    public function index()
    {
        $this->eavAttribute->addLocalDescription()
            ->joinModel(EavEntity::class, 'entity', 'main_table.entity_id=entity.entity_id', 'left', 'entity.name as entity_name')
            ->joinModel(EavEntity\LocalDescription::class, 'entity_local', 'main_table.entity_id=entity_local.entity_id and entity_local.local_code=\'' . Cookie::getLangLocal() . '\'', 'left', 'entity_local.name as entity_local_name');
        $this->eavAttribute->joinModel(Type::class, 'type');
        if ($search = $this->request->getGet('search')) {
            $this->eavAttribute->where('concat(main_table.name,main_table.code,type.name,type.code,local.name,entity.name,entity.code,entity_local.name)', "%$search%", 'like');
        }
        if ($entity = $this->request->getGet('entity')) {
            $this->eavAttribute->where('entity_id', $entity);
        }
        // p($this->eavAttribute->select()->getLastSql());
        $attributes = $this->eavAttribute->order('main_table.update_time')->pagination()->select()->fetchOrigin();
        $this->assign('attributes', $attributes);
        $this->assign('pagination', $this->eavAttribute->getPagination());
        return $this->fetch();
    }

    public function getAttributeIdByCode(): string
    {
        $code = (string)$this->request->getPost('code');
        $this->preCreateAttribute->load($this->preCreateAttribute::fields_code, $code);
        return $this->fetchJson($this->preCreateAttribute->getId());
    }

    public function getTypeData()
    {
        $type_id = $this->request->getGet('type_id');
        $type = ObjectManager::getInstance(Type::class)->load($type_id);
        return $this->fetchJson($type->getData());
    }

    public function getSearch(): string
    {
        $field = $this->request->getGet('field');
        $limit = $this->request->getGet('limit');
        $entity_id = $this->request->getGet('entity_id');
        $set_id = $this->request->getGet('set_id');
        $group_id = $this->request->getGet('group_id');
        $search = $this->request->getGet('search');
        $json = ['items' => [], 'entity_id' => $entity_id, 'set_id' => $set_id, 'group_id' => $group_id, 'limit' => $limit, 'search' => $search];
        if (empty($entity_id)) {
            $json['msg'] = __('请先选择实体后操作！');
            return $this->fetchJson($json);
        }
        if (empty($set_id)) {
            $json['msg'] = __('请先选择属性集后操作！');
            return $this->fetchJson($json);
        }
        if (empty($group_id)) {
            $json['msg'] = __('请先选择属性组后操作！');
            return $this->fetchJson($json);
        }
        $this->eavAttribute->where('entity_id', $entity_id)
            ->where('set_id', $set_id)
            ->where('group_id', $group_id);
        if ($field && $search) {
            $this->eavAttribute->where('main_table.' . $field, $search);
            if ($limit) {
                $this->eavAttribute->limit(1);
            } else {
                $this->eavAttribute->limit(100);
            }
        } elseif (empty($field) && $search) {
            $this->eavAttribute->where('concat(`attribute`,main_table.`name`,`entity`,`option`)', "%{$search}%", 'like');
            return $this->fetchJson($json);
        }
        $attributes = $this->eavAttribute->select()->fetchOrigin();
        $json['items'] = $attributes;
        return $this->fetchJson($json);
    }

    public function add_old(int $attribute_id = 0)
    {
        if ($attribute_id) {
            $this->preCreateAttribute->where('attribute_id', $attribute_id)->find()->fetch();
            $attribute = $this->eavAttribute->loadByAttributeId($attribute_id);
            $attribute_data = $attribute->getData();
            $attribute_data['user_id'] = $this->session->getLoginUserId();
            $this->preCreateAttribute->setModelFieldsData($attribute_data)->save(true);
            $json['msg'] = __('操作成功！');
            // 回填选项数据
            if ($attribute->getId() && ($attribute->hasOption()) && $this->preCreateAttribute->getOptions() == []) {
                $attribute_options = $attribute->getOptions();
                $attribute_data['options'] = json_encode($attribute_options);
                $this->preCreateAttribute->setModelFieldsData($attribute_data)->save(true);
                $this->assign('eav_attribute_options', $attribute_options);
            }
            if (!$this->preCreateAttribute->getId()) {
                /**@var EavAttributePreCreate $unfinished_attribute */
                $this->preCreateAttribute->setModelFieldsData($attribute_data)->save(true);
            }
        }
        // 查找是否有未完成的预创建项目
        /**@var EavAttributePreCreate $unfinished_attribute */
        $unfinished_attribute = clone $this->preCreateAttribute
            ->where($this->preCreateAttribute::fields_user_id, $this->session->getLoginUserID())
            ->find()
            ->fetch();
        if ($unfinished_attribute->getId()) {
            $this->assign('progress', $unfinished_attribute->getData($this->preCreateAttribute::fields_step));
        } else {
            $unfinished_attribute->setData('user_id', $this->session->getLoginUserID())->save(true);
        }
        if ($this->request->isPost()) {
            $progress = $this->request->getPost('progress', '');
            $unfinished_attribute->setData($this->preCreateAttribute::fields_step, $progress)
                ->save(true);
            $next_progress = $this->request->getPost('next_progress');
            switch ($progress):
                case 'progress-select-entity':
                    /**@var EavEntity $entityModel */
                    $entityModel = ObjectManager::getInstance(EavEntity::class);
                    $entity = $entityModel->reset()->where('entity_id', $this->request->getPost('entity_id'))->find()->fetch();
                    if (!$entity->getId()) {
                        $msg = __('所选择实体已不存在！');
                        if ($this->request->getGet('isAjax')) {
                            $unfinished_attribute->save(true);
                            return $this->fetchJson(['code' => 0, 'msg' => $msg]);
                        }
                        $this->getMessageManager()->addWarning($msg);
                        break;
                    }
                    $unfinished_attribute->setData($unfinished_attribute::fields_entity_id, $this->request->getPost('entity_id'));
                    $unfinished_attribute->setData($unfinished_attribute::fields_step, $next_progress);
                    if ($this->request->getGet('isAjax')) {
                        $unfinished_attribute->save(true);
                        return $this->fetchJson(['code' => 1, 'msg' => __('实体选择成功！')]);
                    }
                    break;
                case 'progress-select-set':
                    if (!$unfinished_attribute->getData($unfinished_attribute::fields_entity_id)) {
                        if ($this->request->getGet('isAjax')) {
                            $unfinished_attribute->save(true);
                            return $this->fetchJson(['code' => 0, 'msg' => __('请先选择实体！'), 'process' => 'progress-select-entity']);
                        }
                        $this->getMessageManager()->addWarning(__('请先选择实体！'));
                        $this->assign('progress', 'progress-select-entity');
                        break;
                    }
                    /**@var EavAttribute\Set $setModel */
                    $setModel = ObjectManager::getInstance(EavAttribute\Set::class);
                    $set = $setModel->where('set_id', $this->request->getPost('set_id'))
                        ->where('entity_id', $unfinished_attribute->getEntityId())
                        ->find()
                        ->fetch();
                    if (!$set->getId()) {
                        $msg = __('所选择属性集已不存在！');
                        if ($this->request->getGet('isAjax')) {
                            return $this->fetchJson(['code' => 0, 'msg' => $msg]);
                        }
                        $this->getMessageManager()->addWarning($msg);
                        break;
                    }
                    $unfinished_attribute->setSetId($set->getSetId());
                    $this->assign('progress', $next_progress);
                    if ($this->request->getGet('isAjax')) {
                        $unfinished_attribute->save(true);
                        return $this->fetchJson(['code' => 1, 'msg' => __('属性集选择成功！')]);
                    }
                    break;
                case 'progress-select-group':
                    if (!$unfinished_attribute->getEntityId()) {
                        if ($this->request->getGet('isAjax')) {
                            $unfinished_attribute->save(true);
                            return $this->fetchJson(['code' => 0, 'msg' => __('请先选择实体！'), 'process' => 'progress-select-entity']);
                        }
                        $this->getMessageManager()->addWarning(__('请先选择实体！'));
                        $this->assign('progress', 'progress-select-entity');
                        break;
                    }
                    if (!$unfinished_attribute->getSetId()) {
                        if ($this->request->getGet('isAjax')) {
                            $unfinished_attribute->save(true);
                            return $this->fetchJson(['code' => 0, 'msg' => __('请先选择属性集！'), 'process' => 'progress-select-set']);
                        }
                        $this->getMessageManager()->addWarning(__('请先选择属性集！'));
                        $this->assign('progress', 'progress-select-set');
                        break;
                    }
                    /**@var EavAttribute\Group $groupModel */
                    $groupModel = ObjectManager::getInstance(EavAttribute\Group::class);
                    $group = $groupModel->where('group_id', $this->request->getPost('group_id'))
                        ->where('entity_id', $unfinished_attribute->getEntityId())
                        ->where('set_id', $unfinished_attribute->getSetId())
                        ->find()
                        ->fetch();
                    if (!$group->getId()) {
                        $msg = __('所选择属性组已不存在！');
                        if ($this->request->getGet('isAjax')) {
                            $unfinished_attribute->save(true);
                            return $this->fetchJson(['code' => 0, 'msg' => $msg]);
                        }
                        $this->getMessageManager()->addWarning($msg);
                        break;
                    }
                    $unfinished_attribute->setGroupId($group->getId());
                    $this->assign('progress', $next_progress);
                    if ($this->request->getGet('isAjax')) {
                        $unfinished_attribute->save(true);
                        return $this->fetchJson(['code' => 1, 'msg' => __('属性组选择成功！请继续填写属性数据!')]);
                    }
                    break;
                case 'progress-attribute-option-log':
                    if (!$unfinished_attribute->getEntityId()) {
                        if ($this->request->getGet('isAjax')) {
                            return $this->fetchJson(['code' => 0, 'msg' => __('请先选择实体！'), 'process' => 'progress-select-entity']);
                        }
                        $this->getMessageManager()->addWarning(__('请先选择实体！'));
                        $this->assign('progress', 'progress-select-entity');
                        break;
                    }
                    if (!$unfinished_attribute->getSetId()) {
                        if ($this->request->getGet('isAjax')) {
                            return $this->fetchJson(['code' => 0, 'msg' => __('请先选择属性集！'), 'process' => 'progress-select-set']);
                        }
                        $this->getMessageManager()->addWarning(__('请先选择属性集！'));
                        $this->assign('progress', 'progress-select-set');
                        break;
                    }
                    // 读取来自前端的选择选项的数据
                    $option_log = $this->request->getPost('options');
                    if (!$option_log) {
                        if ($this->request->getGet('isAjax')) {
                            return $this->fetchJson(['code' => 0, 'msg' => __('没有配置项数据！')]);
                        }
                    }
                    $unfinished_attribute->setOptions(json_encode($option_log));
                    $progress = 'progress-attribute-option';
                    if ($this->request->getGet('isAjax')) {
                        $unfinished_attribute->setData($unfinished_attribute::fields_step, $progress);
                        $unfinished_attribute->save(true);
                        return $this->fetchJson(['code' => 1, 'msg' => __('属性配置项配置成功!'), 'data' => $option_log]);
                    }
                    break;
                case 'progress-attribute-option-log-delete':
                    // 读取来自前端的选择选项的数据
                    $option_id = $this->request->getPost('option_id');
                    $option_code = $this->request->getPost('code');
                    if (($option_id === null) && ($option_code === null)) {
                        if ($this->request->getGet('isAjax')) {
                            return $this->fetchJson(['code' => 0, 'msg' => __('没有要删除的配置项代码！')]);
                        }
                    }

                    $options = $unfinished_attribute->getOptions();
                    foreach ($options as $key => $option) {
                        if ($option_id == '0') {
                            if ($option['code'] == $option_code) {
                                unset($options[$key]);
                                break;
                            }
                        } else {
                            if ($option['option_id'] == $option_id) {
                                unset($options[$key]);
                                $unfinished_attribute->addDeleteOptionIds((int)$option_id);
                                break;
                            }
                        }
                    }

                    $unfinished_attribute->setOptions(json_encode($options));
                    $unfinished_attribute->setData($unfinished_attribute::fields_step, 'progress-attribute-option');
                    if ($this->request->getGet('isAjax')) {
                        $unfinished_attribute->save(true);
                        return $this->fetchJson(['code' => 1, 'msg' => __('属性配置项删除成功!'), 'data' => $options]);
                    }
                    break;
                case 'progress-attribute-details':
                    if (!$unfinished_attribute->getEntityId()) {
                        if ($this->request->getGet('isAjax')) {
                            return $this->fetchJson(['code' => 0, 'msg' => __('请先选择实体！'), 'process' => 'progress-select-entity']);
                        }
                        $this->getMessageManager()->addWarning(__('请先选择实体！'));
                        $this->assign('progress', 'progress-select-entity');
                        break;
                    }
                    if (!$unfinished_attribute->getSetId()) {
                        if ($this->request->getGet('isAjax')) {
                            return $this->fetchJson(['code' => 0, 'msg' => __('请先选择属性集！'), 'process' => 'progress-select-set']);
                        }
                        $this->getMessageManager()->addWarning(__('请先选择属性集！'));
                        $this->assign('progress', 'progress-select-set');
                        break;
                    }
                    if (!$unfinished_attribute->getGroupId()) {
                        if ($this->request->getGet('isAjax')) {
                            return $this->fetchJson(['code' => 0, 'msg' => __('请先选择实体属性组！'), 'process' => 'progress-select-group']);
                        }
                        $this->getMessageManager()->addWarning(__('请先选择实体属性组！'));
                        $this->assign('progress', 'progress-select-group');
                        break;
                    }
                    $attribute_data = [
                        'code' => $this->request->getPost('code'),
                        'set_id' => $unfinished_attribute->getSetId(),
                        'group_id' => $unfinished_attribute->getGroupId(),
                        'entity_id' => $unfinished_attribute->getEntityId(),
                        'name' => $this->request->getPost('name'),
                        'type_id' => $this->request->getPost('type_id'),
                        'is_enable' => $this->request->getPost('is_enable'),
                        'is_system' => 0,
                        'multiple_valued' => $this->request->getPost('multiple_valued'),
                        'has_option' => $this->request->getPost('has_option'),
                    ];
                    # 修改属性时不可以修改的内容
                    if ($attribute_id !== 0) {
                        unset($attribute_data['type_id']);
                        unset($attribute_data['multiple_valued']);
                        unset($attribute_data['has_option']);
                    }
                    $attribute_data = array_merge($unfinished_attribute->getData(), $attribute_data);
                    //                    dd($unfinished_attribute->getData());
                    $unfinished_attribute->setData($attribute_data);
                    // 检测是否已经存在
                    $attribute = clone $this->eavAttribute->where($this->eavAttribute::fields_code, $this->request->getPost('code'))
                        ->where($this->eavAttribute::fields_entity_id, $unfinished_attribute->getEntityId())
                        ->where($this->eavAttribute::fields_set_id, $unfinished_attribute->getSetId())
                        ->where($this->eavAttribute::fields_group_id, $unfinished_attribute->getGroupId())
                        ->find()->fetch();
                    try {
                        $result = $this->eavAttribute->setModelFieldsData($attribute_data)->save(true);
                        $msg = $attribute->getId() ? __('属性修改成功！') : __('属性创建成功！');
                        if ($this->request->getGet('isAjax')) {
                            $unfinished_attribute->save(true);
                            return $this->fetchJson(['code' => 1, 'msg' => $msg, 'v' => $unfinished_attribute->getQuery()->bound_values]);
                        }
                        $this->getMessageManager()->addSuccess($msg);
                        $this->redirect('*/backend/attribute/edit', ['attribute_id' => $result, 'entity_id' => $this->eavAttribute->getEntityId(), 'isAjax' => 'true']);
                    } catch (\Exception $e) {
                        $msg = $attribute->getId() ? __('属性编辑错误！') : __('创建属性错误！');
                        if ($this->request->getGet('isAjax')) {
                            $unfinished_attribute->save(true);
                            if (DEV || DEBUG) {
                                $msg .= $e->getMessage();
                            }
                            return $this->fetchJson(['code' => 0, 'msg' => $msg]);
                        }
                        $this->getMessageManager()->addError($msg);
                    }
                    $this->assign('progress', 'progress-attribute-details');
                    if ($next_progress !== 'progress-submit') {
                        break;
                    }
                case 'progress-attribute-option':
                    if ($progress === 'progress-attribute-option') {
                        $unfinished_attribute->setOptions(json_encode($this->request->getPost()));
                        $this->assign('progress', 'progress-attribute-option');
                    }
                    if ($this->request->getGet('isAjax')) {
                        return $this->fetchJson(['code' => 1, 'msg' => __('属性配置项设置成功！')]);
                    }
                    break;
                case 'progress-submit':
                    $unfinished_attribute->setData($unfinished_attribute::fields_step, $progress);
                    $unfinished_attribute->setModelFieldsData($this->request->getPost())->save(true);
                    // no break
                default:
                    // 检验
                    if (!$unfinished_attribute->getEntityId()) {
                        if ($this->request->getGet('isAjax')) {
                            $unfinished_attribute->save(true);
                            return $this->fetchJson(['code' => 0, 'msg' => __('请先选择实体！'), 'process' => 'progress-select-entity']);
                        }
                        $this->getMessageManager()->addWarning(__('请先选择实体！'));
                        $this->assign('progress', 'progress-select-entity');
                        break;
                    }
                    if (!$unfinished_attribute->getSetId()) {
                        if ($this->request->getGet('isAjax')) {
                            $unfinished_attribute->save(true);
                            $unfinished_attribute->save(true);
                            return $this->fetchJson(['code' => 0, 'msg' => __('请先选择实体属性集！'), 'process' => 'progress-select-set']);
                        }
                        $this->getMessageManager()->addWarning(__('请先选择实体属性集！'));
                        $this->assign('progress', 'progress-select-set');
                        break;
                    }
                    if (!$unfinished_attribute->getGroupId()) {
                        if ($this->request->getGet('isAjax')) {
                            $unfinished_attribute->save(true);
                            return $this->fetchJson(['code' => 0, 'msg' => __('请先选择实体属性组！'), 'process' => 'progress-select-group']);
                        }
                        $this->getMessageManager()->addWarning(__('请先选择实体属性组！'));
                        $this->assign('progress', 'progress-select-group');
                        break;
                    }
                    if (!$unfinished_attribute->getCode()) {
                        if ($this->request->getGet('isAjax')) {
                            $unfinished_attribute->save(true);
                            return $this->fetchJson(['code' => 0, 'msg' => __('请先填写属性数据！'), 'process' => 'progress-attribute-details']);
                        }
                        $this->getMessageManager()->addWarning(__('请先填写属性数据！'));
                        $this->assign('progress', 'progress-attribute-details');
                        break;
                    }
                    // 如果检测合格则添加
                    $attribute_data['entity_id'] = $unfinished_attribute->getEntityId();
                    $attribute_data['set_id'] = $unfinished_attribute->getSetId();
                    $attribute_data['group_id'] = $unfinished_attribute->getGroupId();
                    // 校验实体-属性集-属性组关系
                    /**@var Group $groupModel */
                    $groupModel = ObjectManager::getInstance(Group::class);
                    $group = $groupModel->where('group_id', $attribute_data['group_id'])
                        ->where('entity_id', $attribute_data['entity_id'])
                        ->where('set_id', $attribute_data['set_id'])
                        ->find()
                        ->fetch();
                    if (!$group->getId()) {
                        if ($this->request->getGet('isAjax')) {
                            $unfinished_attribute->save(true);
                            return $this->fetchJson(['code' => 0, 'msg' => __('分组不在所选实体属性集内！')]);
                        }
                        $this->getMessageManager()->addWarning(__('分组不在所选实体属性集内！'));
                        $this->redirect($this->_url->getCurrentUrl());
                    }
                    // 合并属性数据
                    if (!$unfinished_attribute->getCode()) {
                        if ($this->request->getGet('isAjax')) {
                            $unfinished_attribute->save(true);
                            return $this->fetchJson(['code' => 0, 'msg' => __('属性数据不存在！')]);
                        }
                        $this->getMessageManager()->addWarning(__('属性数据不存在！'));
                        $this->redirect($this->_url->getCurrentUrl());
                    }
                    $attribute_data = array_merge($attribute_data, $unfinished_attribute->getData());
                    $attribute_id = $this->eavAttribute->reset()
                        ->setModelFieldsData($attribute_data)
                        ->unsetData($this->eavAttribute::fields_ID)
                        ->save(true);
                    // 如果属性添加成功，并且有属性配置项，配置属性配置项
                    // 属性配置项
                    if ($this->eavAttribute->getId() and $this->eavAttribute->getId() && isset($attribute_data['has_option']) && ($attribute_data['has_option'] === '1')) {
                        $attribute_options = $unfinished_attribute->getOptions();
                        if (empty($attribute_options)) {
                            if ($this->request->getGet('isAjax')) {
                                $unfinished_attribute->save(true);
                                return $this->fetchJson(['code' => 0, 'msg' => __('属性为配置项属性：请设置属性配置项数据！')]);
                            }
                            $this->getMessageManager()->addWarning(__('属性为配置项属性：请设置属性配置项数据！'));
                            $this->redirect($this->_url->getCurrentUrl());
                        }
                        $insert_attribute_options = [];
                        foreach ($attribute_options as $attribute_option) {
                            $insert_attribute_options[] = [
                                EavAttribute\Option::fields_option_id => $attribute_option['option_id'],
                                EavAttribute\Option::fields_code => $attribute_option['code'],
                                EavAttribute\Option::fields_value => $attribute_option['value'],
                                EavAttribute\Option::fields_entity_id => $attribute_data['entity_id'],
                                EavAttribute\Option::fields_attribute_id => $this->eavAttribute->getId(),
                            ];
                        }
                        /**@var \Weline\Eav\Model\EavAttribute\Option $optionModel */
                        $optionModel = ObjectManager::getInstance(EavAttribute\Option::class);
                        $this->eavAttribute->beginTransaction();
                        try {
                            $optionModel->insert(
                                $insert_attribute_options,
                                [
                                    EavAttribute\Option::fields_code,
                                    EavAttribute\Option::fields_entity_id,
                                    EavAttribute\Option::fields_attribute_id
                                ]
                            )->fetch();
                            # 删除要删除的选择项
                            $deleteIds = $unfinished_attribute->getDeleteOptionIds();
                            if (!empty($deleteIds)) {
                                $option = $this->eavAttribute->getOptionModel();
                                $option->reset()
                                    ->where('attribute_id', $this->eavAttribute->getId())
                                    ->where('option_id in (\'' . implode('\',\'', $deleteIds) . '\')')
                                    ->delete()
                                    ->fetch();
                            }
                            $this->eavAttribute->commit();
                        } catch (\Exception $exception) {
                            $this->eavAttribute->rollBack();
                            if ($this->request->getGet('isAjax')) {
                                return $this->fetchJson(['code' => 0, 'msg' => __('添加异常！属性可能已经存在！'), 'error' => $exception->getMessage()]);
                            }
                            $this->getMessageManager()->addWarning(__('添加异常！'));
                            if (DEBUG || DEV) {
                                $this->getMessageManager()->addException($exception);
                            }
                            $this->redirect('*/backend/attribute/add');
                        }
                    }
                    // 卸载预创建数据
                    $unfinished_attribute->delete();
                    if ($this->request->getGet('isAjax')) {
                        return $this->fetchJson(['code' => 1, 'msg' => __('添加成功！')]);
                    }
                    $this->getMessageManager()->addSuccess(__('添加成功！'));

                    $this->redirect($this->_url->getBackendUrl('*/backend/attribute/edit', [
                        'code' => $this->request->getPost('code'),
                        'entity_id' => $this->request->getPost('entity_id'),
                    ]));
                    break;
            endswitch;
            // 记录进度
            $unfinished_attribute->setData($unfinished_attribute::fields_step, $progress);
            $unfinished_attribute->save(true);
        }
        // 装配session记录数据
        if ($entity = $unfinished_attribute->getEntity()) {
            $this->assign(self::eav_entity, $entity->getData());
        }
        if ($set = $unfinished_attribute->getSet()) {
            $this->assign(self::eav_entity_attribute_set, $set->getData());
        }
        if ($group = $unfinished_attribute->getGroup()) {
            $this->assign(self::eav_entity_attribute_set_group, $group->getData());
        }
        if ($data = $unfinished_attribute->getData()) {
            if ($attribute_id == 0) {
                unset($data['attribute_id']);
            }
            $this->assign(self::eav_attribute, $data);
        }
        $options = $unfinished_attribute->getOptions();
        if ($options) {
            $this->assign('eav_attribute_options', $options);
        }
        $entity_id = $unfinished_attribute->getEntityId();
        $attribute = $unfinished_attribute->getData();
        // 检测如果有has_option则添加options
        $has_option = $attribute['has_option'] ?? 0;
        $attribute_id = $attribute['code'] ?? '';
        if ($has_option === '1' && ($entity_id && $attribute_id)) {
            /**@var \Weline\Eav\Model\EavAttribute\Option $optionModel */
            $optionModel = ObjectManager::getInstance(EavAttribute\Option::class);
            $options = $optionModel->where([
                'entity_id' => $entity_id,
                'attribute_id' => $this->request->getPost('attribute_id')
            ])
                ->select()->fetchOrigin();
            $this->assign('options', $options);
        }
        /**@var \Weline\Eav\Model\EavAttribute\Type $typeModel */
        $typeModel = ObjectManager::getInstance(EavAttribute\Type::class);
        $types = $typeModel->select()->fetchOrigin();
        $this->assign('types', $types);

        /**@var EavEntity $eavEntityModel */
        $eavEntityModel = ObjectManager::getInstance(EavEntity::class);
        $entities = $eavEntityModel->reset()->select()->fetchOrigin();
        $this->assign('entities', $entities);

        $entity_id = $unfinished_attribute->getEntityId();
        if ($entity_id) {
            /**@var \Weline\Eav\Model\EavAttribute\Set $setModel */
            $setModel = ObjectManager::getInstance(EavAttribute\Set::class);
            $sets = $setModel
                ->where('main_table.entity_id', $entity_id)
                ->joinModel(EavEntity::class, 'entity', 'main_table.entity_id=entity.entity_id', 'left', 'entity.name as entity_name')
                ->select()
                ->fetchOrigin();
            $this->assign('sets', $sets);
        }
        $set = $unfinished_attribute->getSet();
        if ($set) {
            /**@var Group $grouModel */
            $groupModel = ObjectManager::getInstance(Group::class);
            $groups = $groupModel
                ->where('main_table.entity_id', $entity_id)
                ->where('main_table.set_id', $set->getId())
                ->joinModel(EavEntity::class, 'entity', 'main_table.entity_id=entity.entity_id', 'left', 'entity.name as entity_name')
                ->select()
                ->fetchOrigin();
            $this->assign('groups', $groups);
        }
        // 链接
        $this->assign('action', $this->_url->getCurrentUrl());
        return $this->fetch('form_old');
    }

    public function add()
    {
        $user_data = $this->backendUserData->where('backend_user_id', $this->session->getLoginUserID())
            ->where('scope', 'attribute')
            ->find()->fetch();
        # 配置属性记录
        $attribute = json_decode($user_data['json'] ?? '', true);
        # 属性配置项解析
        if (isset($attribute['options'])) {
            $attribute['options'] = json_decode($attribute['options'], true);
        } else {
            $attribute['options'] = [];
        }
        $this->assign('attribute', $attribute);
        # 实体
        $entities = ObjectManager::getInstance(EavEntity::class)->select()->fetchOrigin();
        $this->assign('entities', $entities);
        # 类型
        /**@var \Weline\Eav\Model\EavAttribute\Type $typeModel */
        $typeModel = ObjectManager::getInstance(EavAttribute\Type::class);
        $types = $typeModel->select()->fetchOrigin();
        $this->assign('types', $types);
        return $this->fetch('form');
    }

    public function edit()
    {
        $attribute_id = (int)$this->request->getGet('attribute_id');
        return $this->add($attribute_id);
    }

    private function form()
    {

    }

    public function getDelete()
    {
        if ($id = $this->request->getGet('id')) {
            $this->eavAttribute->load($id)->delete();
            $this->getMessageManager()->addSuccess(__('删除成功！'));
        } else {
            $this->getMessageManager()->addError(__('找不到要操作的代码！'));
        }
        $this->redirect($this->_url->getBackendUrl('*/backend/attribute'));
    }

    public function postTranslate()
    {
        $attribute_id = $this->request->getPost('attribute_id');
        $entity_id = $this->request->getPost('entity_id');
    }
}
