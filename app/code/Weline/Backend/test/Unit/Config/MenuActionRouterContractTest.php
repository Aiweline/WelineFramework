<?php

declare(strict_types=1);

namespace Weline\Backend\test\Unit\Config;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

/**
 * menu.xml action 经星号替换为 backend_router(system) 后必须命中已注册路由。
 * 回归：Settings/Maintenance 等 Controller 根路径勿再多写 /backend 段。
 */
final class MenuActionRouterContractTest extends TestCase
{
    /** @return array<string,string> source => action */
    private static function expectedLeafActions(): array
    {
        return [
            'Weline_Backend::basic_settings' => '*/settings/basic',
            'Weline_Backend::email_settings' => '*/settings/email',
            'Weline_Backend::storage_settings' => '*/settings/storage',
            'Weline_Backend::system_maintenance_mode' => '*/maintenance',
            'Weline_Backend::system_backup' => '*/backup',
            'Weline_Backend::system_monitor' => '*/monitor',
            'Weline_Backend::access_log' => '*/access-log',
            'Weline_Backend::backend_config' => '*/backend/config',
        ];
    }

    public function testBackendMenuActionsResolveToRegisteredRoutes(): void
    {
        $menuFile = BP . 'app/code/Weline/Backend/etc/backend/menu.xml';
        $routerFile = BP . 'generated/routers/backend_pc.php';
        self::assertFileExists($menuFile);
        self::assertFileExists($routerFile);

        $env = require BP . 'app/code/Weline/Backend/etc/env.php';
        $router = (string)($env['router'] ?? 'system');
        self::assertNotSame('', $router);

        /** @var array<string,mixed> $routes */
        $routes = require $routerFile;
        self::assertIsArray($routes);

        $document = \simplexml_load_file($menuFile);
        self::assertNotFalse($document);

        $actions = [];
        $this->collectActions($document->menu, $actions);

        foreach (self::expectedLeafActions() as $source => $action) {
            self::assertArrayHasKey($source, $actions, 'Missing menu source: ' . $source);
            self::assertSame($action, $actions[$source], 'Wrong action for ' . $source);

            $path = \ltrim(\str_replace('*', $router, \trim($action)), '/');
            $path = \explode('?', $path, 2)[0];
            self::assertArrayHasKey(
                $path,
                $routes,
                \sprintf('Menu %s action "%s" → "%s" is not registered in backend_pc routers', $source, $action, $path)
            );
        }
    }

    /**
     * @param \SimpleXMLElement|null $nodes
     * @param array<string,string> $actions
     */
    private function collectActions(?\SimpleXMLElement $nodes, array &$actions): void
    {
        if ($nodes === null) {
            return;
        }
        foreach ($nodes as $node) {
            $source = \trim((string)($node['source'] ?? ''));
            $action = \trim((string)($node['action'] ?? ''));
            if ($source !== '' && $action !== '') {
                $actions[$source] = $action;
            }
            if (isset($node->menu)) {
                $this->collectActions($node->menu, $actions);
            }
        }
    }
}
