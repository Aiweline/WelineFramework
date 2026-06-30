<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Api\Rest\V1;

use Weline\DeveloperWorkspace\Api\DevToolRestController;
use Weline\DeveloperWorkspace\Observer\DevToolPanelObserver;
use Weline\Framework\App\Env;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Response;

class Panel extends DevToolRestController
{
    public function getIndex(): Response
    {
        if (!$this->isAllowed()) {
            return Response::text('dev tool panel is not allowed', 403);
        }

        $html = (new DevToolPanelObserver($this->request))->renderPanel();

        return Response::html($html)
            ->setHeader('Cache-Control', 'no-store, max-age=0');
    }

    private function isAllowed(): bool
    {
        if ((\defined('DEV') && DEV) || (\defined('DEBUG') && DEBUG)) {
            return true;
        }

        $cookieName = (string)Env::get('dev_tool.cookie_name', 'w_dev_tool');

        return Cookie::get($cookieName) === '1';
    }
}
