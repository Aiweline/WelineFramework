<?php

declare(strict_types=1);

/*
 * GuoLaiRen Blog Module
 * 博客分类后台管理控制器
 */

namespace GuoLaiRen\Blog\Controller\Backend;

use GuoLaiRen\Blog\Model\Category as CategoryModel;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\MessageManager;

#[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::category', '分类管理', 'mdi mdi-folder-outline', '管理博客分类')]
class Category extends BackendController
{
    private CategoryModel $categoryModel;

    public function __construct(CategoryModel $categoryModel)
    {
        $this->categoryModel = $categoryModel;
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::category_index', '分类列表', 'mdi mdi-view-list', '查看博客分类列表', 'GuoLaiRen_Blog::category')]
    public function index()
    {
        $this->assign('page_title', __('分类管理'));
        $this->assign('breadcrumb_parent', __('博客管理'));
        $this->assign('breadcrumb_current', __('分类管理'));

        $listModel = clone $this->categoryModel;
        $listModel->clear();

        if ($keyword = $this->request->getGet('search')) {
            $keyword = "%{$keyword}%";
            $listModel->where(CategoryModel::fields_NAME, $keyword, 'like')
                ->where(CategoryModel::fields_SLUG, $keyword, 'like', 'OR');
        }

        $categories = $listModel
            ->order(CategoryModel::fields_SORT_ORDER, 'ASC')
            ->order(CategoryModel::fields_ID, 'ASC')
            ->pagination()
            ->select()
            ->fetch();

        $this->assign('categories', $categories->getItems());
        $this->assign('pagination', $categories->getPagination());

        return $this->fetch('index');
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::category_create', '新建分类', 'mdi mdi-plus', '新建博客分类', 'GuoLaiRen_Blog::category')]
    public function getCreate()
    {
        $this->assign('page_title', __('新建分类'));
        $this->assign('breadcrumb_parent', __('分类管理'));
        $this->assign('breadcrumb_current', __('新建分类'));
        $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl('blog/backend/category/create'));
        $this->assign('category', null);
        $this->assign('parent_options', CategoryModel::getFlatCategoryList());

        return $this->fetch('form');
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::category_create_post', '新建分类请求', '', '新建分类请求', 'GuoLaiRen_Blog::category')]
    public function postCreate()
    {
        try {
            $data = $this->request->getPost();

            $name = trim((string)($data['name'] ?? ''));
            $slug = trim((string)($data['slug'] ?? ''));

            if ($name === '' || $slug === '') {
                throw new \Exception(__('名称和URL别名不能为空'));
            }

            // 别名唯一性校验
            $exists = clone $this->categoryModel;
            $exists->clear()
                ->where(CategoryModel::fields_SLUG, $slug)
                ->find()
                ->fetch();
            if ($exists->getId()) {
                throw new \Exception(__('URL别名已存在，请更换一个'));
            }

            $category = clone $this->categoryModel;
            $category->setData(CategoryModel::fields_NAME, $name)
                ->setData(CategoryModel::fields_SLUG, $slug)
                ->setData(CategoryModel::fields_DESCRIPTION, (string)($data['description'] ?? ''))
                ->setData(CategoryModel::fields_COVER_IMAGE, (string)($data['cover_image'] ?? ''))
                ->setData(CategoryModel::fields_PARENT_ID, (int)($data['parent_id'] ?? 0))
                ->setData(CategoryModel::fields_SORT_ORDER, (int)($data['sort_order'] ?? 0))
                ->setData(CategoryModel::fields_STATUS, (int)($data['status'] ?? CategoryModel::STATUS_ENABLED))
                ->setData(CategoryModel::fields_META_TITLE, (string)($data['meta_title'] ?? ''))
                ->setData(CategoryModel::fields_META_DESCRIPTION, (string)($data['meta_description'] ?? ''))
                ->setData(CategoryModel::fields_META_KEYWORDS, (string)($data['meta_keywords'] ?? ''))
                ->save();

            MessageManager::success(__('分类已创建'));

            $this->redirect('blog/backend/category/index');
        } catch (\Throwable $e) {
            MessageManager::error($e->getMessage());
            $this->redirect('blog/backend/category/create');
        }
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::category_edit', '编辑分类', 'mdi mdi-pencil', '编辑博客分类', 'GuoLaiRen_Blog::category')]
    public function getEdit()
    {
        $id = (int)$this->request->getGet('id', 0);

        $category = clone $this->categoryModel;
        $category->clear()->load($id);

        if (!$category->getId()) {
            MessageManager::error(__('分类不存在'));
            $this->redirect('blog/backend/category/index');
            return;
        }

        $this->assign('page_title', __('编辑分类'));
        $this->assign('breadcrumb_parent', __('分类管理'));
        $this->assign('breadcrumb_current', __('编辑分类'));
        $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl('blog/backend/category/edit', ['id' => $id]));
        $this->assign('category', $category);
        $this->assign('parent_options', CategoryModel::getFlatCategoryList($id));

        return $this->fetch('form');
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::category_edit_post', '编辑分类请求', '', '编辑分类请求', 'GuoLaiRen_Blog::category')]
    public function postEdit()
    {
        try {
            $id   = (int)$this->request->getGet('id', 0);
            $data = $this->request->getPost();

            $category = clone $this->categoryModel;
            $category->clear()->load($id);

            if (!$category->getId()) {
                throw new \Exception(__('分类不存在'));
            }

            $name = trim((string)($data['name'] ?? ''));
            $slug = trim((string)($data['slug'] ?? ''));

            if ($name === '' || $slug === '') {
                throw new \Exception(__('名称和URL别名不能为空'));
            }

            // 别名唯一性校验（排除当前记录）
            $exists = clone $this->categoryModel;
            $exists->clear()
                ->where(CategoryModel::fields_SLUG, $slug)
                ->where(CategoryModel::fields_ID, $id, '!=', 'AND')
                ->find()
                ->fetch();
            if ($exists->getId()) {
                throw new \Exception(__('URL别名已存在，请更换一个'));
            }

            // 防止将自己设为父级
            $parentId = (int)($data['parent_id'] ?? 0);
            if ($parentId === $id) {
                throw new \Exception(__('不能将自己设为父级分类'));
            }

            $category->setData(CategoryModel::fields_NAME, $name)
                ->setData(CategoryModel::fields_SLUG, $slug)
                ->setData(CategoryModel::fields_DESCRIPTION, (string)($data['description'] ?? ''))
                ->setData(CategoryModel::fields_COVER_IMAGE, (string)($data['cover_image'] ?? ''))
                ->setData(CategoryModel::fields_PARENT_ID, $parentId)
                ->setData(CategoryModel::fields_SORT_ORDER, (int)($data['sort_order'] ?? 0))
                ->setData(CategoryModel::fields_STATUS, (int)($data['status'] ?? CategoryModel::STATUS_ENABLED))
                ->setData(CategoryModel::fields_META_TITLE, (string)($data['meta_title'] ?? ''))
                ->setData(CategoryModel::fields_META_DESCRIPTION, (string)($data['meta_description'] ?? ''))
                ->setData(CategoryModel::fields_META_KEYWORDS, (string)($data['meta_keywords'] ?? ''))
                ->save();

            MessageManager::success(__('分类已保存'));

            $this->redirect('blog/backend/category/index');
        } catch (\Throwable $e) {
            MessageManager::error($e->getMessage());
            $this->redirect('blog/backend/category/index');
        }
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::category_delete', '删除分类', 'mdi mdi-delete', '删除博客分类', 'GuoLaiRen_Blog::category')]
    public function delete()
    {
        try {
            $id = (int)$this->request->getGet('id', 0);

            $category = clone $this->categoryModel;
            $category->clear()->load($id);

            if (!$category->getId()) {
                throw new \Exception(__('分类不存在'));
            }

            // 检查是否有子分类
            $childCount = clone $this->categoryModel;
            $childCount->clear()
                ->where(CategoryModel::fields_PARENT_ID, $id)
                ->count();
            if ($childCount > 0) {
                throw new \Exception(__('该分类下还有子分类，无法删除'));
            }

            // 检查是否有文章
            if ($category->getPostCount() > 0) {
                throw new \Exception(__('该分类下还有文章，无法删除'));
            }

            $category->delete();
            MessageManager::success(__('分类已删除'));
        } catch (\Throwable $e) {
            MessageManager::error($e->getMessage());
        }

        $this->redirect('blog/backend/category/index');
    }
}
