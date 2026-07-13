<?php

namespace Weline\Visitor\Api\Rest;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\DeveloperAccessPolicy;
use Weline\Framework\Runtime\DeveloperAccessProviderInterface;

trait PanelProtectedTrait
{
    private ?DeveloperAccessProviderInterface $visitorPanelAccessService = null;

    protected function guardVisitorPanelApi(): ?string
    {
        if ($this->panelAccessService()->canAccessApi($this->request)) {
            return null;
        }

        return $this->error('访问面板数据需要有效的开发面板 Token', [], 403);
    }

    private function panelAccessService(): DeveloperAccessProviderInterface
    {
        if (!$this->visitorPanelAccessService) {
            $this->visitorPanelAccessService = ObjectManager::getInstance(DeveloperAccessPolicy::class);
        }

        return $this->visitorPanelAccessService;
    }
}
