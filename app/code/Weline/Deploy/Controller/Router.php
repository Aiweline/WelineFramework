<?php

declare(strict_types=1);

namespace Weline\Deploy\Controller;

use Weline\Deploy\Service\DeployWebhookRouteService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\RouterInterface;

/**
 * 将带 ~wh~ 特征前缀的随机 Webhook 公网路径映射到框架内部路由。
 */
class Router implements RouterInterface
{
    private const INTERNAL_WEBHOOK = 'deploy/webhook/deploy';
    private const INTERNAL_VERSION = 'deploy/version';

    public static function process(string &$path, array &$rule): void
    {
        if (!empty($rule['module'])) {
            return;
        }

        $normalized = trim(str_replace('\\', '/', $path), '/');
        if ($normalized === '' || !str_starts_with($normalized, DeployWebhookRouteService::MARKER)) {
            return;
        }

        try {
            /** @var DeployWebhookRouteService $routeService */
            $routeService = ObjectManager::getInstance(DeployWebhookRouteService::class);
            $resolved = $routeService->getResolvedPaths();
        } catch (\Throwable) {
            return;
        }

        if ($resolved === null) {
            return;
        }

        if (hash_equals($resolved['webhook'], $normalized)) {
            $path = self::INTERNAL_WEBHOOK;
            return;
        }

        if (hash_equals($resolved['version'], $normalized)) {
            $path = self::INTERNAL_VERSION;
        }
    }
}
