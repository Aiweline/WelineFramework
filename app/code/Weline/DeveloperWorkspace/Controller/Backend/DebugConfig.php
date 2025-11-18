<?php
declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\Message;

/**
 * Debug配置控制器
 * 
 * 功能：
 * - 管理开启debug的key配置
 */
#[Acl('Weline_DeveloperWorkspace::debug_config', 'Debug配置', 'mdi-bug', 'Debug配置', 'Weline_Backend::debug_tools')]
class DebugConfig extends BackendController
{
    /**
     * Debug配置页面
     * 
     * @return string
     */
    #[Acl('Weline_DeveloperWorkspace::debug_config_index', '查看Debug配置', 'mdi-bug', '查看Debug配置')]
    public function index(): string
    {
        try {
            // TODO: 从配置获取Debug配置
            $config = [
                'debug_key' => '',
                'is_enabled' => false,
            ];
            
            $this->assign('config', $config);
            
            return $this->fetch();
            
        } catch (\Exception $e) {
            Message::error(__('加载Debug配置失败：%{1}', $e->getMessage()));
            $this->assign('config', []);
            return $this->fetch();
        }
    }
    
    /**
     * 保存Debug配置
     * 
     * @return string
     */
    #[Acl('Weline_DeveloperWorkspace::debug_config_save', '保存Debug配置', 'mdi-content-save', '保存Debug配置')]
    public function save(): string
    {
        if (!$this->isPost()) {
            return $this->jsonResponse(false, __('无效的请求方法'));
        }
        
        try {
            // TODO: 保存Debug配置到配置
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

