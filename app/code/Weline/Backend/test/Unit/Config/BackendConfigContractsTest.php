<?php

declare(strict_types=1);

namespace Weline\Backend\test\Unit\Config;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllBackendConfigContractsPass().
 */
final class BackendConfigContractsTest extends TestCase
{
    public function testAllBackendConfigContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'MenuActionRouter::testBackendMenuActionsResolveToRegisteredRoutes' => function () {
            $this->assert_MenuActionRouter_testBackendMenuActionsResolveToRegisteredRoutes();
        },
        ];
    }

/** @return array<string,string> source => action */
private function expectedLeafActions_MenuActionRouter(): array
{
    return ['Weline_Backend::system_maintenance_mode' => '*/maintenance', 'Weline_Backend::system_backup' => '*/backup', 'Weline_Backend::system_monitor' => '*/monitor', 'Weline_Backend::access_log' => '*/access-log', 'Weline_Backend::backend_config' => '*/backend/config'];
}

/** @return list<string> */
private function removedPlaceholderSources_MenuActionRouter(): array
{
    return ['Weline_Backend::basic_settings', 'Weline_Backend::email_settings', 'Weline_Backend::storage_settings'];
}

private function assert_MenuActionRouter_testBackendMenuActionsResolveToRegisteredRoutes(): void
{
    $menuFile = BP . 'app/code/Weline/Backend/etc/backend/menu.xml';
    $routerFile = BP . 'generated/routers/backend_pc.php';
    self::assertFileExists($menuFile);
    self::assertFileExists($routerFile);
    $env = require BP . 'app/code/Weline/Backend/etc/env.php';
    $router = (string) ($env['router'] ?? 'system');
    self::assertNotSame('', $router);
    /** @var array<string,mixed> $routes */
    $routes = require $routerFile;
    self::assertIsArray($routes);
    $document = \simplexml_load_file($menuFile);
    self::assertNotFalse($document);
    $actions = [];
    $this->collectActions_MenuActionRouter($document->menu, $actions);
    foreach (self::expectedLeafActions_MenuActionRouter() as $source => $action) {
        self::assertArrayHasKey($source, $actions, 'Missing menu source: ' . $source);
        self::assertSame($action, $actions[$source], 'Wrong action for ' . $source);
        $path = \ltrim(\str_replace('*', $router, \trim($action)), '/');
        $path = \explode('?', $path, 2)[0];
        self::assertArrayHasKey($path, $routes, \sprintf('Menu %s action "%s" → "%s" is not registered in backend_pc routers', $source, $action, $path));
    }
    foreach (self::removedPlaceholderSources_MenuActionRouter() as $source) {
        self::assertArrayNotHasKey($source, $actions, 'Placeholder menu must be removed: ' . $source);
    }
    foreach (['system/settings/basic', 'system/settings/email', 'system/settings/storage'] as $gone) {
        self::assertArrayNotHasKey($gone, $routes, 'Placeholder route must be unregistered: ' . $gone);
    }
}

/**
 * @param \SimpleXMLElement|null $nodes
 * @param array<string,string> $actions
 */
private function collectActions_MenuActionRouter(?\SimpleXMLElement $nodes, array &$actions): void
{
    if ($nodes === null) {
        return;
    }
    foreach ($nodes as $node) {
        $source = \trim((string) ($node['source'] ?? ''));
        $action = \trim((string) ($node['action'] ?? ''));
        if ($source !== '' && $action !== '') {
            $actions[$source] = $action;
        }
        if (isset($node->menu)) {
            $this->collectActions_MenuActionRouter($node->menu, $actions);
        }
    }
}
}
