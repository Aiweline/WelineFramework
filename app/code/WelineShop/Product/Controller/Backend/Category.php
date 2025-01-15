<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Gvanda所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/7/11 20:55:21
 */

namespace Gvanda\Product\Controller\Backend;

use Weline\Framework\App\Exception;
use Weline\Framework\Exception\Core;

class Category extends \Weline\Framework\App\Controller\BackendController
{
    private \Gvanda\Product\Model\Category $category;

    public function __construct(
        \Gvanda\Product\Model\Category $category
    ) {
        $this->category = $category;
    }

    public function index()
    {
        $categories = $this->category->addLocalDescription()->pagination()->select()->fetch();
        $this->assign('categories', $categories->getItems());
        $this->assign('pagination', $categories->getPagination());
        return $this->fetch();
    }

    public function getSearch(): string
    {
        $id     = $this->request->getGet('id', 0);
        $field  = $this->request->getGet('field');
        $limit  = $this->request->getGet('limit');
        $search = $this->request->getGet('search');
        $json   = ['limit' => $limit, 'search' => $search];
        $this->category->addLocalDescription();
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
        $attributes    = $this->category->select()->fetchOrigin();
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
}
