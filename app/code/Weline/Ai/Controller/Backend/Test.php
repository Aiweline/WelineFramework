<?php
declare(strict_types=1);

namespace Weline\Ai\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;

/**
 * AI模块测试控制器
 */
#[Acl('Weline_Ai::ai_test', 'AI测试', 'mdi-test-tube', 'AI模块测试', 'Weline_Ai::ai')]
class Test extends BackendController
{
    /**
     * 测试页面
     * 
     * @return string
     */
    public function index(): string
    {
        return '<h1>AI模块测试页面</h1><p>如果您能看到这个页面，说明AI模块的路由和控制器工作正常！</p><p>访问时间：' . date('Y-m-d H:i:s') . '</p>';
    }
}
