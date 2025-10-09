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
     * @var AiScenarioAdapter
     */
    private AiScenarioAdapter $scenarioAdapter;

    /**
     * @var AdapterScanner
     */
    private AdapterScanner $adapterScanner;

    /**
     * 构造函数
     * 
     * @param AiScenarioAdapter $scenarioAdapter
     * @param AdapterScanner $adapterScanner
     */
    public function __construct(
        AiScenarioAdapter $scenarioAdapter,
        AdapterScanner $adapterScanner
    ) {
        $this->scenarioAdapter = $scenarioAdapter;
        $this->adapterScanner = $adapterScanner;
    }

    /**
     * 适配器列表页面
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_adapter_list', '查看场景适配器列表', 'mdi-view-list', '查看场景适配器列表')]
    public function index(): string
    {
        $page = (int)$this->request->getGet('page', 1);
        $pageSize = 20;

        // 获取适配器列表
        $adapters = $this->scenarioAdapter->reset()
            ->pagination($page, $pageSize)
            ->order(AiScenarioAdapter::fields_CREATED_TIME, 'DESC')
            ->select()
            ->fetch();

        $this->assign('adapters', $adapters->getItems());
        $this->assign('pagination', $adapters->getPagination());

        return $this->fetch();/*  */
    }

    /**
     * 适配器详情页面
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_adapter_detail', '查看场景适配器详情', 'mdi-information', '查看场景适配器详情')]
    public function detail(): string
    {
        $id = (int)$this->request->getGet('id');
        
        if (!$id) {
            Message::error(__('适配器ID不能为空'));
            return $this->redirect($this->_url->getBackendUrl('*/backend/adapter'));
        }

        $adapter = $this->scenarioAdapter->reset()->load($id);
        
        if (!$adapter->getId()) {
            Message::error(__('适配器不存在'));
            return $this->redirect($this->_url->getBackendUrl('*/backend/adapter'));
        }

        // 获取适配器实例信息
        $adapterInstance = $this->adapterScanner->getAdapter($adapter->getData(AiScenarioAdapter::fields_CODE));
        
        $this->assign('adapter', $adapter);
        $this->assign('adapterInstance', $adapterInstance);
        $this->assign('supportedModels', $adapter->getSupportedModels());
        $this->assign('paramTemplate', $adapter->getParamTemplate());
        $this->assign('examples', $adapter->getExamples());

        return $this->fetch();
    }

    /**
     * 扫描适配器
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_adapter_scan', '扫描场景适配器', 'mdi-radar', '扫描场景适配器')]
    public function scan(): string
    {
        try {
            $scannedAdapters = $this->adapterScanner->scanAllAdapters();
            
            Message::success(
                __('成功扫描 %{count} 个适配器', ['count' => count($scannedAdapters)])
            );
        } catch (\Exception $e) {
            Message::error(__('适配器扫描失败: %{error}', ['error' => $e->getMessage()]));
        }

        return $this->redirect($this->_url->getBackendUrl('*/backend/adapter'));
    }

    /**
     * 切换适配器状态
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_adapter_toggle', '切换场景适配器状态', 'mdi-toggle-switch', '启用或禁用场景适配器')]
    public function toggleStatus(): string
    {
        $id = (int)$this->request->getPost('id');
        
        if (!$id) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '适配器ID不能为空'
            ]);
        }

        $adapter = $this->scenarioAdapter->reset()->load($id);
        
        if (!$adapter->getId()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '适配器不存在'
            ]);
        }

        try {
            $newStatus = $adapter->isActive() ? 0 : 1;
            $adapter->setData(AiScenarioAdapter::fields_IS_ACTIVE, $newStatus);
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

        $adapterInstance = $this->adapterScanner->getAdapter($code);
        
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
            $cleanedCount = $this->adapterScanner->cleanupInvalidAdapters();
            
            Message::success(
                sprintf('成功清理 %d 个无效适配器', $cleanedCount)
            );
        } catch (\Exception $e) {
            Message::error('清理失败: ' . $e->getMessage());
        }

        return $this->redirect($this->_url->getBackendUrl('*/backend/adapter'));
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
            $stats = $this->adapterScanner->getAdapterStats();
            
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
     * JSON响应
     * 
     * @param array $data
     * @return string
     */
    private function jsonResponse(array $data): string
    {
        $this->response->setHeader('Content-Type', 'application/json');
        return json_encode($data);
    }
}
