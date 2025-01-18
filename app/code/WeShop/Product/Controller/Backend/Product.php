<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归WeShop所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/7/11 20:55:21
 */

namespace WeShop\Product\Controller\Backend;

use WeShop\Product\Model\Product\OptionId;
use Weline\Backend\Model\BackendUserData;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Group;
use Weline\Eav\Model\EavAttribute\Set;
use Weline\Eav\Model\EavAttribute\Type;
use Weline\Eav\Model\EavEntity;
use Weline\Framework\App\Exception;
use Weline\Framework\Exception\Core;
use Weline\Framework\Manager\ObjectManager;

class Product extends \Weline\Framework\App\Controller\BackendController
{
    private \WeShop\Product\Model\Product $product;

    public function __construct(
        \WeShop\Product\Model\Product $product
    )
    {
        $this->product = $product;
    }

    public function index()
    {
        $products = $this->product->pagination()->select()->fetch();
        $this->assign('products', $products->getItems());
        $this->assign('pagination', $products->getPagination());
        return $this->fetch();
    }

    /**
     * @throws \ReflectionException
     * @throws \Weline\Framework\App\Exception
     */
    public function add()
    {
        # get请求返回产品创建表单
        if ($this->request->isGet()) {
            $action = $this->request->getUrlBuilder()->getBackendUrl('*/backend/product/add');
            $this->assign('action', $action);
            # 获取用户的scope数据
            $userData = ObjectManager::getInstance(BackendUserData::class);
            $userData = $userData->where('backend_user_id', $this->session->getLoginUserID())
                ->where('scope', 'product')
                ->find()
                ->fetch();
            $product = json_decode($userData['json'] ?? '', true);
            if (!isset($product['progress'])) {
                $product['progress'] = 'product_base_info';
            }
            if (isset($product['set_id'])) {
                $set = $this->product->eav_AttributeSetModel()->where(Set::fields_ID, $product['set_id'])->find()->fetch();
                $product['set_name'] = $set->getData('name');
            }
            $product['image'] = $product['image'] ?? '';
            $product['images'] = $product['images'] ?? '';
            $this->assign('product', $product);
            # 实体ID
            //            $this->product->setModelFieldsData($data);
            # 属性集
            $sets = $this->product->eav_AttributeSetModel()->select()->fetchArray();
            $this->assign('sets', $sets);
            return $this->fetch('form');
        }
        # post请求保存
        if ($this->request->isPost()) {
            $data = $this->request->getPost();
            $entity_id = $this->product->getEntityId();
            $data['image'] = trim($data['image'], ',');
            $data['parent_id'] = 0;
            $this->product->setModelFieldsData($data);
            # 创建主产品
            try {
                $product_id = $this->product->save();
                # 保存属性数据
                $attributes = $this->request->getPost('attribute');
                if ($attributes) {
                    list($attributeValues, $attributeDataItems) = $this->checkAttributes($attributes, $entity_id);
                    /**@var EavAttribute $attributeDataItem */
                    foreach ($attributeDataItems as $attributeDataItem) {
                        # 先删除属性原值
                        $valueModel = $attributeDataItem->w_getValueModel();
                        $valueModel->where('entity_id', $product_id)->where('attribute_id', $attributeDataItem->getId())->delete();
                        $attribute_value = $attributeValues[$attributeDataItem->getId()];
                        $attributeDataItem->setValue($product_id, $attribute_value);
                    }
                }
            } catch (\Exception $exception) {
                $this->getMessageManager()->addError(__('保存失败:') . (DEV ? $exception->getMessage() : ''));
                $this->request->isGet(true);
                return $this->add();
            }

            # 检测是否有可配置产品
            if (isset($data['configurable'])) {
                $configurableAttributes = $data['configurable'];
                $configurableProductItemsStr = $data['configurableProductItems'] ?? '[]';
                $configurableProductItems = json_decode($configurableProductItemsStr, true);
                foreach ($configurableProductItems as $configurableProduct) {
                    if (empty($configurableProduct['image'])) {
                        $configurableProduct['image'] = $data['image'];
                    }
                    if (empty($configurableProduct['images'])) {
                        $configurableProduct['images'] = $data['images'];
                    }
                    # 创建子产品
                    $configurableProduct['parent_id'] = $product_id;
                    $configurableProduct = array_merge($data, $configurableProduct);
                    try {
                        $child_product_id = $this->product->reset()->clearData()->setModelFieldsData($configurableProduct)->save();
                    } catch (\Throwable $throwable) {
                        $this->getMessageManager()->addError(__('保存失败,请重试!') . (DEV ? $throwable->getMessage() : ''));
                        $this->request->isGet(true);
                        return $this->add();
                    }
                    # 可配置属性设置
                    /**@var OptionId $optionModel */
                    $optionModel = ObjectManager::getInstance(OptionId::class);
                    list($attributeValues, $attributeDataItems) = $this->checkAttributes($configurableAttributes, $entity_id);
                    foreach ($attributeDataItems as $attributeDataItem) {
                        # 先删除属性原值
                        $valueModel = $attributeDataItem->w_getValueModel();
                        $valueModel->where('entity_id', $child_product_id)->where('attribute_id', $attributeDataItem->getId())->delete();
                        $attribute_values = $attributeValues[$attributeDataItem->getId()];
                        foreach ($attribute_values as &$attribute_value_item) {
                            $attribute_value_item = json_decode(urldecode($attribute_value_item), true);
                            if (!isset($attribute_value_item['swatch-image'])) {
                                $attribute_value_item['swatch-image'] = $data['image'];
                            }
                            $attribute_value_item['swatch-color'] = $attribute_value_item['swatch-color'] ?? '';
                            $attribute_value_item['swatch-text'] = $attribute_value_item['swatch-text'] ?? '';
                            # 类型数据
                            $type = $attributeDataItem->getTypeModel();
                            $value_data = [
                                $child_product_id,
                                $attribute_value_item['value'],
                            ];
                            if ($type->getIsSwatch()) {
                                if ($type->hasSwatchImage()) {
                                    $value_data[] = $attribute_value_item['swatch-image'];
                                } else {
                                    $value_data[] = '';
                                }
                                if ($type->hasSwatchColor()) {
                                    $value_data[] = $attribute_value_item['swatch-color'];
                                } else {
                                    $value_data[] = '';
                                }
                                if ($type->hasSwatchText()) {
                                    $value_data[] = $attribute_value_item['swatch-text'];
                                } else {
                                    $value_data[] = '';
                                }
                                # 类型ID
                            }
                            $attributeDataItem->setValue(...$value_data);
                            # 设置产品option_id
                            $optionModel->clearData()->setProductId($child_product_id)
                                ->setAttributeId($attributeDataItem->getId())
                                ->setOptionId($attribute_value_item['option_id'])
                                ->setParentProductId($product_id)
                                ->save();
                        }
                    }
                }
            }
//            # 删除预留数据
//            /**@var BackendUserData $UserData */
//            $UserData = ObjectManager::getInstance(BackendUserData::class);
//            try {
//                $UserData->reset()->clearData()
//                    ->where('backend_user_id', $this->session->getLoginUserID())
//                    ->where('scope', 'product')
//                    ->delete();
//            } catch (\ReflectionException|Exception|Core $e) {
//            }
            $this->redirect('/component/offcanvas/success', ['msg' => '保存成功']);
        }
    }

    function searchBy()
    {
        $filed = $this->request->getGet('field');
        $value = $this->request->getGet('value');
        if ($filed && $value) {
            $product = $this->product
                ->where($filed, $value)
                ->find()
                ->fetch();
            return $this->fetchJson($product->getData());
        } else {
            return $this->fetchJson([]);
        }
    }

    public function checkBatchSku()
    {
        $skus = $this->request->getPost('skus');
        if ($skus) {
            $products = $this->product->reset()
                ->where('sku in (\'' . implode("','", $skus) . '\')')
                ->select()
                ->fetchArray();
            # 检查这些sku是否存在
            foreach ($skus as $key => $sku) {
                unset($skus[$key]);
                $skus[$sku] = 0;
                foreach ($products as $product) {
                    if ($product['sku'] === $sku) {
                        $skus[$sku] = 1;
                    }
                }
            }
            return $this->fetchJson($skus);
        } else {
            return $this->fetchJson([]);
        }
    }

    public function getSetAttributes()
    {
        $id = $this->request->getGet('id');
        $attributes = $this->product->eav_AttributeModel()
            ->joinModel(Group::class, 'group', 'main_table.group_id=group.group_id')
            ->joinModel(Type::class, 'type', 'main_table.type_id=type.type_id')
            ->where('main_table.' . Set::fields_ID, $id)
            ->select()
            ->fetchArray();
        return $this->fetchJson($attributes);
    }

    public function getSetGroup()
    {
        $id = $this->request->getGet('id');
        $groups = $this->product->eav_AttributeGroupModel()->where(Set::fields_ID, $id)
            ->joinModel(Group::class, 'group', 'main_table.group_id=group.group_id')
            ->joinModel(Type::class, 'type', 'main_table.type_id=type.type_id')
            ->select()
            ->fetchArray();
        return $this->fetchJson($groups);
    }

    public function getSetGroupAttributes()
    {
        $id = $this->request->getGet('set_id');
        $groups = $this->product->eav_AttributeGroupModel()
            ->addLocalDescription()
            ->where(Set::fields_ID, $id)
            ->select()
            ->fetchArray();
        $productEavEntity = ObjectManager::getInstance(EavEntity::class)->loadByCode('product');
        foreach ($groups as &$group) {
            $attributes = $this->product->reset()->eav_AttributeModel()
                ->where(EavAttribute::fields_set_id, $group[Set::fields_ID])
                ->where('main_table.' . EavAttribute::fields_group_id, $group[EavAttribute\Group::fields_ID])
                ->joinModel(Type::class, 'type', 'main_table.type_id=type.type_id')
                ->select()
                ->fetch()
                ->getItems();
            /**@var EavAttribute $attr */
            foreach ($attributes as &$attr) {
                # 获取属性的属性
                $frontend_attrs = $attr->getData('frontend_attrs') ?? '';
                $frontend_attrs = str_replace('  ', ' ', $frontend_attrs);
                $frontend_attrs = str_replace('  ', ' ', $frontend_attrs);
                $frontend_attrs = str_replace('\'', '', $frontend_attrs);
                $frontend_attrs = str_replace('"', '', $frontend_attrs);
                $frontend_attrs = explode(' ', $frontend_attrs);
                $need = [];
                foreach ($frontend_attrs as $key => $frontend_attr) {
                    $frontend_attr_arr = explode('=', $frontend_attr);
                    $frontend_attr_arr[0] = trim($frontend_attr_arr[0]);
                    $frontend_attr_arr[1] = trim($frontend_attr_arr[1] ?? '');
                    $need[$frontend_attr_arr[0]] = $frontend_attr_arr[1];
                }
                $attr['frontend_attrs_json'] = $need;
                # 获取属性值
                try {
                    $value = $attr->getValue();
                } catch (\Exception $exception) {
                    $value = $attr->getMultipleValued() ? [] : '';
                }
                $attr['value'] = $attr->getData('multiple_valued') ? $value : ($value[0] ?? '');
                $data = $attr->getData();
                # 获取属性配置项
                if ($attr->getData('has_option')) {
                    $data['options'] = $attr->getOptionModel()
                        ->select()
                        ->fetchArray();
                }
                $attr = $data;
            }
            $group['attributes'] = $attributes;
        }
        return $this->fetchJson($groups);
    }

    /**
     * @param mixed $attributes
     * @param int $entity_id
     * @return array
     * @throws \ReflectionException
     * @throws \Weline\Framework\App\Exception
     */
    public function checkAttributes(array $attributes, int $entity_id): array
    {
# 批量查询属性
        /**@var EavAttribute $attribute */
        $attribute = ObjectManager::make(EavAttribute::class);
        $attributesItems = [];
        $attributeIds = [];
        $attributeValues = [];
        foreach ($attributes as $attributeItems) {
            $attributeValues = $attributeValues + $attributeItems;
            $attributeIds = array_merge($attributeIds, array_keys($attributeItems));
        }
        # 查询属性是否存在
        /**@var EavAttribute[] $attributeDataItems */
        $attributeDataItems = $attribute->reset()
            ->where($attribute::fields_attribute_id, $attributeIds, 'in')
            ->where($attribute::fields_eav_entity_id, $entity_id)
            ->select()
            ->fetch()
            ->getItems();
        if (count($attributeIds) !== count($attributeDataItems)) {
            throw new Exception(__('部分属性不存在'));
        }
        return array($attributeValues, $attributeDataItems);
    }
}
