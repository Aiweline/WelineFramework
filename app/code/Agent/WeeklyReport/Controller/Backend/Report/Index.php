<?php

declare(strict_types=1);

namespace Agent\WeeklyReport\Controller\Backend\Report;

use Weline\Admin\Controller\BaseController;

class Index extends BaseController
{
    public function index(): string
    {
        return (string) $this->fetchBase('Agent_WeeklyReport::backend/templates/report/index.phtml');
    }
}

