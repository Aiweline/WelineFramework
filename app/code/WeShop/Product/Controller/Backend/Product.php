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

use WeShop\Product\Model\Product as ProductModel;
use WeShop\Product\Model\Product\OptionId;
use Weline\Backend\Model\BackendUserData;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Group;
use Weline\Eav\Model\EavAttribute\Set;
use Weline\Eav\Model\EavAttribute\Type;
use Weline\Eav\Model\EavEntity;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Exception;
use Weline\Framework\Exception\Core;
use Weline\Framework\Manager\ObjectManager;
use Weline\Admin\Controller\BaseController;

#[Acl('WeShop_Product::product', 'Products', 'mdi mdi-package-variant', 'Manage products', 'WeShop_Product::catalog')]
class Product extends BaseController
{
    private \WeShop\Product\Model\Product $product;

    public function __construct(
        \WeShop\Product\Model\Product $product
    ) {
        $this->product = $product;
        $this->product->loadLocalDescription();
    }

    #[Acl('WeShop_Product::product_index', 'View products', 'mdi mdi-package-search-outline', 'View product management page')]
    public function index(): string
    {
        $search = trim((string)$this->request->getGet('search', ''));
        if ($search !== '') {
            $likeSearch = '%' . $search . '%';
            $where = [
                ['main_table.' . ProductModel::schema_fields_sku, $likeSearch, 'LIKE', 'OR'],
                ['main_table.' . ProductModel::schema_fields_name, $likeSearch, 'LIKE', 'OR'],
                ['local.' . ProductModel::schema_fields_name, $likeSearch, 'LIKE', 'OR'],
            ];
            if (ctype_digit($search)) {
                array_unshift($where, ['main_table.' . ProductModel::schema_fields_ID, (int)$search, '=', 'OR']);
            }
            $this->product->where($where);
        }

        // 明确指定排序字段，使用表别名避免JOIN时的字段歧义
        $products = $this->product->order('main_table.' . ProductModel::schema_fields_ID, 'DESC')
            ->pagination()
            ->select()
            ->fetch();

        // 获取子产品数量统计
        $items = $products->getItems();
        $productIds = [];
        foreach ($items as $item) {
            $productIds[] = $item['product_id'] ?? ($item->getData('product_id') ?? 0);
        }
        $childrenCounts = [];

        if (!empty($productIds)) {
            // 查询每个产品的子产品数量
            $childrenData = $this->product->reset()
                ->fields('parent_id, COUNT(*) as children_count')
                ->where(ProductModel::schema_fields_parent_id, $productIds, 'in')
                ->group('parent_id')
                ->select()
                ->fetchArray();

            foreach ($childrenData as $row) {
                $childrenCounts[$row['parent_id']] = (int)$row['children_count'];
            }
        }

        // 将子产品数量附加到产品数据中（通过 setData 设置到模型对象）
        foreach ($items as $item) {
            $productId = $item['product_id'] ?? ($item->getData('product_id') ?? 0);
            $count = $childrenCounts[$productId] ?? 0;
            if (is_object($item) && method_exists($item, 'setData')) {
                $item->setData('children_count', $count);
            } elseif (is_array($item)) {
                $item['children_count'] = $count;
            }
        }

        $this->assign('products', $items);
        $this->assign('pagination', $products->getPagination());
        $this->assign('filterSearch', $search);
        return $this->fetch();
    }

    #[Acl('WeShop_Product::product_search_by', 'Search products', 'mdi mdi-magnify', 'Search product data')]
    public function searchBy(): string
    {
        $field = $this->request->getGet('field');
        $value = $this->request->getGet('value');
        // Whitelist allowed fields to prevent SQL injection
        $allowedFields = ['product_id', 'sku', 'name', 'status', 'price', 'stock'];
        if ($field && $value && in_array($field, $allowedFields, true)) {
            $product = $this->product
                ->where($field, $value)
                ->find()
                ->fetch();
            return $this->fetchJson($product->getData());
        } else {
            return $this->fetchJson([]);
        }
    }

    #[Acl('WeShop_Product::product_check_batch_sku', 'Check product SKUs', 'mdi mdi-barcode-scan', 'Check product SKU availability')]
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

    #[Acl('WeShop_Product::product_get_set_attributes', 'View product set attributes', 'mdi mdi-format-list-bulleted-type', 'View product attribute set attributes')]
    public function getSetAttributes()
    {
        $id = $this->request->getGet('id');
        $attributes = $this->product->eav_AttributeModel()
            ->joinModel(Group::class, 'group', 'main_table.group_id=group.group_id')
            ->joinModel(Type::class, 'type', 'main_table.type_id=type.type_id')
            ->where('main_table.' . Set::schema_fields_ID, $id)
            ->select()
            ->fetchArray();
        return $this->fetchJson($attributes);
    }

    #[Acl('WeShop_Product::product_get_set_group', 'View product set groups', 'mdi mdi-group', 'View product attribute set groups')]
    public function getSetGroup()
    {
        $id = $this->request->getGet('id');
        $groups = $this->product->eav_AttributeGroupModel()->where(Set::schema_fields_ID, $id)
            ->joinModel(Group::class, 'group', 'main_table.group_id=group.group_id')
            ->joinModel(Type::class, 'type', 'main_table.type_id=type.type_id')
            ->select()
            ->fetchArray();
        return $this->fetchJson($groups);
    }

    #[Acl('WeShop_Product::product_get_set_group_attributes', 'View product group attributes', 'mdi mdi-format-list-group', 'View product group attributes')]
    public function getSetGroupAttributes()
    {
        $id = $this->request->getGet('set_id');
        $groups = $this->product->eav_AttributeGroupModel()
            ->loadLocalDescription()
            ->where(Set::schema_fields_ID, $id)
            ->select()
            ->fetchArray();
        foreach ($groups as &$group) {
            $attributes = $this->product->reset()->eav_AttributeModel()
                ->where(EavAttribute::schema_fields_set_id, $group[Set::schema_fields_ID])
                ->where('main_table.' . EavAttribute::schema_fields_group_id, $group[EavAttribute\Group::schema_fields_ID])
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
     * 获取产品关联信息（子产品数量统计）
     */
    #[Acl('WeShop_Product::product_get_links_count', 'View linked product count', 'mdi mdi-link-variant', 'View linked product count')]
    public function getLinksCount(): string
    {
        $productId = (int)$this->request->getGet('product_id');
        if (!$productId) {
            return $this->fetchJson(['error' => '缺少产品ID']);
        }

        // 获取子产品数量
        $childrenCount = $this->product->reset()
            ->where(ProductModel::schema_fields_parent_id, $productId)
            ->count();

        return $this->fetchJson([
            'product_id' => $productId,
            'children' => $childrenCount,
            'grouped' => 0,  // 预留：组产品
            'bundle' => 0,   // 预留：绑定产品
            'related' => 0,  // 预留：关联产品
        ]);
    }

    /**
     * 获取子产品列表
     */
    #[Acl('WeShop_Product::product_get_children', 'View child products', 'mdi mdi-family-tree', 'View product child relation data')]
    public function getChildren(): string
    {
        $productId = (int)$this->request->getGet('product_id');
        if (!$productId) {
            return $this->fetchJson(['error' => '缺少产品ID', 'items' => []]);
        }

        $children = $this->product->reset()
            ->loadLocalDescription()
            ->where(ProductModel::schema_fields_parent_id, $productId)
            ->order('main_table.' . ProductModel::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();

        return $this->fetchJson([
            'product_id' => $productId,
            'total' => count($children),
            'items' => $children
        ]);
    }

}
