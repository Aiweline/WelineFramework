<?php

declare(strict_types=1);

/*
 * GuoLaiRen Blog Module
 * 增长词日志后台管理控制器
 */

namespace GuoLaiRen\Blog\Controller\Backend;

use GuoLaiRen\Blog\Model\TrendingKeywordLog as TrendingKeywordLogModel;
use GuoLaiRen\Blog\Model\TrendProfile;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;

#[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::trending_keyword_log', '增长词日志', 'mdi mdi-format-list-bulleted', '查看趋势增长词日志', 'GuoLaiRen_Blog::blog_menu')]
class TrendingKeywordLog extends BackendController
{
    private TrendingKeywordLogModel $logModel;

    public function __construct(TrendingKeywordLogModel $logModel)
    {
        $this->logModel = $logModel;
    }

    /**
     * 获取画像选项
     */
    private function getProfileOptions(): array
    {
        /** @var TrendProfile $profile */
        $profile = ObjectManager::getInstance(TrendProfile::class);
        $items = $profile->clear()
            ->order(TrendProfile::fields_SORT, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
        $out = [];
        foreach ($items as $p) {
            $out[(int)$p->getData(TrendProfile::fields_ID)] = (string)$p->getData(TrendProfile::fields_NAME);
        }
        return $out;
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::trending_keyword_log_index', '日志列表', 'mdi mdi-view-list', '查看增长词日志列表', 'GuoLaiRen_Blog::trending_keyword_log')]
    public function index(): string
    {
        $listModel = clone $this->logModel;
        $listModel->clear();

        // 按画像筛选
        $profileId = (int)$this->request->getGet('profile_id', 0);
        if ($profileId > 0) {
            $listModel->where(TrendingKeywordLogModel::fields_PROFILE_ID, $profileId);
        }

        // 按使用状态筛选
        $usedFilter = $this->request->getGet('used', '');
        if ($usedFilter === 'yes') {
            $listModel->where(TrendingKeywordLogModel::fields_USED_AT, null, 'IS NOT');
        } elseif ($usedFilter === 'no') {
            $listModel->where(TrendingKeywordLogModel::fields_USED_AT, null, 'IS');
        }

        // 搜索关键词
        $search = trim((string)$this->request->getGet('search', ''));
        if ($search !== '') {
            $listModel->where(TrendingKeywordLogModel::fields_KEYWORD, '%' . $search . '%', 'like');
        }

        $items = $listModel
            ->order(TrendingKeywordLogModel::fields_ID, 'DESC')
            ->pagination()
            ->select()
            ->fetch();

        $profileNames = $this->getProfileOptions();

        $this->assign('logs', $items->getItems());
        $this->assign('pagination', $items->getPagination());
        $this->assign('profileNames', $profileNames);
        $this->assign('selected_profile_id', $profileId);
        $this->assign('used_filter', $usedFilter);
        $this->assign('search', $search);
        $this->assign('page_title', __('增长词日志'));
        $this->assign('breadcrumb_parent', __('博客管理'));
        $this->assign('breadcrumb_current', __('增长词日志'));

        return $this->fetch('index');
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::trending_keyword_log_mark_used', '标记已使用', 'mdi mdi-check', '标记增长词为已使用', 'GuoLaiRen_Blog::trending_keyword_log')]
    public function markUsed()
    {
        try {
            $id = (int)$this->request->getGet('id', 0);
            $log = clone $this->logModel;
            $log->clear()->load($id);

            if (!$log->getId()) {
                throw new \Exception(__('日志不存在'));
            }

            $log->setData(TrendingKeywordLogModel::fields_USED_AT, date('Y-m-d H:i:s'))->save();
            MessageManager::success(__('已标记为已使用'));
        } catch (\Throwable $e) {
            MessageManager::error($e->getMessage());
        }
        $this->redirectBack();
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::trending_keyword_log_mark_unused', '标记未使用', 'mdi mdi-close', '标记增长词为未使用', 'GuoLaiRen_Blog::trending_keyword_log')]
    public function markUnused()
    {
        try {
            $id = (int)$this->request->getGet('id', 0);
            $log = clone $this->logModel;
            $log->clear()->load($id);

            if (!$log->getId()) {
                throw new \Exception(__('日志不存在'));
            }

            $log->setData(TrendingKeywordLogModel::fields_USED_AT, null)->save();
            MessageManager::success(__('已标记为未使用'));
        } catch (\Throwable $e) {
            MessageManager::error($e->getMessage());
        }
        $this->redirectBack();
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::trending_keyword_log_delete', '删除日志', 'mdi mdi-delete', '删除增长词日志', 'GuoLaiRen_Blog::trending_keyword_log')]
    public function delete()
    {
        try {
            $id = (int)$this->request->getGet('id', 0);
            $log = clone $this->logModel;
            $log->clear()->load($id);

            if (!$log->getId()) {
                throw new \Exception(__('日志不存在'));
            }

            $log->delete()->fetch();
            MessageManager::success(__('日志已删除'));
        } catch (\Throwable $e) {
            MessageManager::error($e->getMessage());
        }
        $this->redirectBack();
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::trending_keyword_log_clear_old', '清理旧日志', 'mdi mdi-delete-sweep', '清理指定天数前的旧日志', 'GuoLaiRen_Blog::trending_keyword_log')]
    public function clearOld()
    {
        try {
            $days = (int)$this->request->getGet('days', 30);
            if ($days < 7) {
                $days = 7; // 最少保留7天
            }
            $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

            $delModel = clone $this->logModel;
            $delModel->clear()
                ->where(TrendingKeywordLogModel::fields_CREATED_AT, $cutoff, '<')
                ->where(TrendingKeywordLogModel::fields_USED_AT, null, 'IS NOT') // 只删除已使用的
                ->delete()
                ->fetch();

            MessageManager::success(__('已清理 %{days} 天前的旧日志', ['days' => $days]));
        } catch (\Throwable $e) {
            MessageManager::error($e->getMessage());
        }
        $this->redirect('blog/backend/trending-keyword-log/index');
    }

    /**
     * 返回上一页
     */
    private function redirectBack(): void
    {
        $referer = $this->request->getServer('HTTP_REFERER', '');
        if ($referer) {
            $this->request->getResponse()->redirect($referer);
        } else {
            $this->redirect('blog/backend/trending-keyword-log/index');
        }
    }
}
