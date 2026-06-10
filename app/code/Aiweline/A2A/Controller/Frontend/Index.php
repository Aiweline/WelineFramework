<?php

declare(strict_types=1);

namespace Aiweline\A2A\Controller\Frontend;

use Aiweline\A2A\Service\TradingWorkspaceDataProvider;
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

        return $this->getTemplate()->fetch('Aiweline_A2A::templates/Frontend/Index/index.phtml');
    }
}
