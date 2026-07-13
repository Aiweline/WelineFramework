<?php

declare(strict_types=1);

namespace Weline\Backend\Api\Menu;

use Weline\Backend\Config\MenuXmlReader;
use Weline\Backend\Model\Menu;
use Weline\Framework\Http\Request;

/**
 * Data-only backend page-header boundary for themes.
 *
 * Backend keeps ownership of its menu ORM and XML fallback. Consumers receive
 * only the resolved title/breadcrumb payload and never see mutable menu models.
 */
final class PageHeaderResolver
{
    public function __construct(
        private readonly Menu $menuPrototype,
        private readonly MenuXmlReader $menuXmlReader,
    ) {
    }

    /**
     * @return array{title:string,breadcrumbs:list<array{title:string,url:string,active:bool}>,has_menu:bool}
     */
    public function resolve(Request $request, string $fallbackTitle = ''): array
    {
        $menu = $this->findCurrentMenu($request);
        if ($menu->getId()) {
            return [
                'title' => $this->menuTitle($menu, $fallbackTitle),
                'breadcrumbs' => $this->buildBreadcrumbs($request, $menu),
                'has_menu' => true,
            ];
        }

        $configMenu = $this->findCurrentMenuConfig($request);
        if ($configMenu !== null) {
            return [
                'title' => $this->configTitle($configMenu['item'], $fallbackTitle),
                'breadcrumbs' => $this->buildConfigBreadcrumbs($request, $configMenu['item'], $configMenu['lookup']),
                'has_menu' => true,
            ];
        }

        return ['title' => $fallbackTitle, 'breadcrumbs' => [], 'has_menu' => false];
    }

    private function findCurrentMenu(Request $request): Menu
    {
        $routePath = trim($request->getRouteUrlPath(), '/');
        $candidates = array_values(array_unique(array_filter([
            $routePath,
            '/' . $routePath,
            strtolower($routePath),
            '/' . strtolower($routePath),
        ])));

        $menu = $this->newMenu();
        foreach ($candidates as $candidate) {
            $current = $menu->clear()->reset()
                ->where(Menu::schema_fields_ACTION, $candidate)
                ->find()
                ->fetch();
            if ($current->getId()) {
                return $current;
            }
        }
        return $menu->clear()->reset();
    }

    /** @return array{item:array<string,mixed>,lookup:array<string,array<string,mixed>>}|null */
    private function findCurrentMenuConfig(Request $request): ?array
    {
        $routePath = trim($request->getRouteUrlPath(), '/');
        $candidates = array_values(array_unique(array_filter([$routePath, strtolower($routePath)])));
        if ($candidates === []) {
            return null;
        }

        try {
            $configs = $this->menuXmlReader->read();
        } catch (\Throwable) {
            return null;
        }

        $lookup = [];
        $current = null;
        foreach ($configs as $moduleConfig) {
            $items = $moduleConfig['data'] ?? [];
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $source = (string)($item['source'] ?? '');
                if ($source !== '') {
                    $lookup[$source] = $item;
                }
                $action = trim((string)($item['action'] ?? ''), '/');
                if ($action !== '' && in_array(strtolower($action), $candidates, true)) {
                    $current = $item;
                }
            }
        }
        return $current === null ? null : ['item' => $current, 'lookup' => $lookup];
    }

    /** @return list<array{title:string,url:string,active:bool}> */
    private function buildBreadcrumbs(Request $request, Menu $menu): array
    {
        $items = [];
        $lookup = $this->newMenu();
        $menu->getParentPaths($lookup, Menu::schema_fields_ID, Menu::schema_fields_PID, Menu::schema_fields_ORDER, 'ASC');
        $parent = $menu->getData('parents')[0] ?? null;
        if ($parent instanceof Menu) {
            $this->appendParentBreadcrumbs($request, $parent, $items);
        }
        $items[] = $this->menuBreadcrumb($request, $menu, true);
        return $items;
    }

    /** @param list<array{title:string,url:string,active:bool}> $items */
    private function appendParentBreadcrumbs(Request $request, Menu $parent, array &$items): void
    {
        $grandParent = $parent->getData('parents')[0] ?? null;
        if ($grandParent instanceof Menu) {
            $this->appendParentBreadcrumbs($request, $grandParent, $items);
        }
        $items[] = $this->menuBreadcrumb($request, $parent, false);
    }

    /**
     * @param array<string,mixed> $menu
     * @param array<string,array<string,mixed>> $lookup
     * @return list<array{title:string,url:string,active:bool}>
     */
    private function buildConfigBreadcrumbs(Request $request, array $menu, array $lookup): array
    {
        $items = [];
        $this->appendConfigParentBreadcrumbs($request, $menu, $lookup, $items);
        $items[] = $this->configBreadcrumb($request, $menu, true);
        return $items;
    }

    /**
     * @param array<string,mixed> $menu
     * @param array<string,array<string,mixed>> $lookup
     * @param list<array{title:string,url:string,active:bool}> $items
     */
    private function appendConfigParentBreadcrumbs(Request $request, array $menu, array $lookup, array &$items): void
    {
        $parentSource = (string)($menu['parent'] ?? $menu['parent_source'] ?? '');
        if ($parentSource === '' || !isset($lookup[$parentSource])) {
            return;
        }
        $parent = $lookup[$parentSource];
        $this->appendConfigParentBreadcrumbs($request, $parent, $lookup, $items);
        $items[] = $this->configBreadcrumb($request, $parent, false);
    }

    /** @return array{title:string,url:string,active:bool} */
    private function menuBreadcrumb(Request $request, Menu $menu, bool $active): array
    {
        $action = trim((string)$menu->getData(Menu::schema_fields_ACTION), '/');
        return [
            'title' => $this->menuTitle($menu),
            'url' => $active || $action === '' ? '' : $request->getUrlBuilder()->getBackendUrl('/' . $action),
            'active' => $active,
        ];
    }

    /** @param array<string,mixed> $menu */
    private function configBreadcrumb(Request $request, array $menu, bool $active): array
    {
        $action = trim((string)($menu['action'] ?? ''), '/');
        return [
            'title' => $this->configTitle($menu),
            'url' => $active || $action === '' ? '' : $request->getUrlBuilder()->getBackendUrl('/' . $action),
            'active' => $active,
        ];
    }

    private function menuTitle(Menu $menu, string $fallbackTitle = ''): string
    {
        $title = (string)($menu->getData(Menu::schema_fields_TITLE) ?: $menu->getData(Menu::schema_fields_NAME) ?: $fallbackTitle);
        return $title === '' ? '' : (string)__($title);
    }

    /** @param array<string,mixed> $menu */
    private function configTitle(array $menu, string $fallbackTitle = ''): string
    {
        $title = (string)($menu['title'] ?? $menu['name'] ?? $fallbackTitle);
        return $title === '' ? '' : (string)__($title);
    }

    private function newMenu(): Menu
    {
        return (clone $this->menuPrototype)->clear()->reset();
    }
}
