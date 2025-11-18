<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Controller\Backend;

use Weline\Cdn\Model\WarmupUrl;
use Weline\Cdn\Service\WarmupRunner;
use Weline\Cdn\Service\WarmupProviderScanner;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl as AclAttribute;
use Weline\Framework\Manager\Message;
use Weline\Framework\Manager\ObjectManager;

/**
 * CDN预热管理后台控制器
 * 
 * @package Weline_Cdn
 */
#[AclAttribute('Weline_Cdn::cdn_warmup_manager', 'CDN预热管理', 'mdi-fire', 'CDN预热管理', '')]
class Warmup extends BackendController
{
    /**
     * 获取预热URL模型
     */
    private function getWarmupUrlModel(): WarmupUrl
    {
        return ObjectManager::getInstance(WarmupUrl::class);
    }

    /**
     * 获取预热执行器
     */
    private function getWarmupRunner(): WarmupRunner
    {
        return ObjectManager::getInstance(WarmupRunner::class);
    }

    /**
     * 获取预热Provider扫描器
     */
    private function getProviderScanner(): WarmupProviderScanner
    {
        return ObjectManager::getInstance(WarmupProviderScanner::class);
    }

    /**
     * 预热URL列表页面
     * 
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_warmup_list', '查看预热URL列表', 'mdi-view-list', '查看预热URL列表')]
    public function index(): string
    {
        try {
            $page = (int)$this->request->getGet('page', 1);
            $pageSize = 20;
            $search = trim($this->request->getGet('search', ''));
            $module = trim($this->request->getGet('module', ''));
            $status = trim($this->request->getGet('status', ''));

            $query = $this->getWarmupUrlModel()->reset()->select();

            // 搜索过滤
            if (!empty($search)) {
                $query->where(WarmupUrl::fields_URL, 'like', "%{$search}%");
            }

            // 模块过滤
            if (!empty($module)) {
                $query->where(WarmupUrl::fields_MODULE, $module);
            }

            // 状态过滤
            if (!empty($status)) {
                $query->where(WarmupUrl::fields_STATUS, $status);
            }

            // 统计
            $total = $query->count();

            // 分页查询
            $urls = $query
                ->limit($pageSize, ($page - 1) * $pageSize)
                ->order(WarmupUrl::fields_CREATED_AT, 'DESC')
                ->fetch()
                ->getItems();

            // 计算分页信息
            $totalPages = ceil($total / $pageSize);

            // 获取所有模块（用于筛选）
            $modules = $this->getWarmupUrlModel()->reset()
                ->select(WarmupUrl::fields_MODULE)
                ->group(WarmupUrl::fields_MODULE)
                ->fetch()
                ->getItems();

            $moduleList = [];
            foreach ($modules as $item) {
                $moduleList[] = $item->getData(WarmupUrl::fields_MODULE);
            }

            $this->assign('urls', $urls);
            $this->assign('total', $total);
            $this->assign('page', $page);
            $this->assign('pageSize', $pageSize);
            $this->assign('totalPages', $totalPages);
            $this->assign('search', $search);
            $this->assign('module', $module);
            $this->assign('status', $status);
            $this->assign('moduleList', $moduleList);

            return $this->fetch();
        } catch (\Exception $e) {
            Message::error(__('加载预热URL列表失败：%{1}', $e->getMessage()));
            $this->assign('urls', []);
            $this->assign('total', 0);
            $this->assign('page', 1);
            $this->assign('pageSize', 20);
            $this->assign('totalPages', 0);
            $this->assign('search', '');
            $this->assign('module', '');
            $this->assign('status', '');
            $this->assign('moduleList', []);
            return $this->fetch();
        }
    }

    /**
     * 统计信息页面
     * 
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_warmup_statistics', '查看统计信息', 'mdi-chart-bar', '查看预热统计信息')]
    public function statistics(): string
    {
        try {
            // 按模块统计
            $stats = $this->getWarmupUrlModel()->reset()
                ->select(WarmupUrl::fields_MODULE . ', COUNT(*) as total_count, SUM(' . WarmupUrl::fields_PROCESSED_COUNT . ') as total_processed, SUM(' . WarmupUrl::fields_SUCCESS_COUNT . ') as total_success, SUM(' . WarmupUrl::fields_FAIL_COUNT . ') as total_fail')
                ->group(WarmupUrl::fields_MODULE)
                ->fetch()
                ->getItems();

            $statistics = [];
            foreach ($stats as $stat) {
                $module = $stat->getData(WarmupUrl::fields_MODULE);
                $total = (int)$stat->getData('total_count');
                $processed = (int)$stat->getData('total_processed');
                $success = (int)$stat->getData('total_success');
                $fail = (int)$stat->getData('total_fail');
                
                $statistics[] = [
                    'module' => $module,
                    'total_count' => $total,
                    'total_processed' => $processed,
                    'total_success' => $success,
                    'total_fail' => $fail,
                    'success_rate' => $processed > 0 ? round($success / $processed * 100, 2) : 0
                ];
            }

            $this->assign('statistics', $statistics);
            return $this->fetch();
        } catch (\Exception $e) {
            Message::error(__('加载统计信息失败：%{1}', $e->getMessage()));
            $this->assign('statistics', []);
            return $this->fetch();
        }
    }

    /**
     * 手动触发预热任务
     * 
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_warmup_execute', '执行预热任务', 'mdi-play', '手动触发预热任务')]
    public function execute(): string
    {
        $limit = (int)$this->request->getPost('limit', 50);

        try {
            $result = $this->getWarmupRunner()->run($limit);

            return $this->jsonResponse([
                'success' => true,
                'message' => __('预热任务执行完成'),
                'data' => $result
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('执行失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 启用/禁用URL预热
     * 
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_warmup_toggle_enable', '启用/禁用预热', 'mdi-toggle-switch', '启用/禁用URL预热')]
    public function toggleEnable(): string
    {
        $id = (int)$this->request->getPost('id');
        $enabled = (int)$this->request->getPost('enabled', 1);

        if (!$id) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('URL ID不能为空')
            ]);
        }

        try {
            $warmupUrl = $this->getWarmupUrlModel()->reset()->load($id);
            
            if (!$warmupUrl->getData(WarmupUrl::fields_WARMUP_URL_ID)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('URL不存在')
                ]);
            }

            $warmupUrl->setData(WarmupUrl::fields_ENABLED, $enabled ? 1 : 0);
            $warmupUrl->save();

            Message::success($enabled ? __('URL已启用') : __('URL已禁用'));

            return $this->jsonResponse([
                'success' => true,
                'message' => $enabled ? __('URL已启用') : __('URL已禁用')
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('操作失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 删除预热URL
     * 
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_warmup_delete', '删除预热URL', 'mdi-delete', '删除预热URL')]
    public function delete(): string
    {
        $id = (int)$this->request->getPost('id');

        if (!$id) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('URL ID不能为空')
            ]);
        }

        try {
            $warmupUrl = $this->getWarmupUrlModel()->reset()->load($id);
            
            if (!$warmupUrl->getData(WarmupUrl::fields_WARMUP_URL_ID)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('URL不存在')
                ]);
            }

            $warmupUrl->delete();

            Message::success(__('URL删除成功'));

            return $this->jsonResponse([
                'success' => true,
                'message' => __('URL删除成功')
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('删除失败：%{1}', $e->getMessage())
            ]);
        }
    }
}

