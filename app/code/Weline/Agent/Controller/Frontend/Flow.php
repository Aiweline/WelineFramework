<?php
declare(strict_types=1);

namespace Weline\Agent\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Response;

/**
 * 可视化角色流程编辑/运行页（前台）
 *
 * 类 coze 简化版：节点 = 选择角色 + 可选系统提示词覆盖
 * （可「从角色导入」），v1 串行拓扑执行。
 * 独立全屏应用文档，不走 theme layout。
 * @Extra type=fpc enabled=false
 */
class Flow extends FrontendController
{
    public function index(): Response
    {
        \ob_start();
        include BP . 'app/code/Weline/Agent/view/templates/frontend/flow/index.phtml';

        $response = Response::text((string)\ob_get_clean(), 200, 'text/html; charset=utf-8');
        $response->setHeader('Cache-Control', 'no-store, max-age=0');
        $response->setHeader('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
