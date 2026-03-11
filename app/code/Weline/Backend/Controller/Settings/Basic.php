<?php
declare(strict_types=1);

namespace Weline\Backend\Controller\Settings;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\Message;

/**
 * 基础设置控制器
 * 
 * 功能：
 * - 管理站点名称、时区等基础配置
 */
#[Acl('Weline_Backend::basic_settings', '基础设置', 'mdi-settings', '基础设置', 'Weline_Backend::system_config_group')]
class Basic extends BackendController
{
    /**
     * 基础设置页面
     * 
     * @return string
     */
    #[Acl('Weline_Backend::basic_settings_index', '查看基础设置', 'mdi-settings', '查看基础设置')]
    public function index(): string
    {
        try {
            // TODO: 从配置获取基础设置
            $settings = [
                'site_name' => '',
                'site_url' => '',
                'timezone' => 'Asia/Shanghai',
                'language' => 'zh_Hans_CN',
            ];
            
            $this->assign('settings', $settings);
            
            return $this->fetch();
            
        } catch (\Exception $e) {
            Message::error(__('加载基础设置失败：%{1}', $e->getMessage()));
            $this->assign('settings', []);
            return $this->fetch();
        }
    }
    
    /**
     * 保存基础设置
     * 
     * @return string
     */
    #[Acl('Weline_Backend::basic_settings_save', '保存基础设置', 'mdi-content-save', '保存基础设置')]
    public function save(): string
    {
        if (!$this->isPost()) {
            return $this->jsonResponse(false, __('无效的请求方法'));
        }
        
        try {
            // TODO: 保存基础设置到配置
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

