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
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Data\WebsiteData;
use Weline\Websites\Model\Website;

#[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::category', '分类管理', 'mdi mdi-folder-outline', '管理博客分类')]
class Category extends BackendController
{
    private CategoryModel $categoryModel;
    private Website $websiteModel;

    public function __construct(CategoryModel $categoryModel)
    {
        $this->categoryModel = $categoryModel;
        $this->websiteModel = ObjectManager::getInstance(Website::class);
    }

    /**
     * 获取站点列表
     */
    private function getSiteOptions(): array
    {
        $websites = $this->websiteModel->clear()
            ->order(Website::schema_fields_NAME, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
        
        $options = [];
        foreach ($websites as $website) {
            $options[] = [
                'site_id' => $website->getWebsiteId(),
                'site_name' => $website->getName(),
                'site_url' => $website->getUrl(),
            ];
        }
        return $options;
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::category_index', '分类列表', 'mdi mdi-view-list', '查看博客分类列表', 'GuoLaiRen_Blog::category')]
    public function index()
    {
        $this->assign('page_title', __('分类管理'));
        $this->assign('breadcrumb_parent', __('博客管理'));
        $this->assign('breadcrumb_current', __('分类管理'));

        $listModel = clone $this->categoryModel;
        $listModel->clear();

        // 根据当前网站过滤
        $websiteId = WebsiteData::getWebsiteId();
        if ($websiteId) {
            $listModel->where(CategoryModel::schema_fields_SITE_ID, $websiteId);
        }

        if ($keyword = $this->request->getGet('search')) {
            $keyword = "%{$keyword}%";
            $listModel->where(CategoryModel::schema_fields_NAME, $keyword, 'like')
                ->where(CategoryModel::schema_fields_SLUG, $keyword, 'like', 'OR');
        }

        $categories = $listModel
            ->order(CategoryModel::schema_fields_ID, 'DESC')
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
        $websiteId = WebsiteData::getWebsiteId();
        $this->assign('parent_options', CategoryModel::getFlatCategoryList(0, $websiteId));
        $this->assign('sites', $this->getSiteOptions());

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

            // 获取当前网站ID（优先使用表单提交的，否则从WebsiteData获取）
            $websiteId = (int)($data['site_id'] ?? 0);
            if (!$websiteId) {
                $websiteId = (int)(WebsiteData::getWebsiteId() ?? 0);
            }

            // 别名唯一性校验（同一网站内唯一）
            $exists = clone $this->categoryModel;
            $exists->clear()
                ->where(CategoryModel::schema_fields_SLUG, $slug);
            if ($websiteId) {
                $exists->where(CategoryModel::schema_fields_SITE_ID, $websiteId);
            }
            $exists->find()->fetch();
            if ($exists->getId()) {
                throw new \Exception(__('URL别名已存在，请更换一个'));
            }

            $category = clone $this->categoryModel;
            $category->setData(CategoryModel::schema_fields_NAME, $name)
                ->setData(CategoryModel::schema_fields_SLUG, $slug)
                ->setData(CategoryModel::schema_fields_SITE_ID, $websiteId)
                ->setData(CategoryModel::schema_fields_DESCRIPTION, (string)($data['description'] ?? ''))
                ->setData(CategoryModel::schema_fields_COVER_IMAGE, (string)($data['cover_image'] ?? ''))
                ->setData(CategoryModel::schema_fields_PARENT_ID, (int)($data['parent_id'] ?? 0))
                ->setData(CategoryModel::schema_fields_SORT_ORDER, (int)($data['sort_order'] ?? 0))
                ->setData(CategoryModel::schema_fields_STATUS, (int)($data['status'] ?? CategoryModel::STATUS_ENABLED))
                ->setData(CategoryModel::schema_fields_META_TITLE, (string)($data['meta_title'] ?? ''))
                ->setData(CategoryModel::schema_fields_META_DESCRIPTION, (string)($data['meta_description'] ?? ''))
                ->setData(CategoryModel::schema_fields_META_KEYWORDS, (string)($data['meta_keywords'] ?? ''))
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
        $websiteId = WebsiteData::getWebsiteId();
        $this->assign('parent_options', CategoryModel::getFlatCategoryList($id, $websiteId));
        $this->assign('sites', $this->getSiteOptions());

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

            // 获取当前网站ID（优先使用表单提交的，否则使用分类现有数据，最后从WebsiteData获取）
            $websiteId = (int)($data['site_id'] ?? 0);
            if (!$websiteId) {
                $websiteId = (int)($category->getData(CategoryModel::schema_fields_SITE_ID) ?? 0);
            }
            if (!$websiteId) {
                $websiteId = (int)(WebsiteData::getWebsiteId() ?? 0);
            }

            // 别名唯一性校验（排除当前记录，同一网站内唯一）
            $exists = clone $this->categoryModel;
            $exists->clear()
                ->where(CategoryModel::schema_fields_SLUG, $slug)
                ->where(CategoryModel::schema_fields_ID, $id, '!=', 'AND');
            if ($websiteId) {
                $exists->where(CategoryModel::schema_fields_SITE_ID, $websiteId);
            }
            $exists->find()->fetch();
            if ($exists->getId()) {
                throw new \Exception(__('URL别名已存在，请更换一个'));
            }

            // 防止将自己设为父级
            $parentId = (int)($data['parent_id'] ?? 0);
            if ($parentId === $id) {
                throw new \Exception(__('不能将自己设为父级分类'));
            }

            $category->setData(CategoryModel::schema_fields_NAME, $name)
                ->setData(CategoryModel::schema_fields_SLUG, $slug)
                ->setData(CategoryModel::schema_fields_SITE_ID, $websiteId)
                ->setData(CategoryModel::schema_fields_DESCRIPTION, (string)($data['description'] ?? ''))
                ->setData(CategoryModel::schema_fields_COVER_IMAGE, (string)($data['cover_image'] ?? ''))
                ->setData(CategoryModel::schema_fields_PARENT_ID, $parentId)
                ->setData(CategoryModel::schema_fields_SORT_ORDER, (int)($data['sort_order'] ?? 0))
                ->setData(CategoryModel::schema_fields_STATUS, (int)($data['status'] ?? CategoryModel::STATUS_ENABLED))
                ->setData(CategoryModel::schema_fields_META_TITLE, (string)($data['meta_title'] ?? ''))
                ->setData(CategoryModel::schema_fields_META_DESCRIPTION, (string)($data['meta_description'] ?? ''))
                ->setData(CategoryModel::schema_fields_META_KEYWORDS, (string)($data['meta_keywords'] ?? ''))
                ->save();

            MessageManager::success(__('分类已保存'));

            $this->redirect('blog/backend/category/index');
        } catch (\Throwable $e) {
            MessageManager::error($e->getMessage());
            $this->redirect('blog/backend/category/index');
        }
    }

    // ─── Offcanvas 新建/编辑（iframe 内加载） ───

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::category_create', '新建分类(Offcanvas)', '', '', 'GuoLaiRen_Blog::category')]
    public function getOffcanvasCreate(): string
    {
        $this->assign('category', null);
        $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl('blog/backend/category/offcanvas-create'));
        $websiteId = WebsiteData::getWebsiteId();
        $this->assign('parent_options', CategoryModel::getFlatCategoryList(0, $websiteId));
        $this->assign('sites', $this->getSiteOptions());
        return $this->fetch('offcanvas_form');
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::category_create_post', '新建分类提交(Offcanvas)', '', '', 'GuoLaiRen_Blog::category')]
    public function postOffcanvasCreate(): void
    {
        try {
            $this->saveCategory();
            MessageManager::success(__('分类已创建'));
        } catch (\Throwable $e) {
            MessageManager::error($e->getMessage());
        }
        $this->redirect('blog/backend/category/offcanvas-create');
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::category_edit', '编辑分类(Offcanvas)', '', '', 'GuoLaiRen_Blog::category')]
    public function getOffcanvasEdit(): string
    {
        $id       = (int)$this->request->getGet('id', 0);
        $category = clone $this->categoryModel;
        $category->clear()->load($id);

        if (!$category->getId()) {
            MessageManager::error(__('分类不存在'));
            return $this->fetch('offcanvas_form');
        }

        $this->assign('category', $category);
        $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl('blog/backend/category/offcanvas-edit', ['id' => $id]));
        $websiteId = WebsiteData::getWebsiteId();
        $this->assign('parent_options', CategoryModel::getFlatCategoryList($id, $websiteId));
        $this->assign('sites', $this->getSiteOptions());
        return $this->fetch('offcanvas_form');
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::category_edit_post', '编辑分类提交(Offcanvas)', '', '', 'GuoLaiRen_Blog::category')]
    public function postOffcanvasEdit(): void
    {
        $id = (int)$this->request->getGet('id', 0);
        try {
            $category = clone $this->categoryModel;
            $category->clear()->load($id);
            if (!$category->getId()) {
                throw new \Exception(__('分类不存在'));
            }
            $this->saveCategory($category);
            MessageManager::success(__('分类已保存'));
        } catch (\Throwable $e) {
            MessageManager::error($e->getMessage());
        }
        $this->redirect('blog/backend/category/offcanvas-edit', ['id' => $id]);
    }

    /**
     * 提取公共保存逻辑
     */
    private function saveCategory(?CategoryModel $category = null): void
    {
        $data = $this->request->getPost();

        $name = trim((string)($data['name'] ?? ''));
        $slug = trim((string)($data['slug'] ?? ''));
        if ($name === '' || $slug === '') {
            throw new \Exception(__('名称和URL别名不能为空'));
        }

        $websiteId = (int)($data['site_id'] ?? 0);
        if (!$websiteId && $category) {
            $websiteId = (int)($category->getData(CategoryModel::schema_fields_SITE_ID) ?? 0);
        }
        if (!$websiteId) {
            $websiteId = (int)(WebsiteData::getWebsiteId() ?? 0);
        }

        // 别名唯一性校验（同一网站内唯一）
        $exists = clone $this->categoryModel;
        $exists->clear()->where(CategoryModel::schema_fields_SLUG, $slug);
        if ($category && $category->getId()) {
            $exists->where(CategoryModel::schema_fields_ID, $category->getId(), '!=', 'AND');
        }
        if ($websiteId) {
            $exists->where(CategoryModel::schema_fields_SITE_ID, $websiteId);
        }
        $exists->find()->fetch();
        if ($exists->getId()) {
            throw new \Exception(__('URL别名已存在，请更换一个'));
        }

        $parentId = (int)($data['parent_id'] ?? 0);
        if ($category && $parentId === (int)$category->getId()) {
            throw new \Exception(__('不能将自己设为父级分类'));
        }

        if ($category === null) {
            $category = clone $this->categoryModel;
        }

        $category->setData(CategoryModel::schema_fields_NAME, $name)
            ->setData(CategoryModel::schema_fields_SLUG, $slug)
            ->setData(CategoryModel::schema_fields_SITE_ID, $websiteId)
            ->setData(CategoryModel::schema_fields_DESCRIPTION, (string)($data['description'] ?? ''))
            ->setData(CategoryModel::schema_fields_COVER_IMAGE, (string)($data['cover_image'] ?? ''))
            ->setData(CategoryModel::schema_fields_PARENT_ID, $parentId)
            ->setData(CategoryModel::schema_fields_SORT_ORDER, (int)($data['sort_order'] ?? 0))
            ->setData(CategoryModel::schema_fields_STATUS, (int)($data['status'] ?? CategoryModel::STATUS_ENABLED))
            ->setData(CategoryModel::schema_fields_META_TITLE, (string)($data['meta_title'] ?? ''))
            ->setData(CategoryModel::schema_fields_META_DESCRIPTION, (string)($data['meta_description'] ?? ''))
            ->setData(CategoryModel::schema_fields_META_KEYWORDS, (string)($data['meta_keywords'] ?? ''))
            ->save();
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

            // 检查是否有子分类（同网站内）
            $childCount = clone $this->categoryModel;
            $query = $childCount->clear()
                ->where(CategoryModel::schema_fields_PARENT_ID, $id);
            $siteId = $category->getData(CategoryModel::schema_fields_SITE_ID);
            if ($siteId) {
                $query->where(CategoryModel::schema_fields_SITE_ID, $siteId);
            }
            $count = $query->count();
            if ($count > 0) {
                throw new \Exception(__('该分类下还有子分类，无法删除'));
            }

            // 检查是否有文章
            if ($category->getPostCount() > 0) {
                throw new \Exception(__('该分类下还有文章，无法删除'));
            }

            $category->delete()->fetch();
            MessageManager::success(__('分类已删除'));
        } catch (\Throwable $e) {
            MessageManager::error($e->getMessage());
        }

        $this->redirect('blog/backend/category/index');
    }
}
