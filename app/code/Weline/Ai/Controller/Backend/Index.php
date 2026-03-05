<?php
declare(strict_types=1);

namespace Weline\Ai\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;

/**
 * AI 中心默认入口：重定向到 Manager
 *
 * 处理 ai/backend/index 和 ai/backend 的访问，统一跳转到 ai/backend/manager
 *
 * @package Weline_Ai
 */
class Index extends BackendController
{
    /**
     * 重定向到 AI 管理聚合页
     */
    public function index(): string
    {
        $url = $this->getBackendUrl('ai/backend/manager');
        return $this->redirect($url);
    }
}
