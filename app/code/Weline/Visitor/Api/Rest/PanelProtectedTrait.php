<?php

namespace Weline\Visitor\Api\Rest;

use Weline\DeveloperWorkspace\Service\PanelAccessService;

trait PanelProtectedTrait
{
    private ?PanelAccessService $visitorPanelAccessService = null;

    protected function guardVisitorPanelApi(): ?string
    {
        if ($this->panelAccessService()->canAccessApi($this->request)) {
            return null;
        }

        return $this->error('访问面板数据需要有效的开发面板 Token', [], 403);
    }

    private function panelAccessService(): PanelAccessService
    {
        if (!$this->visitorPanelAccessService) {
            $this->visitorPanelAccessService = new PanelAccessService();
        }

        return $this->visitorPanelAccessService;
    }
}
