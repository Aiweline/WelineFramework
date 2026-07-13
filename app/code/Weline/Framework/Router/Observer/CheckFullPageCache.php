<?php

declare(strict_types=1);

namespace Weline\Framework\Router\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\FullPageCacheCoordinator;
use Weline\Framework\Runtime\RequestPipeline;
use Weline\Framework\Runtime\Runtime;

class CheckFullPageCache implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        if ((CLI && !Runtime::isPersistent()) || (!PROD && !Runtime::isPersistent())) {
            return;
        }

        if ((\defined('WLS_MAINTENANCE_WORKER') && WLS_MAINTENANCE_WORKER) || Env::system('maintenance')) {
            return;
        }

        $routerCacheEnabled = Env::get('cache.status.router_cache', 1);
        $frontendCacheEnabled = Env::get('cache.status.frontend_cache', 1);
        if (!$routerCacheEnabled || !$frontendCacheEnabled) {
            return;
        }

        $requestMethod = \w_env('request.method', 'GET');
        if ($requestMethod !== 'GET') {
            return;
        }

        if (\w_env_get('editor_mode') === '1' || \w_env_get('editor_mode') === 'true') {
            return;
        }

        if (!\w_env('url_parsed', false) || \w_env('is_backend', false)) {
            return;
        }

        $requestUri = \w_env('request.uri', '/');
        $backendPrefix = Env::getAreaRoutePrefix('backend') ?: 'admin';
        if (\str_starts_with($requestUri, '/' . $backendPrefix . '/') || \str_starts_with($requestUri, '/' . $backendPrefix)) {
            return;
        }

        try {
            /** @var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            $method = $request->getMethod() ?: 'GET';
        } catch (\Throwable) {
            return;
        }

        $coordinator = ObjectManager::getInstance(FullPageCacheCoordinator::class);
        if (!$coordinator->canServeCachedResponse($method)) {
            return;
        }

        $response = $coordinator->getCachedResponse($method);
        if ($response !== null) {
            RequestPipeline::registerEarlyResponse($response);
        }
    }
}
