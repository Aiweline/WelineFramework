<?php
declare(strict_types=1);

namespace Weline\Backend\Controller;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\Message;

/**
 * 系统维护模式控制器
 * 
 * 功能：
 * - 系统级维护模式（后台仍可访问）
 */
#[Acl('Weline_Backend::system_maintenance', '系统维护模式', 'mdi-tools', '系统维护模式', 'Weline_Backend::system_service')]
class Maintenance extends BackendController
{
    /**
     * 维护模式管理首页
     * 
     * @return string
     */
    #[Acl('Weline_Backend::system_maintenance_index', '查看系统维护模式', 'mdi-tools', '查看系统维护模式')]
    public function index(): string
    {
        try {
            $isMaintenance = $this->getMaintenanceStatus();
            $maintenanceMessage = $this->getMaintenanceMessage();
            
            $this->assign('is_maintenance', $isMaintenance);
            $this->assign('maintenance_message', $maintenanceMessage);
            
            return $this->fetch();
            
        } catch (\Exception $e) {
            Message::error(__('加载维护模式失败：%{1}', $e->getMessage()));
            $this->assign('is_maintenance', false);
            $this->assign('maintenance_message', '');
            return $this->fetch();
        }
    }
    
    /**
     * 切换维护模式
     * 
     * @return string
     */
    #[Acl('Weline_Backend::system_maintenance_toggle', '切换系统维护模式', 'mdi-toggle-switch', '切换系统维护模式')]
    public function toggle(): string
    {
        if (!$this->isPost()) {
            return $this->jsonResponse(false, __('无效的请求方法'));
        }
        
        try {
            $enabled = (bool)$this->request->getPost('enabled', false);
            $message = trim($this->request->getPost('message', ''));
            
            $this->setMaintenanceStatus($enabled, $message);
            
            return $this->jsonResponse(true, __('维护模式状态已更新'));
            
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('更新失败：%{1}', $e->getMessage()));
        }
    }
    
    /**
     * 获取维护模式状态
     * 
     * @return bool
     */
    private function getMaintenanceStatus(): bool
    {
        // TODO: 从配置或文件获取维护模式状态
        // 可以使用配置文件或数据库存储
        return false;
    }
    
    /**
     * 获取维护消息
     * 
     * @return string
     */
    private function getMaintenanceMessage(): string
    {
        // TODO: 从配置或文件获取维护消息
        return __('系统维护中，请稍后再试');
    }
    
    /**
     * 设置维护模式状态
     * 
     * @param bool $enabled
     * @param string $message
     * @return void
     */
    private function setMaintenanceStatus(bool $enabled, string $message): void
    {
        // TODO: 保存维护模式状态到配置或文件
        // 可以使用配置文件或数据库存储
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

