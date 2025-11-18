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
        $this->product->loadLocalDescription();
    }

    public function index()
    {
        // 明确指定排序字段，使用表别名避免JOIN时的字段歧义
        $products = $this->product->order('main_table.' . \WeShop\Product\Model\Product::fields_ID, 'DESC')
            ->pagination()
            ->select()
            ->fetch();
        $this->assign('products', $products->getItems());
        $this->assign('pagination', $products->getPagination());
        return $this->fetch();
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
                ->where('sku', $skus, 'in')
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
            ->loadLocalDescription()
            ->where(Set::fields_ID, $id)
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

}
