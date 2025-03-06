<?php

namespace WeShop\Product\Controller\Backend\Product;

use Aiweline\PlayingInChina\Model\Message;
use WeShop\Product\Model\Product;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Set;
use Weline\Eav\Model\EavAttribute\Type;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;

class Edit extends BackendController
{
    private \WeShop\Product\Model\Product $product;

    function __construct(Product $product)
    {
        $this->product = $product;
    }

    function index()
    {
        if ($this->request->isGet()) {
            $product_id = $this->request->getGet('product_id');
            if (!$product_id) {
                $this->redirect('/component/offcanvas/error', ['msg' => '缺少参数', 'reload' => false]);
            }
            /**@var Product $product */
            $product = ObjectManager::getInstance(Product::class);
            $product = $product->where('product_id', $product_id)
                ->joinModel(Set::class, 'attr_set', 'main_table.set_id=attr_set.set_id', 'left', 'attr_set.name as attr_set_name')
                ->find()
                ->fetch();
            if (!$product->getId()) {
                $this->redirect('/component/offcanvas/error', [
                    'msg' => __('您编辑的产品已不存在！'),
                    'time' => 5
                ]);
            }
            $this->assign('type', (bool)$product->getParentId() ? 'configurable' : 'simple');
            $this->assign('product', $product);
            $child_products = $product->where('parent_id', $product_id)
                ->select()
                ->fetchArray();
            $this->assign('child_products', $child_products);
            # 属性集
            $sets = $this->product->eav_AttributeSetModel()->select()->fetchArray();
            $this->assign('sets', $sets);
            # 产品属性集
            $set = $this->product->eav_AttributeSetModel()->where('set_id', $product['set_id'])->find()->fetch();
            $this->assign('set', $set);
            # 属性集数据
            $setAttributes = $this->getSetGroupAttributes($product['set_id']);
            foreach ($setAttributes as &$groupAttributes) {
                /**@var EavAttribute $attribute */
                foreach ($groupAttributes['attributes'] as $gk => &$groupAttribute) {
                    if ($groupAttribute['code'] == 'image' or $groupAttribute['code'] == 'images') {
                        unset($groupAttributes['attributes'][$gk]);
                        continue;
                    }
                    $attribute = $product->getAttribute($groupAttribute['code']);
                    if ($value = $attribute->getValue()) {
                        $groupAttribute['value'] = $value;
                        $options = $attribute->getOptionsWithValue();
                        $groupAttribute['value_alias'] = $options;
                    }
                }
            }
//            dd($setAttributes);
            $this->assign('setAttributes', $setAttributes);
            # 如果是可配置产品
            return $this->fetch();
        }
        $data = $this->request->getPost();
        $product = $this->product->load('sku', $data['sku']);
        if (!$product->getId()) {
            $this->redirect('/component/offcanvas/error', [
                'msg' => __('您编辑的产品已不存在！'),
                'time' => 5
            ]);
        }
        $data['set_id'] = $product->getSetId();
        $attributes = $data['attribute'] ?? [];
        unset($data['attribute']);
        $product_id = $this->product->getId();
        try {
            $this->product->setData($data)->save('product_id');
            # 保存属性数据
            $eav_entity_id = $this->product->getEavEntityId();
            if ($attributes) {
                list($attributeValues, $attributeDataItems) = $this->checkAttributes($attributes, $this->product);
                /**@var EavAttribute $attributeDataItem */
                foreach ($attributeDataItems as $attributeDataItem) {
                    # 先删除属性原值
                    $valueModel = $attributeDataItem->w_getValueModel();
                    $valueModel->where('entity_id', $product_id)->where('attribute_id', $attributeDataItem->getId())->delete();
                    $attribute_value = $attributeValues[$attributeDataItem->getId()];
                    $attributeDataItem->setValue($product_id, $attribute_value);
                }
            }
            \Weline\Framework\Manager\Message::success(__('修改成功！'));
        } catch (Exception $e) {
            $this->redirect('/component/offcanvas/error', [
                'msg' => $e->getMessage(),
                'time' => 15
            ]);
        }
        $this->redirect('*/backend/product/edit', ['product_id' => $product_id]);
    }

    public function checkAttributes(array $attributes, Product $product): array
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
            ->where($attribute::fields_eav_entity_id, $product->getEavEntityId())
            ->select()
            ->fetch()
            ->getItems();
        foreach ($attributeDataItems as $attributeDataItem) {
            $attributeDataItem->current_setEntity($product);
        }
        if (count($attributeIds) !== count($attributeDataItems)) {
            throw new Exception(__('部分属性不存在'));
        }
        return array($attributeValues, $attributeDataItems);
    }

    private function getSetGroupAttributes($set_id): array
    {
        $groups = $this->product->eav_AttributeGroupModel()
            ->addLocalDescription()
            ->where(Set::fields_ID, $set_id)
            ->select()
            ->fetchArray();
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
                $value = $attr->getValue();
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
        return $groups;
    }
}