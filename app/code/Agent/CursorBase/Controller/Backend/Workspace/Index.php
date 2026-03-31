<?php

declare(strict_types=1);

namespace Agent\CursorBase\Controller\Backend\Workspace;

use Weline\Admin\Controller\BaseController;

class Index extends BaseController
{
    public function index(): string
    {
        return (string) $this->fetchBase(
            'Agent_CursorBase::backend/templates/workspace/index.phtml',
            [
                'title' => __('Cursor 工作台'),
                'description' => __('该页面用于确认 CursorBase 后台入口可用。'),
                'monitoring_hint' => __('监控建议：使用 php bin/w cursor:supervisor:status 查看进程状态。'),
            ]
        );
    }
}
