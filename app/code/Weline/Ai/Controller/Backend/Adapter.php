<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：<?= date('Y/m/d H:i:s') ?>

 */

namespace Weline\Ai\Controller\Backend;

use Weline\Ai\Model\AiModel;
use Weline\Ai\Model\AiScenarioAdapter;
use Weline\Ai\Service\AdapterScanner;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Manager\Message;
use Weline\Framework\Acl\Acl;

/**
 * 场景适配器管理后台控制器
 * 
 * 功能：
 * - 场景适配器列表展示
 * - 适配器详情查看
 * - 适配器状态管理
 * - 适配器扫描和更新
 */
#[Acl('Weline_Ai::ai_adapter_manager', '场景适配器管理', 'mdi-puzzle', '场景适配器管理', 'Weline_Ai::ai')]
class Adapter extends BackendController
{
    /**
     * 获取场景适配器模型（懒加载）
     */
    private function getScenarioAdapter(): AiScenarioAdapter
    {
        return \Weline\Framework\Manager\ObjectManager::getInstance(AiScenarioAdapter::class);
    }

    /**
     * 获取适配器扫描器（懒加载）
     */
    private function getAdapterScanner(): AdapterScanner
    {
        return \Weline\Framework\Manager\ObjectManager::getInstance(AdapterScanner::class);
    }

    /**
     * 补充适配器列表中空版本和空状态，从实例读取并回写数据库
     *
     * @param array $items 适配器模型列表
     */
    private function enrichAdapterList(array $items): void
    {
        $scanner = $this->getAdapterScanner();
        foreach ($items as $adapter) {
            if (!is_object($adapter) || !method_exists($adapter, 'getData')) {
                continue;
            }
            $code = $adapter->getData(AiScenarioAdapter::schema_fields_CODE);
            $version = $adapter->getData(AiScenarioAdapter::schema_fields_VERSION);
            $isActive = $adapter->getData(AiScenarioAdapter::schema_fields_IS_ACTIVE);
            $needsSave = false;

            // 版本为空时从实例获取
            if ($code && ($version === '' || $version === null)) {
                $instance = $scanner->getAdapter($code);
                if ($instance && method_exists($instance, 'getVersion')) {
                    $ver = $instance->getVersion();
                    $adapter->setData(AiScenarioAdapter::schema_fields_VERSION, $ver);
                    $needsSave = true;
                }
            }

            // is_active 为空时默认激活
            if ($isActive === '' || $isActive === null) {
                $adapter->setData(AiScenarioAdapter::schema_fields_IS_ACTIVE, 1);
                $needsSave = true;
            }

            if ($needsSave) {
                try {
                    $adapter->save();
                } catch (\Throwable $e) {
                    // 忽略保存失败，避免阻断列表展示
                }
            }
        }
    }

    /**
     * 适配器列表页面
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_adapter_list', '查看场景适配器列表', 'mdi-view-list', '查看场景适配器列表')]
    public function index(): string
    {
        if ($this->request->getGet('embed') === '1') {
            $this->layoutType = 'default.blank';
        }
        $page = (int)$this->request->getGet('page', 1);
        $pageSize = 20;

        // 获取适配器列表
        $adapters = $this->getScenarioAdapter()->reset()
            ->pagination($page, $pageSize)
            ->order(AiScenarioAdapter::schema_fields_CREATED_TIME, 'DESC')
            ->select()
            ->fetch();

        $items = $adapters->getItems();
        // 补充空版本和空状态：从适配器实例读取并回写数据库
        $this->enrichAdapterList($items);

        $this->assign('adapters', $items);
        $this->assign('pagination', $adapters->getPagination());
        $this->assign('embed', ($this->request->getGet('embed') === '1' || $this->request->getGet('embed') === true));
        $this->assign('activeTab', 'adapter');

        return $this->fetch();
    }

    /**
     * 适配器详情页面（Offcanvas侧边栏）
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_adapter_detail', '查看场景适配器详情', 'mdi-information', '查看场景适配器详情')]
    public function detailOffcanvas(): string
    {
        $this->layoutType = 'default.blank';
        $id = (int)$this->request->getGet('id');
        
        if (!$id) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '适配器ID不能为空'
            ]);
        }

        $adapter = $this->getScenarioAdapter()->reset()->load($id);
        
        if (!$adapter->getId()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '适配器不存在'
            ]);
        }

        // 获取适配器实例信息
        $adapterInstance = $this->getAdapterScanner()->getAdapter($adapter->getData(AiScenarioAdapter::schema_fields_CODE));
        
        $this->assign('adapter', $adapter);
        $this->assign('adapterInstance', $adapterInstance);

        return $this->fetch('offcanvas_detail');
    }

    /**
     * 适配器详情页面（完整页面，已废弃，使用 Offcanvas 代替）
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_adapter_detail_page', '查看场景适配器详情页面', 'mdi-file-document', '查看场景适配器完整详情页面')]
    public function detail(): string
    {
        $this->layoutType = 'default.blank';
        $id = (int)$this->request->getGet('id');
        
        if (!$id) {
            Message::error(__('适配器ID不能为空'));
            return $this->redirect('*/backend/adapter/index');
        }

        $adapter = $this->getScenarioAdapter()->reset()->load($id);
        
        if (!$adapter->getId()) {
            Message::error(__('适配器不存在'));
            return $this->redirect('*/backend/adapter/index');
        }

        // 获取适配器实例信息
        $adapterInstance = $this->getAdapterScanner()->getAdapter($adapter->getData(AiScenarioAdapter::schema_fields_CODE));
        
        $this->assign('adapter', $adapter);
        $this->assign('adapterInstance', $adapterInstance);
        $this->assign('supportedModels', $adapter->getSupportedModels());
        $this->assign('paramTemplate', $adapter->getParamTemplate());
        $this->assign('examples', $adapter->getExamples());

        return $this->fetch();
    }

    /**
     * 扫描适配器（同时支持GET和POST请求）
     * GET请求返回重定向，POST/AJAX请求返回JSON
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_adapter_scan', '扫描场景适配器', 'mdi-radar', '扫描场景适配器')]
    public function scan(): string
    {
        try {
            $scannedAdapters = $this->getAdapterScanner()->scanAllAdapters();
            
            // 检查是否是AJAX请求
            if ($this->request->isAjax() || $this->request->isPost()) {
                return $this->jsonResponse([
                    'success' => true,
                    'message' => sprintf('成功扫描 %d 个适配器', count($scannedAdapters)),
                    'count' => count($scannedAdapters)
                ]);
            }
            
            Message::success(
                __('成功扫描 %{count} 个适配器', ['count' => count($scannedAdapters)])
            );
        } catch (\Exception $e) {
            // AJAX请求返回JSON错误
            if ($this->request->isAjax() || $this->request->isPost()) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => '适配器扫描失败: ' . $e->getMessage()
                ]);
            }
            
            Message::error(__('适配器扫描失败: %{error}', ['error' => $e->getMessage()]));
        }

        return $this->redirect('*/backend/adapter/index');
    }

    /**
     * 切换适配器状态
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_adapter_toggle', '切换场景适配器状态', 'mdi-toggle-switch', '启用或禁用场景适配器')]
    public function toggleStatus(): string
    {
        $id = (int)$this->request->getBodyParam('id', $this->request->getPost('id'));
        
        if (!$id) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '适配器ID不能为空'
            ]);
        }

        $adapter = $this->getScenarioAdapter()->reset()->load($id);
        
        if (!$adapter->getId()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '适配器不存在'
            ]);
        }

        try {
            $newStatus = $adapter->isActive() ? 0 : 1;
            $adapter->setData(AiScenarioAdapter::schema_fields_IS_ACTIVE, $newStatus);
            $adapter->save();

            return $this->jsonResponse([
                'success' => true,
                'message' => '状态更新成功',
                'status' => $newStatus
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '状态更新失败: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 获取适配器信息
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_adapter_info', '获取场景适配器信息', 'mdi-information-outline', '获取场景适配器信息')]
    public function getAdapterInfo(): string
    {
        $code = $this->request->getGet('code');
        
        if (!$code) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '适配器代码不能为空'
            ]);
        }

        $adapterInstance = $this->getAdapterScanner()->getAdapter($code);
        
        if (!$adapterInstance) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '适配器不存在或未激活'
            ]);
        }

        return $this->jsonResponse([
            'success' => true,
            'data' => [
                'code' => $adapterInstance->getCode(),
                'name' => $adapterInstance->getName(),
                'description' => $adapterInstance->getDescription(),
                'version' => $adapterInstance->getVersion(),
                'supported_models' => $adapterInstance->getSupportedModelTypes(),
                'param_template' => $adapterInstance->getParamTemplate(),
                'examples' => $adapterInstance->getExamples()
            ]
        ]);
    }

    /**
     * 清理无效适配器
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_adapter_cleanup', '清理无效场景适配器', 'mdi-delete-sweep', '清理无效场景适配器')]
    public function cleanup(): string
    {
        try {
            $cleanedCount = $this->getAdapterScanner()->cleanupInvalidAdapters();
            
            Message::success(
                sprintf('成功清理 %d 个无效适配器', $cleanedCount)
            );
        } catch (\Exception $e) {
            Message::error('清理失败: ' . $e->getMessage());
        }

        return $this->redirect('*/backend/adapter/index');
    }

    /**
     * 获取适配器统计信息
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_adapter_stats', '查看场景适配器统计', 'mdi-chart-bar', '查看场景适配器统计信息')]
    public function getStats(): string
    {
        try {
            $stats = $this->getAdapterScanner()->getAdapterStats();
            
            return $this->jsonResponse([
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '获取统计信息失败: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 删除适配器
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_adapter_delete', '删除场景适配器', 'mdi-delete', '删除场景适配器')]
    public function postDelete(): string
    {
        $id = (int)$this->request->getBodyParam('id', $this->request->getPost('id'));
        
        if (!$id) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('适配器ID不能为空')
            ]);
        }

        $adapter = $this->getScenarioAdapter()->reset()->load($id);
        
        if (!$adapter->getId()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('适配器不存在')
            ]);
        }

        try {
            $adapter->delete();

            return $this->jsonResponse([
                'success' => true,
                'message' => __('删除成功')
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('删除失败：%{error}', ['error' => $e->getMessage()])
            ]);
        }
    }

    /**
     * 批量删除适配器
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_adapter_batch_delete', '批量删除场景适配器', 'mdi-delete-sweep', '批量删除场景适配器')]
    public function postBatchDelete(): string
    {
        $ids = $this->request->getBodyParam('ids', $this->request->getPost('ids', []));
        
        if (empty($ids)) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('请选择要删除的适配器')
            ]);
        }

        if (!is_array($ids)) {
            $ids = explode(',', (string)$ids);
        }

        try {
            $deleted = 0;
            foreach ($ids as $id) {
                $adapter = $this->getScenarioAdapter()->reset()->load((int)$id);
                if ($adapter->getId()) {
                    $adapter->delete();
                    $deleted++;
                }
            }

            return $this->jsonResponse([
                'success' => true,
                'message' => __('成功删除 %{count} 个适配器', ['count' => $deleted])
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('批量删除失败：%{error}', ['error' => $e->getMessage()])
            ]);
        }
    }

    /**
     * 批量切换状态
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_adapter_batch_toggle', '批量切换场景适配器状态', 'mdi-toggle-switch-outline', '批量启用或禁用场景适配器')]
    public function postBatchToggle(): string
    {
        $ids = $this->request->getBodyParam('ids', $this->request->getPost('ids', []));
        $status = (int)$this->request->getBodyParam('status', $this->request->getPost('status', 1));
        
        if (empty($ids)) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('请选择要操作的适配器')
            ]);
        }

        if (!is_array($ids)) {
            $ids = explode(',', (string)$ids);
        }

        try {
            $updated = 0;
            foreach ($ids as $id) {
                $adapter = $this->getScenarioAdapter()->reset()->load((int)$id);
                if ($adapter->getId()) {
                    $adapter->setData(AiScenarioAdapter::schema_fields_IS_ACTIVE, $status);
                    $adapter->save();
                    $updated++;
                }
            }

            $statusText = $status ? __('激活') : __('禁用');
            return $this->jsonResponse([
                'success' => true,
                'message' => __('成功%{status} %{count} 个适配器', ['status' => $statusText, 'count' => $updated])
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('批量操作失败：%{error}', ['error' => $e->getMessage()])
            ]);
        }
    }

    /**
     * JSON响应
     * 
     * @param array $data
     * @return string
     */
    private function jsonResponse(array $data): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}
