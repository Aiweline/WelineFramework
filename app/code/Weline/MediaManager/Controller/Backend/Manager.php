<?php

declare(strict_types=1);

namespace Weline\MediaManager\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;

class Manager extends BackendController
{
    public function index()
    {
        $connectorUrl = $this->_url->getBackendUrl('media/backend/connector');
        $this->assign('connector_url', $connectorUrl);
        return $this->fetch('manager.phtml');
    }
}
