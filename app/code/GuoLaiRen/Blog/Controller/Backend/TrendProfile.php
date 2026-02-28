<?php

declare(strict_types=1);

/*
 * GuoLaiRen Blog Module
 * 趋势关键词画像后台管理
 */

namespace GuoLaiRen\Blog\Controller\Backend;

use GuoLaiRen\Blog\Model\TrendProfile as TrendProfileModel;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;

#[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::trend_profile', '关键词画像', 'mdi mdi-tag-multiple-outline', '趋势关键词画像管理', 'GuoLaiRen_Blog::blog_menu')]
class TrendProfile extends BackendController
{
    private TrendProfileModel $profileModel;

    public function __construct(TrendProfileModel $profileModel)
    {
        $this->profileModel = $profileModel;
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::trend_profile_index', '画像列表', 'mdi mdi-view-list', '查看关键词画像列表', 'GuoLaiRen_Blog::trend_profile')]
    public function index(): string
    {
        $listModel = clone $this->profileModel;
        $listModel->clear();

        if ($search = trim((string)$this->request->getGet('search', ''))) {
            $listModel->where(TrendProfileModel::fields_NAME, '%' . $search . '%', 'like');
        }

        $items = $listModel
            ->order(TrendProfileModel::fields_SORT, 'ASC')
            ->order(TrendProfileModel::fields_ID, 'DESC')
            ->pagination()
            ->select()
            ->fetch();

        $this->assign('profiles', $items->getItems());
        $this->assign('pagination', $items->getPagination());
        $this->assign('page_title', __('关键词画像'));
        $this->assign('breadcrumb_parent', __('博客管理'));
        $this->assign('breadcrumb_current', __('关键词画像'));

        return $this->fetch('index');
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::trend_profile_create', '新建画像', 'mdi mdi-plus', '新建关键词画像', 'GuoLaiRen_Blog::trend_profile')]
    public function getCreate(): string
    {
        $this->assign('profile', null);
        $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl('blog/backend/trend-profile/create'));
        $this->assign('page_title', __('新建关键词画像'));
        $this->assign('breadcrumb_parent', __('关键词画像'));
        $this->assign('breadcrumb_current', __('新建'));
        return $this->fetch('form');
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::trend_profile_create_post', '新建画像提交', '', '', 'GuoLaiRen_Blog::trend_profile')]
    public function postCreate()
    {
        try {
            $data = $this->request->getPost();
            $name = trim((string)($data['name'] ?? ''));
            if ($name === '') {
                throw new \Exception(__('画像名称不能为空'));
            }

            $keywords = (string)($data['keywords'] ?? '');
            $keywordsArr = array_values(array_filter(array_map('trim', explode(',', $keywords))));

            $profile = ObjectManager::getInstance(TrendProfileModel::class);
            $profile->setData(TrendProfileModel::fields_NAME, $name)
                ->setKeywordsFromArray($keywordsArr)
                ->setData(TrendProfileModel::fields_SORT, (int)($data['sort'] ?? 0))
                ->setData(TrendProfileModel::fields_IS_ACTIVE, isset($data['is_active']) ? 1 : 0)
                ->save();

            MessageManager::success(__('画像已创建'));
            $this->redirect('blog/backend/trend-profile/index');
        } catch (\Throwable $e) {
            MessageManager::error($e->getMessage());
            $this->redirect('blog/backend/trend-profile/create');
        }
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::trend_profile_edit', '编辑画像', 'mdi mdi-pencil', '编辑关键词画像', 'GuoLaiRen_Blog::trend_profile')]
    public function getEdit(): string
    {
        $id = (int)$this->request->getGet('id', 0);
        $profile = clone $this->profileModel;
        $profile->clear()->load($id);

        if (!$profile->getId()) {
            MessageManager::error(__('画像不存在'));
            $this->redirect('blog/backend/trend-profile/index');
            return '';
        }

        $this->assign('profile', $profile);
        $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl('blog/backend/trend-profile/edit', ['id' => $id]));
        $this->assign('page_title', __('编辑关键词画像'));
        $this->assign('breadcrumb_parent', __('关键词画像'));
        $this->assign('breadcrumb_current', __('编辑'));
        return $this->fetch('form');
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::trend_profile_edit_post', '编辑画像提交', '', '', 'GuoLaiRen_Blog::trend_profile')]
    public function postEdit()
    {
        try {
            $id = (int)$this->request->getGet('id', 0);
            $data = $this->request->getPost();

            $profile = clone $this->profileModel;
            $profile->clear()->load($id);

            if (!$profile->getId()) {
                throw new \Exception(__('画像不存在'));
            }

            $name = trim((string)($data['name'] ?? ''));
            if ($name === '') {
                throw new \Exception(__('画像名称不能为空'));
            }

            $keywords = (string)($data['keywords'] ?? '');
            $keywordsArr = array_values(array_filter(array_map('trim', explode(',', $keywords))));

            $profile->setData(TrendProfileModel::fields_NAME, $name)
                ->setKeywordsFromArray($keywordsArr)
                ->setData(TrendProfileModel::fields_SORT, (int)($data['sort'] ?? 0))
                ->setData(TrendProfileModel::fields_IS_ACTIVE, isset($data['is_active']) ? 1 : 0)
                ->save();

            MessageManager::success(__('画像已保存'));
            $this->redirect('blog/backend/trend-profile/index');
        } catch (\Throwable $e) {
            MessageManager::error($e->getMessage());
            $this->redirect('blog/backend/trend-profile/index');
        }
    }

    // ─── Offcanvas 新建/编辑（iframe 内加载，使用空白布局） ───

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::trend_profile_create', '新建画像(Offcanvas)', '', '', 'GuoLaiRen_Blog::trend_profile')]
    public function getOffcanvasCreate(): string
    {
        $this->layoutType = 'default.blank';
        $this->assign('profile', null);
        $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl('blog/backend/trend-profile/offcanvas-create'));
        return $this->fetch('offcanvas_form');
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::trend_profile_create_post', '新建画像提交(Offcanvas)', '', '', 'GuoLaiRen_Blog::trend_profile')]
    public function postOffcanvasCreate(): void
    {
        try {
            $this->saveProfile();
            MessageManager::success(__('画像已创建'));
        } catch (\Throwable $e) {
            MessageManager::error($e->getMessage());
        }
        $this->redirect('blog/backend/trend-profile/offcanvas-create');
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::trend_profile_edit', '编辑画像(Offcanvas)', '', '', 'GuoLaiRen_Blog::trend_profile')]
    public function getOffcanvasEdit(): string
    {
        $this->layoutType = 'default.blank';
        $id = (int)$this->request->getGet('id', 0);
        $profile = clone $this->profileModel;
        $profile->clear()->load($id);

        if (!$profile->getId()) {
            MessageManager::error(__('画像不存在'));
            return $this->fetch('offcanvas_form');
        }

        $this->assign('profile', $profile);
        $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl('blog/backend/trend-profile/offcanvas-edit', ['id' => $id]));
        return $this->fetch('offcanvas_form');
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::trend_profile_edit_post', '编辑画像提交(Offcanvas)', '', '', 'GuoLaiRen_Blog::trend_profile')]
    public function postOffcanvasEdit(): void
    {
        $id = (int)$this->request->getGet('id', 0);
        try {
            $profile = clone $this->profileModel;
            $profile->clear()->load($id);
            if (!$profile->getId()) {
                throw new \Exception(__('画像不存在'));
            }
            $this->saveProfile($profile);
            MessageManager::success(__('画像已保存'));
        } catch (\Throwable $e) {
            MessageManager::error($e->getMessage());
        }
        $this->redirect('blog/backend/trend-profile/offcanvas-edit', ['id' => $id]);
    }

    /**
     * 提取公共保存逻辑
     */
    private function saveProfile(?TrendProfileModel $profile = null): void
    {
        $data = $this->request->getPost();
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new \Exception(__('画像名称不能为空'));
        }

        $keywords    = (string)($data['keywords'] ?? '');
        $keywordsArr = array_values(array_filter(array_map('trim', explode(',', $keywords))));

        if ($profile === null) {
            $profile = ObjectManager::getInstance(TrendProfileModel::class);
        }

        $profile->setData(TrendProfileModel::fields_NAME, $name)
            ->setKeywordsFromArray($keywordsArr)
            ->setData(TrendProfileModel::fields_SORT, (int)($data['sort'] ?? 0))
            ->setData(TrendProfileModel::fields_IS_ACTIVE, isset($data['is_active']) ? 1 : 0)
            ->save();
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::trend_profile_delete', '删除画像', 'mdi mdi-delete', '删除关键词画像', 'GuoLaiRen_Blog::trend_profile')]
    public function delete()
    {
        try {
            $id = (int)$this->request->getGet('id', 0);
            $profile = clone $this->profileModel;
            $profile->clear()->load($id);

            if (!$profile->getId()) {
                throw new \Exception(__('画像不存在'));
            }

            $profile->delete()->fetch();
            MessageManager::success(__('画像已删除'));
        } catch (\Throwable $e) {
            MessageManager::error($e->getMessage());
        }
        $this->redirect('blog/backend/trend-profile/index');
    }
}
