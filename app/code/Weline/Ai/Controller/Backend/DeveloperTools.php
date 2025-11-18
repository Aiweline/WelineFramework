<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Ai\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Manager\Message;
use Weline\Framework\Acl\Acl;

/**
 * 开发者工具管理控制器
 * 
 * 功能：
 * - API测试工具
 * - 代码生成器
 * - 调试工具
 * - 文档生成
 */
#[Acl('Weline_Ai::ai_developer_tools', '开发者工具', 'mdi-tools', '开发者工具', 'Weline_Ai::ai')]
class DeveloperTools extends BackendController
{
    /**
     * 开发者工具首页
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_developer_tools_index', '查看开发者工具', 'mdi-view-dashboard', '查看开发者工具')]
    public function index(): string
    {
        try {
            // 获取系统信息
            $systemInfo = [
                'php_version' => PHP_VERSION,
                'framework_version' => 'Weline Framework',
                'server_time' => date('Y-m-d H:i:s'),
                'timezone' => date_default_timezone_get(),
            ];
            
            $this->assign('system_info', $systemInfo);
            
            return $this->fetch();

        } catch (\Exception $e) {
            Message::error(__('加载开发者工具失败：%{1}', $e->getMessage()));
            return $this->fetch();
        }
    }

    /**
     * API测试工具
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_developer_tools_api_test', 'API测试工具', 'mdi-api', 'API测试工具')]
    public function apiTest(): string
    {
        return $this->fetch('api_test');
    }

    /**
     * 代码生成器
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_developer_tools_code_generator', '代码生成器', 'mdi-code-tags', '代码生成器')]
    public function codeGenerator(): string
    {
        return $this->fetch('code_generator');
    }

    /**
     * 调试工具
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_developer_tools_debug', '调试工具', 'mdi-bug', '调试工具')]
    public function debug(): string
    {
        return $this->fetch('debug');
    }
}
