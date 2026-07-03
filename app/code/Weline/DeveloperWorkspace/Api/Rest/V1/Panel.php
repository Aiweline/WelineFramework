<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Api\Rest\V1;

use Weline\DeveloperWorkspace\Api\DevToolRestController;
use Weline\DeveloperWorkspace\Observer\DevToolPanelObserver;
use Weline\DeveloperWorkspace\Service\PanelAccessService;
use Weline\Framework\Http\Response;

class Panel extends DevToolRestController
{
    public function getIndex(): Response
    {
        $access = new PanelAccessService();
        if (!$access->canAccessPanel($this->request)) {
            return $access->noStore(Response::text('weline panel is not allowed', 403));
        }

        $html = (new DevToolPanelObserver($this->request))->renderPanel();

        return $access->noStore(Response::html($html));
    }

    public function postSession(): Response
    {
        $access = new PanelAccessService();
        if (!$access->authenticate($this->request)) {
            return $access->noStore(Response::json([
                'success' => false,
                'message' => (string)__('Weline 面板 token 无效。'),
            ], 403));
        }

        return $access->issueSession(Response::json([
            'success' => true,
            'message' => (string)__('Weline 面板已授权。'),
            'ttl' => $access->sessionTtl(),
        ]));
    }
}
