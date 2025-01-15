<?php

namespace Gvanda\Product\Controller\Backend\Product;

use Gvanda\Product\Model\Product;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Set;
use Weline\Eav\Model\EavAttribute\Type;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;

class Edit extends BackendController
{
    private \Gvanda\Product\Model\Product $product;

    function __construct(Product $product)
    {
        $this->product = $product;
    }

    function index()
    {
        $product_id = $this->request->getGet('product_id');
        if (!$product_id) {
            $this->redirect('/component/offcanvas/error', ['msg' => '缺少参数', 'reload' => false]);
        }
        /**@var Product $product */
        $product = ObjectManager::getInstance(Product::class);
        $product = $product->where('product_id', $product_id)
            ->joinModel(Set::class, 'set', 'main_table.set_id=set.set_id', 'left', 'set.name as set_name')
            ->find()->fetch();
        $this->assign('product', $product);
        # 属性集
        $sets = $this->product->eav_AttributeSetModel()->select()->fetchOrigin();
        $this->assign('sets', $sets);
        # 产品属性集
        $set = $this->product->eav_AttributeSetModel()->where('set_id', $product['set_id'])->find()->fetch();
        $this->assign('set', $set);
        # 属性集数据
        $setAttributes = $this->getSetGroupAttributes($product['set_id']);
        $this->assign('setAttributes', $setAttributes);
        # 如果是可配置产品
        return $this->fetch();
    }

    private function getSetGroupAttributes($set_id): array
    {
        $groups = $this->product->eav_AttributeGroupModel()
            ->addLocalDescription()
            ->where(Set::fields_ID, $set_id)
            ->select()
            ->fetchOrigin();
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
                $need           = [];
                foreach ($frontend_attrs as $key => $frontend_attr) {
                    $frontend_attr_arr           = explode('=', $frontend_attr);
                    $frontend_attr_arr[0]        = trim($frontend_attr_arr[0]);
                    $frontend_attr_arr[1]        = trim($frontend_attr_arr[1] ?? '');
                    $need[$frontend_attr_arr[0]] = $frontend_attr_arr[1];
                }
                $attr['frontend_attrs_json'] = $need;
                # 获取属性值
                $value         = $attr->getValue();
                $attr['value'] = $attr->getData('multiple_valued') ? $value : ($value[0] ?? '');
                $data          = $attr->getData();
                # 获取属性配置项
                if ($attr->getData('has_option')) {
                    $data['options'] = $attr->getOptionModel()
                        ->select()
                        ->fetchOrigin();
                }
                $attr = $data;
            }
            $group['attributes'] = $attributes;
        }
        return $groups;
    }
}