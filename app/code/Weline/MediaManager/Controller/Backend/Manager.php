<?php

declare(strict_types=1);

namespace Weline\MediaManager\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;

class Manager extends BackendController
{
    public function index()
    {
        // 使用 path 相对当前页 origin，避免端口/协议不一致导致 XHR 挂起或跨域
        $connectorUrl = $this->_url->getBackendUrlPath('media/backend/connector');
        $this->assign('connector_url', $connectorUrl);
        return $this->fetch('manager.phtml');
    }
}
