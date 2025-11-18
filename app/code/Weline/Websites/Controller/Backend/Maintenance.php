<?php
declare(strict_types=1);

namespace Weline\Websites\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Manager\Message;
use Weline\Websites\Model\Website;

/**
 * 网站维护模式控制器
 * 
 * 功能：
 * - 网站级维护模式（后台仍可访问）
 */
#[Acl('Weline_Websites::website_maintenance', '网站维护模式', 'mdi-tools', '网站维护模式', 'Weline_Backend::website_service')]
class Maintenance extends BackendController
{
    /**
     * 维护模式管理首页
     * 
     * @return string
     */
    #[Acl('Weline_Websites::website_maintenance_index', '查看网站维护模式', 'mdi-tools', '查看网站维护模式')]
    public function index(): string
    {
        try {
            /** @var Website $websiteModel */
            $websiteModel = ObjectManager::getInstance(Website::class);
            $websites = $websiteModel->select()->fetchArray();
            
            // 获取每个网站的维护模式状态
            $maintenanceStatus = [];
            foreach ($websites as $website) {
                $websiteId = $website['website_id'] ?? $website['id'] ?? 0;
                $maintenanceStatus[$websiteId] = $this->getMaintenanceStatus($websiteId);
            }
            
            $this->assign('websites', $websites);
            $this->assign('maintenance_status', $maintenanceStatus);
            
            return $this->fetch();
            
        } catch (\Exception $e) {
            Message::error(__('加载维护模式失败：%{1}', $e->getMessage()));
            $this->assign('websites', []);
            $this->assign('maintenance_status', []);
            return $this->fetch();
        }
    }
    
    /**
     * 切换维护模式
     * 
     * @return string
     */
    #[Acl('Weline_Websites::website_maintenance_toggle', '切换网站维护模式', 'mdi-toggle-switch', '切换网站维护模式')]
    public function toggle(): string
    {
        if (!$this->isPost()) {
            return $this->jsonResponse(false, __('无效的请求方法'));
        }
        
        try {
            $websiteId = (int)$this->request->getPost('website_id', 0);
            $enabled = (bool)$this->request->getPost('enabled', false);
            
            if ($websiteId <= 0) {
                return $this->jsonResponse(false, __('无效的网站ID'));
            }
            
            $this->setMaintenanceStatus($websiteId, $enabled);
            
            return $this->jsonResponse(true, __('维护模式状态已更新'));
            
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('更新失败：%{1}', $e->getMessage()));
        }
    }
    
    /**
     * 获取维护模式状态
     * 
     * @param int $websiteId
     * @return bool
     */
    private function getMaintenanceStatus(int $websiteId): bool
    {
        // TODO: 从配置或数据库获取维护模式状态
        // 这里可以使用配置系统或数据库存储
        return false;
    }
    
    /**
     * 设置维护模式状态
     * 
     * @param int $websiteId
     * @param bool $enabled
     * @return void
     */
    private function setMaintenanceStatus(int $websiteId, bool $enabled): void
    {
        // TODO: 保存维护模式状态到配置或数据库
        // 这里可以使用配置系统或数据库存储
    }
    
    /**
     * JSON响应
     * 
     * @param bool $success
     * @param string $message
     * @param array $data
     * @return string
     */
    private function jsonResponse(bool $success, string $message, array $data = []): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        return json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE);
    }
}

