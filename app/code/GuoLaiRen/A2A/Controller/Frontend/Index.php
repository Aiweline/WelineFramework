<?php

declare(strict_types=1);

namespace GuoLaiRen\A2A\Controller\Frontend;

use GuoLaiRen\A2A\Service\TradingWorkspaceDataProvider;
use Weline\Framework\App\Controller\FrontendController;

class Index extends FrontendController
{
    public function __construct(
        private readonly TradingWorkspaceDataProvider $workspaceDataProvider
    ) {
    }

    public function index(): string
    {
        foreach ($this->workspaceDataProvider->getWorkspace() as $key => $value) {
            $this->assign($key, $value);
        }

        return $this->getTemplate()->fetch('GuoLaiRen_A2A::templates/Frontend/Index/index.phtml');
    }
}
