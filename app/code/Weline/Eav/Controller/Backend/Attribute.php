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

use Weline\Backend\Api\UserData\BackendCurrentUserDataInterface;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Group;
use Weline\Eav\Model\EavAttribute\Type;
use Weline\Eav\Model\EavEntity;
use Weline\Framework\App\Exception;
use Weline\Framework\Exception\Core;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;

/**
 * @deprecated 请使用 Manager 控制器 (/eav/backend/manager) 统一管理EAV
 * @see \Weline\Eav\Controller\Backend\Manager
 */
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
    private ?BackendCurrentUserDataInterface $currentUserData = null;
    private RuntimeProviderResolver $runtimeProviders;

    public function __construct(RuntimeProviderResolver $runtimeProviders, EavAttribute $eavAttribute)
    {
        $this->eavAttribute = $eavAttribute;
        $this->runtimeProviders = $runtimeProviders;
    }

    public function index()
    {
        if ($entity_code = $this->request->getGet('entity_code')) {
            /**
             * @var \Weline\Eav\Model\EavEntity $entityModel
             */
            $entityModel = ObjectManager::getInstance(EavEntity::class);
            $entityModel->loadByCode($entity_code);
            if (!$entityModel->getId()) {
                throw new Core(__('实体不存在'));
            }
            $this->eavAttribute->where('main_table.eav_entity_id', $entityModel->getId());
            $this->assign('entity', $entityModel);
        }
        $this->eavAttribute->loadLocalDescription()
            ->joinModel(EavEntity::class, 'entity', 'main_table.eav_entity_id=entity.eav_entity_id', 'left', 'entity.name as entity_name')
            ->joinModel(EavEntity\LocalDescription::class, 'entity_local', 'main_table.eav_entity_id=entity_local.eav_entity_id and entity_local.local_code=\'' . Cookie::getLangLocal() . '\'', 'left', 'entity_local.name as entity_local_name');
        $this->eavAttribute->joinModel(Type::class, 'type');
        if ($search = $this->request->getGet('search')) {
            $this->eavAttribute->where('concat(main_table.name,main_table.code,type.name,type.code,local.name,entity.name,entity.code,entity_local.name)', "%$search%", 'like');
        }
        if ($entity = $this->request->getGet('entity')) {
            $this->eavAttribute->where('eav_entity_id', $entity);
        }
        // p($this->eavAttribute->select()->getLastSql());
        $attributes = $this->eavAttribute->order('main_table.update_time')->pagination()->select()->fetchArray();
        $this->assign('attributes', $attributes);
        $this->assign('pagination', $this->eavAttribute->getPagination());
        return $this->fetch();
    }

    public function getAttributeIdByCode(): string
    {
        $code = (string)$this->request->getPost('code');
        $this->preCreateAttribute->load($this->preCreateAttribute::schema_fields_code, $code);
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
        $eav_entity_id = $this->request->getGet('eav_entity_id');
        $set_id = $this->request->getGet('set_id');
        $group_id = $this->request->getGet('group_id');
        $search = $this->request->getGet('search');
        $json = ['items' => [], 'eav_entity_id' => $eav_entity_id, 'set_id' => $set_id, 'group_id' => $group_id, 'limit' => $limit, 'search' => $search];
        if (empty($eav_entity_id)) {
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
        $this->eavAttribute->where('eav_entity_id', $eav_entity_id)
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
        $attributes = $this->eavAttribute->select()->fetchArray();
        $json['items'] = $attributes;
        return $this->fetchJson($json);
    }

    public function add()
    {
        # 检测是否有锁定实体 entity_type
        $entity_type = $this->request->getGet('entity_type');
        $this->assign('entity_type', $entity_type);
        # 配置属性记录
        $attribute = $this->backendCurrentUserData()->getScope('attribute');
        # 属性配置项解析
        if (isset($attribute['options'])) {
            $attribute['options'] = json_decode($attribute['options'], true);
        } else {
            $attribute['options'] = [];
        }
        if (!isset($attribute['data_has_option'])) {
            $attribute['data_has_option'] = 0;
        }
        $this->assign('attribute', $attribute);
        # 实体
        $entities = ObjectManager::getInstance(EavEntity::class)->select()->fetchArray();
        $this->assign('entities', $entities);
        # 类型
        /**@var \Weline\Eav\Model\EavAttribute\Type $typeModel */
        $typeModel = ObjectManager::getInstance(EavAttribute\Type::class);
        $types = $typeModel->select()->fetchArray();
        $this->assign('types', $types);
        return $this->fetch('form');
    }

    public function save()
    {
        $json = [
            'code' => 1,
            'msg' => '',
            'data' => []
        ];
        if (!$this->request->isPost()) {
            $json['msg'] = __('非法请求!');
            return $this->fetchJson($json);
        }

        $base = $this->request->getPost('base');
        if ($base['attribute_id']) {
            $this->eavAttribute->load($base['attribute_id'])->setData($base)->save();
            $attribute_id = $base['attribute_id'];
        } else {
            unset($base['attribute_id']);
            $attribute_id = $this->eavAttribute->setData($base)->save();
        }
        # 检测可配置项
        $options = $this->request->getPost('option');
        if ($options) {
            $this->eavAttribute->load($attribute_id);
            $optionModels = [];
            foreach ($options as $option) {
                $option['option_id'] = $option['option_id'] ?? 0;
                $option['attribute_id'] = $attribute_id;
                $option['eav_entity_id'] = $base['eav_entity_id'] ?? 0;
                $optionModels[] = ObjectManager::make(EavAttribute\Option::class, ['data' => $option]);
            }
            $this->eavAttribute->setOptions($optionModels);
        }
        $json['code'] = 0;
        $json['data']['attribute'] = $this->eavAttribute->getData();
        $json['data']['options'] = $this->eavAttribute->getOptions();
        $json['msg'] = __('保存成功');
        # 删除实时保存的数据
        $this->backendCurrentUserData()->clearScope('attribute');
        return $this->fetchJson($json);
    }

    public function edit()
    {
        $attribute_id = (int)$this->request->getGet('attribute_id');
        if (!$attribute_id) {
            $this->getMessageManager()->addError(__('属性不存在！'));
            $this->redirect('*/backend/attribute');
        }
        # 配置属性记录
        $attribute = $this->eavAttribute->loadByAttributeId($attribute_id);
        if (!$attribute->getId()) {
            $this->getMessageManager()->addError(__('属性不存在！'));
            $this->redirect('*/backend/attribute');
        }
        # 属性配置项解析
        if (!isset($attribute['options'])) {
            $options = $attribute->getOptions();
            $attribute['options'] = $options;
        } else {
            $attribute['options'] = [];
        }
        if (!isset($attribute['data_has_option'])) {
            $attribute['data_has_option'] = 0;
        }
        $this->assign('attribute', $attribute);
        # 实体
        $entities = ObjectManager::getInstance(EavEntity::class)->select()->fetchArray();
        $this->assign('entities', $entities);
        # 类型
        /**@var \Weline\Eav\Model\EavAttribute\Type $typeModel */
        $typeModel = ObjectManager::getInstance(EavAttribute\Type::class);
        $types = $typeModel->select()->fetchArray();
        $this->assign('types', $types);
        return $this->fetch('form');
    }

    private function form()
    {

    }

    public function getDelete()
    {
        $json = ['code' => 0, 'msg' => ''];
        if ($id = $this->request->getGet('id')) {
            try {
                $this->eavAttribute->load($id)->delete()->fetch();
            } catch (\ReflectionException|Exception|Core $e) {
                $json['msg'] = __('删除失败，请联系管理员') . (DEV ? '：' . $e->getMessage() : '');
                return $this->fetchJson($json);
            }
            $json['code'] = 1;
            $json['msg'] = __('删除成功');
        } else {
            $json['msg'] = __('非法请求');
        }
        return $this->fetchJson($json);
    }

    public function postTranslate()
    {
        $attribute_id = $this->request->getPost('attribute_id');
        $eav_entity_id = $this->request->getPost('eav_entity_id');
    }

    private function backendCurrentUserData(): BackendCurrentUserDataInterface
    {
        if ($this->currentUserData instanceof BackendCurrentUserDataInterface) {
            return $this->currentUserData;
        }

        $provider = $this->runtimeProviders->resolve(BackendCurrentUserDataInterface::class);
        if (!$provider instanceof BackendCurrentUserDataInterface) {
            throw new \RuntimeException('backend_current_user_data_provider_unavailable');
        }

        return $this->currentUserData = $provider;
    }
}
