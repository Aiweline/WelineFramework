<?php
declare(strict_types=1);

namespace Weline\Backend\Controller;

use Weline\Framework\App\Env;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\Message;
use Weline\Framework\Manager\ObjectManager;
use Weline\Maintenance\Helper\IpMatcher;
use Weline\Maintenance\Service\BackupManager;

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
            $retryAfter = Env::getInstance()->getConfig('maintenance_retry_after', 60);
            $bypassConfig = $this->getBypassConfig();
            $backupConfig = Env::getInstance()->getConfig('maintenance.backup', []);
            
            $this->assign('is_maintenance', $isMaintenance);
            $this->assign('maintenance_message', $maintenanceMessage);
            $this->assign('retry_after', $retryAfter);
            $this->assign('bypass_config', $bypassConfig);
            $this->assign('backup_config', $backupConfig);
            
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
     * 保存配置
     * 
     * @return string
     */
    #[Acl('Weline_Backend::system_maintenance_config', '配置系统维护模式', 'mdi-cog', '配置系统维护模式')]
    public function saveConfig(): string
    {
        if (!$this->isPost()) {
            return $this->jsonResponse(false, __('无效的请求方法'));
        }

        try {
            $env = Env::getInstance();
            
            // 维护模式基本配置
            $enabled = (bool)$this->request->getPost('enabled', false);
            $message = trim($this->request->getPost('message', ''));
            $retryAfter = (int)$this->request->getPost('retry_after', 60);
            
            $env->setConfig('maintenance', $enabled);
            $env->setConfig('maintenance_message', $message);
            $env->setConfig('maintenance_retry_after', $retryAfter);
            
            // 放行配置
            $bypassConfig = [
                'backend_paths' => $this->request->getPost('backend_paths', []),
                'ip_whitelist' => $this->request->getPost('ip_whitelist', []),
                'bypass_key' => [
                    'enabled' => (bool)$this->request->getPost('bypass_key_enabled', false),
                    'name' => trim($this->request->getPost('bypass_key_name', 'maintenance_key')),
                    'value' => trim($this->request->getPost('bypass_key_value', '')),
                    'methods' => $this->request->getPost('bypass_key_methods', ['url', 'header', 'cookie']),
                ],
                'log_bypass' => (bool)$this->request->getPost('log_bypass', false),
            ];
            
            // 验证IP白名单格式
            foreach ($bypassConfig['ip_whitelist'] as $ip) {
                if (!empty($ip) && !IpMatcher::isValidCidr($ip)) {
                    return $this->jsonResponse(false, __('IP白名单格式错误：%{1}', $ip));
                }
            }
            
            $env->setConfig('maintenance.bypass', $bypassConfig);
            
            // 备份配置（可选）
            $autoBackup = (bool)$this->request->getPost('auto_backup_before_maintenance', false);
            $backupTypes = $this->request->getPost('backup_types', []);
            
            if ($autoBackup) {
                $backupConfig = $env->getConfig('maintenance.backup', []);
                $backupConfig['auto_backup_before_maintenance'] = true;
                $backupConfig['backup_types'] = $backupTypes;
                $env->setConfig('maintenance.backup', $backupConfig);
            }
            
            return $this->jsonResponse(true, __('配置保存成功'));
            
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('保存配置失败：%{1}', $e->getMessage()));
        }
    }
    
    /**
     * 获取维护模式状态
     * 
     * @return bool
     */
    private function getMaintenanceStatus(): bool
    {
        return (bool)Env::getInstance()->getConfig('maintenance', false);
    }
    
    /**
     * 获取维护消息
     * 
     * @return string
     */
    private function getMaintenanceMessage(): string
    {
        return Env::getInstance()->getConfig('maintenance_message', __('系统维护中，请稍后再试'));
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
        $env = Env::getInstance();
        
        // 如果开启维护模式且配置了自动备份，先执行备份
        if ($enabled) {
            $backupConfig = $env->getConfig('maintenance.backup', []);
            if (!empty($backupConfig['auto_backup_before_maintenance'])) {
                try {
                    /** @var BackupManager $backupManager */
                    $backupManager = ObjectManager::getInstance(BackupManager::class);
                    $backupTypes = $backupConfig['backup_types'] ?? ['database', 'code'];
                    
                    foreach ($backupTypes as $type) {
                        $backupManager->createBackup($type, $this->session->getLoginUserID());
                    }
                } catch (\Exception $e) {
                    // 备份失败不影响维护模式开启，但记录错误
                    Message::warning(__('自动备份失败：%{1}，维护模式已开启', $e->getMessage()));
                }
            }
        }
        
        $env->setConfig('maintenance', $enabled);
        if (!empty($message)) {
            $env->setConfig('maintenance_message', $message);
        }
    }
    
    /**
     * 获取放行配置
     * 
     * @return array
     */
    private function getBypassConfig(): array
    {
        return Env::getInstance()->getConfig('maintenance.bypass', [
            'backend_paths' => [],
            'ip_whitelist' => [],
            'bypass_key' => [
                'enabled' => false,
                'name' => 'maintenance_key',
                'value' => '',
                'methods' => ['url', 'header', 'cookie'],
            ],
            'log_bypass' => false,
        ]);
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

