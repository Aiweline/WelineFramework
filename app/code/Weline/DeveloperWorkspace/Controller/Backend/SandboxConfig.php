<?php
declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\Message;

/**
 * 沙盒配置控制器
 * 
 * 功能：
 * - 管理开启沙盒的key配置
 */
#[Acl('Weline_DeveloperWorkspace::sandbox_config', '沙盒配置', 'mdi-code-tags-check', '沙盒配置', 'Weline_Backend::debug_tools')]
class SandboxConfig extends BackendController
{
    /**
     * 沙盒配置页面
     * 
     * @return string
     */
    #[Acl('Weline_DeveloperWorkspace::sandbox_config_index', '查看沙盒配置', 'mdi-code-tags-check', '查看沙盒配置')]
    public function index(): string
    {
        try {
            // TODO: 从配置获取沙盒配置
            $config = [
                'sandbox_key' => '',
                'is_enabled' => false,
            ];
            
            $this->assign('config', $config);
            
            return $this->fetch();
            
        } catch (\Exception $e) {
            Message::error(__('加载沙盒配置失败：%{1}', $e->getMessage()));
            $this->assign('config', []);
            return $this->fetch();
        }
    }
    
    /**
     * 保存沙盒配置
     * 
     * @return string
     */
    #[Acl('Weline_DeveloperWorkspace::sandbox_config_save', '保存沙盒配置', 'mdi-content-save', '保存沙盒配置')]
    public function save(): string
    {
        if (!$this->isPost()) {
            return $this->jsonResponse(false, __('无效的请求方法'));
        }
        
        try {
            // TODO: 保存沙盒配置到配置
            return $this->jsonResponse(true, __('保存成功'));
            
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('保存失败：%{1}', $e->getMessage()));
        }
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

