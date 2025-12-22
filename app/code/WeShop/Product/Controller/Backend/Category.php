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

use Weline\Eav\Model\EavAttribute\Set;
use Weline\Framework\App\Exception;
use Weline\Framework\Exception\Core;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Product\Model\Product;

class Category extends \Weline\Framework\App\Controller\BackendController
{
    private \WeShop\Product\Model\Category $category;
    private Product $product;

    public function __construct(
        \WeShop\Product\Model\Category $category,
        Product $product
    )
    {
        $this->category = $category;
        $this->product = $product;
    }

    public function index()
    {
        $categories = $this->category
            ->loadLocalDescription()
            ->pagination()
            ->select()
            ->fetch();
        # 为父分类添加名称
        // 为父分类添加名称（带缓存，避免重复查询）
        $parentCache = [];
        $items = $categories->getItems();
        $this->category->loadLocalDescription();
        foreach ($items as $i => $category) {
            $pid = $category->getPid();
            if ($pid > 0) {
                if (!isset($parentCache[$pid])) {
                    $parent = $this->category->load($pid);
                    $parentCache[$pid] = $parent->getId() ? $parent->getLocalName() : __('无');
                }
                $category->setData('parent_name', $parentCache[$pid]);
            } else {
                $category->setData('parent_name', __('无'));
            }
            $items[$i] = $category;
        }
        $this->assign('categories', $items);
        $this->assign('pagination', $categories->getPagination());
        return $this->fetch();
    }

    public function getSearch(): string
    {
        $id = $this->request->getGet('id', 0);
        $field = $this->request->getGet('field');
        $limit = $this->request->getGet('limit');
        $search = $this->request->getGet('search');
        $json = ['limit' => $limit, 'search' => $search];
        $this->category->loadLocalDescription();
        $this->category->where('main_table.category_id', $id, '!=', 'and');
        if ($field && $search) {
            $this->category->where('main_table.' . $field, "%{$search}%", 'like', 'or')
                ->where('local.' . $field, "%{$search}%", 'like');
            if ($limit) {
                $this->category->limit(1);
            } else {
                $this->category->limit(100);
            }
        } elseif (empty($field) && $search) {
            $this->category->where('main_table.name', "%{$search}%", 'like', 'or')
                ->where('local.name', "%{$search}%", 'like');
        }
        $attributes = $this->category->select()->fetchArray();
        $json['items'] = $attributes;
        return $this->fetchJson($json);
    }

    public function edit()
    {
        if ($this->request->isGet()) {
            if ($this->request->getGet('id', 0) == 1) {
                die(__('根分类不能修改'));
            }
            $this->assign('action', $this->request->getUrlBuilder()->getCurrentUrl());
            $category = $this->category->where('main_table.' . $this->category::fields_ID, $this->request->getGet('id', 0))
                ->joinModel($this->category, 'c', 'main_table.category_id=c.pid', 'left', 'c.name as parent_name,c.category_id as parent_id')
                ->find()->fetch();
            if (!$category->getId()) {
                die(__('分类找不到！'));
            }
            $this->assign('category', $category);
            // 加载产品实体的所有属性集
            $sets = $this->product->eav_AttributeSetModel()->select()->fetchArray();
            $this->assign('attribute_sets', $sets);
            return $this->fetch('form');
        }

        $category_data = $this->request->getPost();
        try {
            if (intval($category_data['category_id']) == 1) {
                $this->getMessageManager()->addWarning(__('根分类不能修改'));
            } else {
                $this->category->setModelData($category_data)->save();
                $this->getMessageManager()->addSuccess(__('分类保存成功！'));
            }
        } catch (\Exception $e) {
            $this->getMessageManager()->addError(__('分类保存失败！请检查参数！'));
            $this->assign('category', $category_data);
            if (DEV || DEBUG) {
                $this->getMessageManager()->addException($e);
            }
        }
        $this->redirect('*/backend/category/edit', ['id' => $category_data['category_id']]);
    }

    public function add()
    {
        if ($this->request->isGet()) {
            $this->assign('action', $this->request->getUrlBuilder()->getCurrentUrl());
            // 加载产品实体的所有属性集
            $sets = $this->product->eav_AttributeSetModel()->select()->fetchArray();
            $this->assign('attribute_sets', $sets);
            return $this->fetch('form');
        }

        $category_data = $this->request->getPost();
        try {
            $this->category->reset()->setModelData($category_data)->save();
            $this->getMessageManager()->addSuccess(__('分类保存成功！'));
            $this->redirect('*/backend/category/edit', ['id' => $this->category->getId()]);
        } catch (\Exception $e) {
            $this->getMessageManager()->addError(__('分类保存失败！请检查参数！'));
            $this->assign('category', $category_data);
            if (DEV || DEBUG) {
                $this->getMessageManager()->addException($e);
            }
        }
        $this->redirect('*/backend/category/add');
    }

    public function getDelete()
    {
        $id = $this->request->getGet('id', 0);
        $this->category->load($id);
        if (!$this->category->getId()) {
            $this->getMessageManager()->addWarning(__('该分类不存在！'));
            $this->redirect('*/backend/category');
        }

        try {
            $this->category->delete();
            $this->getMessageManager()->addSuccess(__('分类删除成功！'));
        } catch (\ReflectionException|Exception|Core $e) {
            $this->getMessageManager()->addException($e);
        }
        $this->redirect('*/backend/category');
    }

    /**
     * 获取分类的默认属性集
     * @return string
     */
    public function getGetDefaultSet(): string
    {
        $categoryId = (int)$this->request->getGet('category_id', 0);
        $result = ['default_set_id' => 0, 'set_name' => ''];
        
        if ($categoryId > 0) {
            $category = $this->category->reset()->load($categoryId);
            if ($category->getId()) {
                $defaultSetId = $category->getDefaultSetId();
                if ($defaultSetId > 0) {
                    $set = $category->getDefaultSet();
                    $result = [
                        'default_set_id' => $defaultSetId,
                        'set_name' => $set ? $set->getName() : ''
                    ];
                }
            }
        }
        
        return $this->fetchJson($result);
    }
}
