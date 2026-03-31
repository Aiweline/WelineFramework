<?php

declare(strict_types=1);

namespace Agent\CursorSupervisor\Controller\Backend\Dashboard;

use Weline\Admin\Controller\BaseController;

class Index extends BaseController
{
    public function index(): string
    {
        return (string) $this->fetchBase('Agent_CursorSupervisor::backend/templates/dashboard/index.phtml');
    }
}

