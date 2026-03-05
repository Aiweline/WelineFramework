<?php

declare(strict_types=1);

/*
 * GuoLaiRen Blog Module
 * 站点发文配额后台管理
 */

namespace GuoLaiRen\Blog\Controller\Backend;

use GuoLaiRen\Blog\Model\Category;
use GuoLaiRen\Blog\Model\TrendProfile;
use GuoLaiRen\Blog\Model\TrendSiteQuota as TrendSiteQuotaModel;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Website;

#[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::trend_site_quota', '站点发文配额', 'mdi mdi-counter', '站点+画像每日发文配额', 'GuoLaiRen_Blog::blog_menu')]
class TrendSiteQuota extends BackendController
{
    private TrendSiteQuotaModel $quotaModel;

    public function __construct(TrendSiteQuotaModel $quotaModel)
    {
        $this->quotaModel = $quotaModel;
    }

    /**
     * 站点列表
     */
    private function getSiteOptions(): array
    {
        /** @var Website $website */
        $website = ObjectManager::getInstance(Website::class);
        $items = $website->clear()
            ->order(Website::schema_fields_NAME, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
        $out = [];
        foreach ($items as $w) {
            $out[] = [
                'site_id' => $w->getWebsiteId(),
                'site_name' => $w->getName(),
            ];
        }
        return $out;
    }

    /**
     * 画像列表（启用）
     */
    private function getProfileOptions(): array
    {
        /** @var TrendProfile $profile */
        $profile = ObjectManager::getInstance(TrendProfile::class);
        $items = $profile->clear()
            ->where(TrendProfile::schema_fields_IS_ACTIVE, 1)
            ->order(TrendProfile::schema_fields_SORT, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
        $out = [];
        foreach ($items as $p) {
            $out[] = [
                'profile_id' => $p->getData(TrendProfile::schema_fields_ID),
                'name' => $p->getData(TrendProfile::schema_fields_NAME),
            ];
        }
        return $out;
    }

    /**
     * 按站点获取该站点下的博客分类（扁平）
     */
    private function getCategoriesBySite(): array
    {
        $sites = $this->getSiteOptions();
        $result = [];
        foreach ($sites as $s) {
            $siteId = (int)$s['site_id'];
            $result[$siteId] = Category::getFlatCategoryList(0, $siteId);
        }
        return $result;
    }

    /**
     * 根据配额列表批量查询分类名称，避免 N+1
     *
     * @param object[] $quotaItems
     * @return array<int, string>
     */
    private function getCategoryNamesForQuotas(array $quotaItems): array
    {
        $cids = [];
        foreach ($quotaItems as $q) {
            $cid = (int)$q->getData(TrendSiteQuotaModel::schema_fields_DEFAULT_CATEGORY_ID);
            if ($cid > 0) {
                $cids[$cid] = true;
            }
        }
        $cids = array_keys($cids);
        if (empty($cids)) {
            return [];
        }
        /** @var Category $categoryModel */
        $categoryModel = ObjectManager::getInstance(Category::class);
        $categories = $categoryModel->clear()
            ->where(Category::schema_fields_ID, $cids, 'in')
            ->select()
            ->fetch()
            ->getItems();
        $out = [];
        foreach ($categories as $c) {
            $out[(int)$c->getData(Category::schema_fields_ID)] = (string)$c->getData(Category::schema_fields_NAME);
        }
        return $out;
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::trend_site_quota_index', '配额列表', 'mdi mdi-view-list', '查看站点发文配额', 'GuoLaiRen_Blog::trend_site_quota')]
    public function index(): string
    {
        $listModel = clone $this->quotaModel;
        $listModel->clear();

        $items = $listModel
            ->order(TrendSiteQuotaModel::schema_fields_QUOTA_ID, 'DESC')
            ->pagination()
            ->select()
            ->fetch();

        $siteOptions = $this->getSiteOptions();
        $sites = [];
        foreach ($siteOptions as $s) {
            $sites[(int)$s['site_id']] = $s['site_name'];
        }
        /** @var TrendProfile $profileModel */
        $profileModel = ObjectManager::getInstance(TrendProfile::class);
        $profiles = [];
        foreach ($profileModel->clear()->select()->fetch()->getItems() as $p) {
            $profiles[(int)$p->getData(TrendProfile::schema_fields_ID)] = (string)$p->getData(TrendProfile::schema_fields_NAME);
        }

        $categoryNames = $this->getCategoryNamesForQuotas($items->getItems());

        $this->assign('quotas', $items->getItems());
        $this->assign('pagination', $items->getPagination());
        $this->assign('profileNames', $profiles);
        $this->assign('siteNames', $sites);
        $this->assign('categoryNames', $categoryNames);
        $this->assign('page_title', __('站点发文配额'));
        $this->assign('breadcrumb_parent', __('博客管理'));
        $this->assign('breadcrumb_current', __('站点发文配额'));

        return $this->fetch('index');
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::trend_site_quota_create', '新建配额', 'mdi mdi-plus', '新建站点发文配额', 'GuoLaiRen_Blog::trend_site_quota')]
    public function getCreate(): string
    {
        $this->assign('quota', null);
        $this->assign('action', $this->request->getUrlBuilder()->getUrl('blog/backend/trend-site-quota/create'));
        $this->assign('sites', $this->getSiteOptions());
        $this->assign('profiles', $this->getProfileOptions());
        $this->assign('categoriesBySite', $this->getCategoriesBySite());
        $this->assign('page_title', __('新建站点发文配额'));
        $this->assign('breadcrumb_parent', __('站点发文配额'));
        $this->assign('breadcrumb_current', __('新建'));
        return $this->fetch('form');
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::trend_site_quota_create_post', '新建配额提交', '', '', 'GuoLaiRen_Blog::trend_site_quota')]
    public function postCreate()
    {
        try {
            $data = $this->request->getPost();
            $siteId = (int)($data['site_id'] ?? 0);
            $profileId = (int)($data['profile_id'] ?? 0);
            if (!$siteId || !$profileId) {
                throw new \Exception(__('请选择站点和画像'));
            }

            $exists = clone $this->quotaModel;
            $exists->clear()
                ->where(TrendSiteQuotaModel::schema_fields_SITE_ID, $siteId)
                ->where(TrendSiteQuotaModel::schema_fields_PROFILE_ID, $profileId)
                ->find()
                ->fetch();
            if ($exists->getId()) {
                throw new \Exception(__('该站点+画像的配额已存在'));
            }

            $defaultCategoryId = (int)($data['default_category_id'] ?? 0);
            $this->validateCategoryForSite($defaultCategoryId, $siteId);

            $quota = ObjectManager::getInstance(TrendSiteQuotaModel::class);
            $quota->setData(TrendSiteQuotaModel::schema_fields_SITE_ID, $siteId)
                ->setData(TrendSiteQuotaModel::schema_fields_PROFILE_ID, $profileId)
                ->setData(TrendSiteQuotaModel::schema_fields_ARTICLES_PER_DAY, (int)($data['articles_per_day'] ?? 0))
                ->setData(TrendSiteQuotaModel::schema_fields_DEFAULT_CATEGORY_ID, $defaultCategoryId)
                ->save();

            MessageManager::success(__('配额已创建'));
            $this->redirect('blog/backend/trend-site-quota/index');
        } catch (\Throwable $e) {
            MessageManager::error($e->getMessage());
            $this->redirect('blog/backend/trend-site-quota/create');
        }
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::trend_site_quota_edit', '编辑配额', 'mdi mdi-pencil', '编辑站点发文配额', 'GuoLaiRen_Blog::trend_site_quota')]
    public function getEdit(): string
    {
        $id = (int)$this->request->getGet('id', 0);
        $quota = clone $this->quotaModel;
        $quota->clear()->load($id);

        if (!$quota->getId()) {
            MessageManager::error(__('配额不存在'));
            $this->redirect('blog/backend/trend-site-quota/index');
            return '';
        }

        $this->assign('quota', $quota);
        $this->assign('action', $this->request->getUrlBuilder()->getUrl('blog/backend/trend-site-quota/edit', ['id' => $id]));
        $this->assign('sites', $this->getSiteOptions());
        $this->assign('profiles', $this->getProfileOptions());
        $this->assign('categoriesBySite', $this->getCategoriesBySite());
        $this->assign('page_title', __('编辑站点发文配额'));
        $this->assign('breadcrumb_parent', __('站点发文配额'));
        $this->assign('breadcrumb_current', __('编辑'));
        return $this->fetch('form');
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::trend_site_quota_edit_post', '编辑配额提交', '', '', 'GuoLaiRen_Blog::trend_site_quota')]
    public function postEdit()
    {
        try {
            $id = (int)$this->request->getGet('id', 0);
            $data = $this->request->getPost();

            $quota = clone $this->quotaModel;
            $quota->clear()->load($id);

            if (!$quota->getId()) {
                throw new \Exception(__('配额不存在'));
            }

            $siteId = (int)($data['site_id'] ?? 0);
            $profileId = (int)($data['profile_id'] ?? 0);
            if (!$siteId || !$profileId) {
                throw new \Exception(__('请选择站点和画像'));
            }

            $exists = clone $this->quotaModel;
            $exists->clear()
                ->where(TrendSiteQuotaModel::schema_fields_SITE_ID, $siteId)
                ->where(TrendSiteQuotaModel::schema_fields_PROFILE_ID, $profileId)
                ->where(TrendSiteQuotaModel::schema_fields_QUOTA_ID, $id, '!=')
                ->find()
                ->fetch();
            if ($exists->getId()) {
                throw new \Exception(__('该站点+画像的配额已存在'));
            }

            $defaultCategoryId = (int)($data['default_category_id'] ?? 0);
            $this->validateCategoryForSite($defaultCategoryId, $siteId);

            $quota->setData(TrendSiteQuotaModel::schema_fields_SITE_ID, $siteId)
                ->setData(TrendSiteQuotaModel::schema_fields_PROFILE_ID, $profileId)
                ->setData(TrendSiteQuotaModel::schema_fields_ARTICLES_PER_DAY, (int)($data['articles_per_day'] ?? 0))
                ->setData(TrendSiteQuotaModel::schema_fields_DEFAULT_CATEGORY_ID, $defaultCategoryId)
                ->save();

            MessageManager::success(__('配额已保存'));
            $this->redirect('blog/backend/trend-site-quota/index');
        } catch (\Throwable $e) {
            MessageManager::error($e->getMessage());
            $this->redirect('blog/backend/trend-site-quota/index');
        }
    }

    private function validateCategoryForSite(int $categoryId, int $siteId): void
    {
        if ($categoryId <= 0) {
            return;
        }
        $cat = ObjectManager::getInstance(Category::class);
        $cat->clear()->load($categoryId);
        if (!$cat->getId()) {
            throw new \Exception(__('所选分类不存在'));
        }
        if ((int)$cat->getData(Category::schema_fields_SITE_ID) !== $siteId) {
            throw new \Exception(__('所选分类必须属于该站点'));
        }
    }

    // ─── Offcanvas 新建/编辑（iframe 内加载，使用空白布局） ───

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::trend_site_quota_create', '新建配额(Offcanvas)', '', '', 'GuoLaiRen_Blog::trend_site_quota')]
    public function getOffcanvasCreate(): string
    {
        $this->layoutType = 'default.blank';
        $this->assign('quota', null);
        $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl('blog/backend/trend-site-quota/offcanvas-create'));
        $this->assign('sites', $this->getSiteOptions());
        $this->assign('profiles', $this->getProfileOptions());
        $this->assign('categoriesBySite', $this->getCategoriesBySite());
        return $this->fetch('offcanvas_form');
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::trend_site_quota_create_post', '新建配额提交(Offcanvas)', '', '', 'GuoLaiRen_Blog::trend_site_quota')]
    public function postOffcanvasCreate(): void
    {
        try {
            $this->saveQuota();
            MessageManager::success(__('配额已创建'));
        } catch (\Throwable $e) {
            MessageManager::error($e->getMessage());
        }
        $this->redirect('blog/backend/trend-site-quota/offcanvas-create');
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::trend_site_quota_edit', '编辑配额(Offcanvas)', '', '', 'GuoLaiRen_Blog::trend_site_quota')]
    public function getOffcanvasEdit(): string
    {
        $this->layoutType = 'default.blank';
        $id    = (int)$this->request->getGet('id', 0);
        $quota = clone $this->quotaModel;
        $quota->clear()->load($id);

        if (!$quota->getId()) {
            MessageManager::error(__('配额不存在'));
            return $this->fetch('offcanvas_form');
        }

        $this->assign('quota', $quota);
        $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl('blog/backend/trend-site-quota/offcanvas-edit', ['id' => $id]));
        $this->assign('sites', $this->getSiteOptions());
        $this->assign('profiles', $this->getProfileOptions());
        $this->assign('categoriesBySite', $this->getCategoriesBySite());
        return $this->fetch('offcanvas_form');
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::trend_site_quota_edit_post', '编辑配额提交(Offcanvas)', '', '', 'GuoLaiRen_Blog::trend_site_quota')]
    public function postOffcanvasEdit(): void
    {
        $id = (int)$this->request->getGet('id', 0);
        try {
            $quota = clone $this->quotaModel;
            $quota->clear()->load($id);
            if (!$quota->getId()) {
                throw new \Exception(__('配额不存在'));
            }
            $this->saveQuota($quota);
            MessageManager::success(__('配额已保存'));
        } catch (\Throwable $e) {
            MessageManager::error($e->getMessage());
        }
        $this->redirect('blog/backend/trend-site-quota/offcanvas-edit', ['id' => $id]);
    }

    /**
     * 提取公共保存逻辑
     */
    private function saveQuota(?TrendSiteQuotaModel $quota = null): void
    {
        $data      = $this->request->getPost();
        $siteId    = (int)($data['site_id'] ?? 0);
        $profileId = (int)($data['profile_id'] ?? 0);
        if (!$siteId || !$profileId) {
            throw new \Exception(__('请选择站点和画像'));
        }

        // 唯一性校验
        $exists = clone $this->quotaModel;
        $exists->clear()
            ->where(TrendSiteQuotaModel::schema_fields_SITE_ID, $siteId)
            ->where(TrendSiteQuotaModel::schema_fields_PROFILE_ID, $profileId);
        if ($quota !== null && $quota->getId()) {
            $exists->where(TrendSiteQuotaModel::schema_fields_QUOTA_ID, $quota->getId(), '!=');
        }
        $exists->find()->fetch();
        if ($exists->getId()) {
            throw new \Exception(__('该站点+画像的配额已存在'));
        }

        $defaultCategoryId = (int)($data['default_category_id'] ?? 0);
        $this->validateCategoryForSite($defaultCategoryId, $siteId);

        if ($quota === null) {
            $quota = ObjectManager::getInstance(TrendSiteQuotaModel::class);
        }

        $quota->setData(TrendSiteQuotaModel::schema_fields_SITE_ID, $siteId)
            ->setData(TrendSiteQuotaModel::schema_fields_PROFILE_ID, $profileId)
            ->setData(TrendSiteQuotaModel::schema_fields_ARTICLES_PER_DAY, (int)($data['articles_per_day'] ?? 0))
            ->setData(TrendSiteQuotaModel::schema_fields_DEFAULT_CATEGORY_ID, $defaultCategoryId)
            ->save();
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::trend_site_quota_delete', '删除配额', 'mdi mdi-delete', '删除站点发文配额', 'GuoLaiRen_Blog::trend_site_quota')]
    public function delete()
    {
        try {
            $id = (int)$this->request->getGet('id', 0);
            $quota = clone $this->quotaModel;
            $quota->clear()->load($id);

            if (!$quota->getId()) {
                throw new \Exception(__('配额不存在'));
            }

            $quota->delete()->fetch();
            MessageManager::success(__('配额已删除'));
        } catch (\Throwable $e) {
            MessageManager::error($e->getMessage());
        }
        $this->redirect('blog/backend/trend-site-quota/index');
    }
}
