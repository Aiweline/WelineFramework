<?php

namespace WeShop\Product\Controller\Backend\Product;

use Weline\Backend\Model\BackendUserData;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Set;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\App\Exception;
use Weline\Framework\Exception\Core;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Product\Model\Category;
use WeShop\Product\Model\Product\OptionId;

#[Acl('WeShop_Product::product_add', 'Product add actions', 'mdi mdi-package-variant-plus', 'Create products', 'WeShop_Product::product')]
class Add extends BackendController
{
    private \WeShop\Product\Model\Product $product;

    public function __construct(
        \WeShop\Product\Model\Product $product
    )
    {
        $this->product = $product;
        $this->product->loadLocalDescription();
    }

    #[Acl('WeShop_Product::product_add_index', 'Add product', 'mdi mdi-package-variant-plus', 'Open product creation page')]
    public function index()
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
                $set = $this->product->eav_AttributeSetModel()->where(Set::schema_fields_ID, $product['set_id'])->find()->fetch();
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
            // 产品分类
            $this->assign('productCategoryIds', []);
            // 分类
            $categories = ObjectManager::getInstance(Category::class)
                ->loadLocalDescription()
                ->select()->fetchArray();
            $this->assign('categories', $categories);
            return $this->fetch();
        }
        # post请求保存
        if ($this->request->isPost()) {
            $data = $this->request->getPost();
            $eav_entity_id = $this->product->getEavEntityId();
            $data['image'] = trim($data['image'], ',');
            $data['parent_id'] = 0;
            $this->product->setModelFieldsData($data);
            # 创建主产品
            try {
                $product_id = $this->product->save();
                # 保存产品分类
                $productCategoryIds = $data['category_ids'] ?? [];
                if ($product_id and $productCategoryIds) {
                    $productCategoryModel = ObjectManager::getInstance(\WeShop\Product\Model\ProductCategory::class);
                    # 查询分类
                    $productCategoryModel->setCategoryIdsByProductId($product_id, $productCategoryIds);
                }
                # 保存属性数据
                $attributes = $this->request->getPost('attribute');
                if ($attributes) {
                    list($attributeValues, $attributeDataItems) = $this->checkAttributes($attributes, $eav_entity_id);
                    /**@var EavAttribute $attributeDataItem */
                    foreach ($attributeDataItems as $attributeDataItem) {
                        # 先删除属性原值
                        $attributeDataItem->current_setEntity($this->product);
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

            # 检测是否有可配置产品f
            if (isset($data['configurable'])) {
                $configurableAttributes = $data['configurable'];
                $configurableProductItemsStr = $data['configurableProductItems'] ?? '[]';
                unset($data['configurableProductItems']);
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
                        // 设置分类
                        $productCategoryIds = $configurableProduct['category_ids'] ?? [];
                        if ($child_product_id and $productCategoryIds) {
                            $productCategoryModel->setCategoryIdsByProductId($child_product_id, $productCategoryIds);
                        }
                        $child_product = $this->product->reset()->clearData()->load($child_product_id); 
                    } catch (\Throwable $throwable) {
                        $this->getMessageManager()->addError(__('保存失败,请重试!') . (DEV ? $throwable->getMessage() : ''));
                        $this->request->isGet(true);
                        return $this->add();
                    }
                    # 可配置属性设置
                    /**@var OptionId $optionModel */
                    $optionModel = ObjectManager::getInstance(OptionId::class);
                    list($attributeValues, $attributeDataItems) = $this->checkAttributes($configurableAttributes, $eav_entity_id);
                    /**@var EavAttribute $attributeDataItem */
                    foreach ($attributeDataItems as $attributeDataItem) {
                        # 先删除属性原值 FIXME 属性没有实体
                        $attributeDataItem->current_setEntity($child_product);
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
            /**@var BackendUserData $UserData */
            $UserData = ObjectManager::getInstance(BackendUserData::class);
            try {
                $UserData->reset()->clearData()
                    ->where('backend_user_id', $this->session->getLoginUserID())
                    ->where('scope', 'product')
                    ->delete()
                    ->fetch();
            } catch (\ReflectionException|Exception|Core $e) {
            }
            $this->redirect('/component/offcanvas/success', ['msg' => '保存成功', 'reload' => 1]);
        }
    }


    /**
     * @param mixed $attributes
     * @param int $eav_entity_id
     * @return array
     * @throws \ReflectionException
     * @throws \Weline\Framework\App\Exception
     */
    #[Acl('WeShop_Product::product_add_check_attributes', 'Validate product attributes', 'mdi mdi-clipboard-check-outline', 'Validate product attributes during creation')]
    public function checkAttributes(array $attributes, int $eav_entity_id): array
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
            ->where($attribute::schema_fields_attribute_id, $attributeIds, 'in')
            ->where($attribute::schema_fields_eav_entity_id, $eav_entity_id)
            ->select()
            ->fetch()
            ->getItems();
        if (count($attributeIds) !== count($attributeDataItems)) {
            throw new Exception(__('部分属性不存在'));
        }
        return array($attributeValues, $attributeDataItems);
    }

}
