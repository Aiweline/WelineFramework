<?php

declare(strict_types=1);

/*
 * GuoLaiRen Blog Module
 * 博客后台管理控制器
 */

namespace GuoLaiRen\Blog\Controller\Backend;

use GuoLaiRen\Blog\Model\Category;
use GuoLaiRen\Blog\Model\Post as PostModel;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Multipass\Model\MultipassSite;

#[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::blog', '博客管理', 'mdi mdi-notebook-outline', '管理博客文章')]
class Post extends BackendController
{
    private PostModel $postModel;
    private MultipassSite $siteModel;

    public function __construct(PostModel $postModel)
    {
        $this->postModel = $postModel;
        $this->siteModel = ObjectManager::getInstance(MultipassSite::class);
    }

    /**
     * 获取分类选项
     */
    private function getCategoryOptions(): array
    {
        return Category::getFlatCategoryList();
    }

    /**
     * 获取启用的站点列表
     */
    private function getSiteOptions(): array
    {
        $sites = $this->siteModel->clear()
            ->where(MultipassSite::fields_IS_ENABLED, 1)
            ->order(MultipassSite::fields_SITE_NAME, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
        
        $options = [];
        foreach ($sites as $site) {
            $options[] = [
                'site_id' => $site->getId(),
                'site_name' => $site->getSiteName(),
                'site_url' => $site->getSiteUrl(),
            ];
        }
        return $options;
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::blog_index', '博客列表', 'mdi mdi-view-list', '查看博客文章列表', 'GuoLaiRen_Blog::blog')]
    public function index()
    {
        $this->assign('page_title', __('博客管理'));
        $this->assign('breadcrumb_parent', __('内容管理'));
        $this->assign('breadcrumb_current', __('博客管理'));

        $listModel = clone $this->postModel;
        $listModel->clear();

        if ($keyword = $this->request->getGet('search')) {
            $keyword = "%{$keyword}%";
            $listModel->where(PostModel::fields_TITLE, $keyword, 'like')
                ->where(PostModel::fields_SLUG, $keyword, 'like', 'OR');
        }

        $posts = $listModel
            ->order(PostModel::fields_PUBLISHED_AT, 'DESC')
            ->pagination()
            ->select()
            ->fetch();

        $this->assign('posts', $posts->getItems());
        $this->assign('pagination', $posts->getPagination());

        return $this->fetch('index');
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::blog_create', '新建博客', 'mdi mdi-plus', '新建博客文章', 'GuoLaiRen_Blog::blog')]
    public function getCreate()
    {
        $this->assign('page_title', __('新建博客'));
        $this->assign('breadcrumb_parent', __('博客管理'));
        $this->assign('breadcrumb_current', __('新建博客'));
        $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl('blog/backend/post/create'));
        $this->assign('post', null);
        $this->assign('categories', $this->getCategoryOptions());
        $this->assign('sites', $this->getSiteOptions());

        return $this->fetch('form');
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::blog_create_post', '新建博客请求', '', '新建博客请求', 'GuoLaiRen_Blog::blog')]
    public function postCreate()
    {
        try {
            $data = $this->request->getPost();

            $title = trim((string)($data['title'] ?? ''));
            $slug  = trim((string)($data['slug'] ?? ''));

            if ($title === '' || $slug === '') {
                throw new \Exception(__('标题和URL别名不能为空'));
            }

            // 别名唯一性校验
            $exists = clone $this->postModel;
            $exists->clear()
                ->where(PostModel::fields_SLUG, $slug)
                ->find()
                ->fetch();
            if ($exists->getId()) {
                throw new \Exception(__('URL别名已存在，请更换一个'));
            }

            $status       = (int)($data['status'] ?? PostModel::STATUS_DRAFT);
            $published_at = $data['published_at'] ?? '';
            if ($status === PostModel::STATUS_PUBLISHED && $published_at === '') {
                $published_at = date('Y-m-d H:i:s');
            }

            $post = clone $this->postModel;
            $post->setData(PostModel::fields_TITLE, $title)
                ->setData(PostModel::fields_SLUG, $slug)
                ->setData(PostModel::fields_SITE_ID, (int)($data['site_id'] ?? 0))
                ->setData(PostModel::fields_SUMMARY, (string)($data['summary'] ?? ''))
                ->setData(PostModel::fields_CONTENT, (string)($data['content'] ?? ''))
                ->setData(PostModel::fields_COVER_IMAGE, (string)($data['cover_image'] ?? ''))
                ->setData(PostModel::fields_CATEGORY_ID, (int)($data['category_id'] ?? 0))
                ->setData(PostModel::fields_AUTHOR, (string)($data['author'] ?? ''))
                ->setData(PostModel::fields_TAGS, (string)($data['tags'] ?? ''))
                ->setData(PostModel::fields_IS_FEATURED, isset($data['is_featured']) ? 1 : 0)
                ->setData(PostModel::fields_STATUS, $status)
                ->setData(PostModel::fields_PUBLISHED_AT, $published_at)
                ->save();

            MessageManager::success(__('博客文章已创建'));

            $this->redirect('blog/backend/post/index');
        } catch (\Throwable $e) {
            MessageManager::error($e->getMessage());
            $this->redirect('blog/backend/post/create');
        }
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::blog_edit', '编辑博客', 'mdi mdi-pencil', '编辑博客文章', 'GuoLaiRen_Blog::blog')]
    public function getEdit()
    {
        $id = (int)$this->request->getGet('id', 0);

        $post = clone $this->postModel;
        $post->clear()->load($id);

        if (!$post->getId()) {
            MessageManager::error(__('博客文章不存在'));
            $this->redirect('blog/backend/post/index');
            return;
        }

        $this->assign('page_title', __('编辑博客'));
        $this->assign('breadcrumb_parent', __('博客管理'));
        $this->assign('breadcrumb_current', __('编辑博客'));
        $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl('blog/backend/post/edit', ['id' => $id]));
        $this->assign('post', $post);
        $this->assign('categories', $this->getCategoryOptions());
        $this->assign('sites', $this->getSiteOptions());

        return $this->fetch('form');
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::blog_edit_post', '编辑博客请求', '', '编辑博客请求', 'GuoLaiRen_Blog::blog')]
    public function postEdit()
    {
        try {
            $id   = (int)$this->request->getGet('id', 0);
            $data = $this->request->getPost();

            $post = clone $this->postModel;
            $post->clear()->load($id);

            if (!$post->getId()) {
                throw new \Exception(__('博客文章不存在'));
            }

            $title = trim((string)($data['title'] ?? ''));
            $slug  = trim((string)($data['slug'] ?? ''));

            if ($title === '' || $slug === '') {
                throw new \Exception(__('标题和URL别名不能为空'));
            }

            // 别名唯一性校验（排除当前记录）
            $exists = clone $this->postModel;
            $exists->clear()
                ->where(PostModel::fields_SLUG, $slug)
                ->where(PostModel::fields_ID, $id, '!=', 'AND')
                ->find()
                ->fetch();
            if ($exists->getId()) {
                throw new \Exception(__('URL别名已存在，请更换一个'));
            }

            $status       = (int)($data['status'] ?? PostModel::STATUS_DRAFT);
            $published_at = $data['published_at'] ?? '';
            if ($status === PostModel::STATUS_PUBLISHED && $published_at === '') {
                $published_at = date('Y-m-d H:i:s');
            }

            $post->setData(PostModel::fields_TITLE, $title)
                ->setData(PostModel::fields_SLUG, $slug)
                ->setData(PostModel::fields_SITE_ID, (int)($data['site_id'] ?? 0))
                ->setData(PostModel::fields_SUMMARY, (string)($data['summary'] ?? ''))
                ->setData(PostModel::fields_CONTENT, (string)($data['content'] ?? ''))
                ->setData(PostModel::fields_COVER_IMAGE, (string)($data['cover_image'] ?? ''))
                ->setData(PostModel::fields_CATEGORY_ID, (int)($data['category_id'] ?? 0))
                ->setData(PostModel::fields_AUTHOR, (string)($data['author'] ?? ''))
                ->setData(PostModel::fields_TAGS, (string)($data['tags'] ?? ''))
                ->setData(PostModel::fields_IS_FEATURED, isset($data['is_featured']) ? 1 : 0)
                ->setData(PostModel::fields_STATUS, $status)
                ->setData(PostModel::fields_PUBLISHED_AT, $published_at)
                ->save();

            MessageManager::success(__('博客文章已保存'));

            $this->redirect('blog/backend/post/index');
        } catch (\Throwable $e) {
            MessageManager::error($e->getMessage());
            $this->redirect('blog/backend/post/index');
        }
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::blog_delete', '删除博客', 'mdi mdi-delete', '删除博客文章', 'GuoLaiRen_Blog::blog')]
    public function delete()
    {
        try {
            $id = (int)$this->request->getGet('id', 0);

            $post = clone $this->postModel;
            $post->clear()->load($id);

            if (!$post->getId()) {
                throw new \Exception(__('博客文章不存在'));
            }

            $post->delete();
            MessageManager::success(__('博客文章已删除'));
        } catch (\Throwable $e) {
            MessageManager::error($e->getMessage());
        }

        $this->redirect('blog/backend/post/index');
    }
}

