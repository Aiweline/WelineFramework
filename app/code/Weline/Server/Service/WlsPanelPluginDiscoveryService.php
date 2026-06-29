<?php
declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\App\Env;
use Weline\Framework\MarketplaceMeta\MarketplaceMetaReader;

class WlsPanelPluginDiscoveryService
{
    public const WLS_PLUGIN_TAG = 'module:wls';
    public const PANEL_SURFACE = 'backend';

    /**
     * @return array{items: array<int, array<string, mixed>>, count: int, error: string}
     */
    public function getInstalledPlugins(?string $locale = null): array
    {
        try {
            $result = \w_query('appstore', 'installedModules', [
                'tag' => self::WLS_PLUGIN_TAG,
                'surface' => self::PANEL_SURFACE,
                'locale' => $locale ?: '',
            ]);
        } catch (\Throwable $e) {
            return [
                'items' => [],
                'count' => 0,
                'error' => $e->getMessage(),
            ];
        }

        if (!is_array($result)) {
            return [
                'items' => [],
                'count' => 0,
                'error' => (string)__('AppStore plugin query returned an invalid response.'),
            ];
        }

        $items = is_array($result['items'] ?? null) ? array_values($result['items']) : [];
        $items = $this->normalizeInstalledPluginItems($items);

        return [
            'items' => $items,
            'count' => (int)($result['count'] ?? count($items)),
            'error' => '',
        ];
    }

    /**
     * @param array{items?: array<int, array<string, mixed>>, count?: int, error?: string}|null $pluginState
     * @return array{items: array<int, array<string, mixed>>, count: int, installed_count: int, missing_count: int, error: string}
     */
    public function getOperationCapabilities(?string $locale = null, ?array $pluginState = null): array
    {
        $pluginState ??= $this->getInstalledPlugins($locale);
        $plugins = \is_array($pluginState['items'] ?? null) ? \array_values($pluginState['items']) : [];
        $items = [];
        $installedCount = 0;

        foreach ($this->getOperationDefinitions() as $definition) {
            $requiredTag = (string)$definition['required_tag'];
            $plugin = $this->findPluginByTag($plugins, $requiredTag);
            $installed = $plugin !== null;
            if ($installed) {
                $installedCount++;
            }

            $pluginUrl = $installed ? $this->resolvePluginPanelUrl($plugin ?? []) : '';
            $pluginTags = $installed ? $this->collectPluginTagCodes($plugin ?? []) : [];
            $items[] = [
                'key' => (string)$definition['key'],
                'title' => (string)__((string)$definition['title']),
                'description' => (string)__((string)$definition['description']),
                'module' => (string)$definition['module'],
                'required_tag' => $requiredTag,
                'feature_tag' => (string)$definition['feature_tag'],
                'capabilities' => $definition['capabilities'],
                'install_query' => (string)$definition['install_query'],
                'installed' => $installed,
                'status' => $installed ? 'installed' : 'missing',
                'status_label' => $installed ? (string)__('Installed') : (string)__('Plugin required'),
                'action_label' => $installed
                    ? ($pluginUrl !== '' ? (string)__('Open Capability') : (string)__('Installed Plugin'))
                    : (string)__('Install Plugin'),
                'action_url' => $pluginUrl,
                'plugin_module' => $installed ? (string)($plugin['module_name'] ?? '') : '',
                'plugin_name' => $installed ? (string)($plugin['display_name'] ?? $plugin['module_name'] ?? '') : '',
                'plugin_tags' => $pluginTags,
            ];
        }

        return [
            'items' => $items,
            'count' => \count($items),
            'installed_count' => $installedCount,
            'missing_count' => \count($items) - $installedCount,
            'error' => (string)($pluginState['error'] ?? ''),
        ];
    }

    /**
     * @param array{items?: array<int, array<string, mixed>>, count?: int, error?: string}|null $pluginState
     * @return array{items: array<int, array<string, mixed>>, count: int, error: string}
     */
    public function getPanelContributions(?string $locale = null, ?array $pluginState = null): array
    {
        $pluginState ??= $this->getInstalledPlugins($locale);
        $plugins = \is_array($pluginState['items'] ?? null) ? \array_values($pluginState['items']) : [];
        $items = [];
        $seen = [];

        foreach ($plugins as $plugin) {
            if (!\is_array($plugin)) {
                continue;
            }
            foreach ($this->extractPluginPanelContributions($plugin, $locale) as $item) {
                $identity = $this->resolvePanelContributionIdentity($item);
                if ($identity === '') {
                    $items[] = $item;
                    continue;
                }
                if (isset($seen[$identity])) {
                    $items[$seen[$identity]] = $this->mergePanelContributionItems($items[$seen[$identity]], $item);
                    continue;
                }
                $seen[$identity] = \count($items);
                $items[] = $item;
            }
        }

        \usort($items, static function (array $left, array $right): int {
            $order = ((int)($left['order'] ?? 500)) <=> ((int)($right['order'] ?? 500));
            if ($order !== 0) {
                return $order;
            }

            return \strnatcasecmp((string)($left['label'] ?? ''), (string)($right['label'] ?? ''));
        });

        return [
            'items' => $items,
            'count' => \count($items),
            'error' => (string)($pluginState['error'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function resolvePanelContributionIdentity(array $item): string
    {
        foreach (['url', 'key'] as $key) {
            $value = \trim((string)($item[$key] ?? ''));
            if ($value !== '') {
                return $key . ':' . $value;
            }
        }

        $moduleName = \trim((string)($item['module_name'] ?? ''));
        $label = \trim((string)($item['label'] ?? ''));
        return $moduleName !== '' && $label !== '' ? 'module-label:' . $moduleName . ':' . $label : '';
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    private function mergePanelContributionItems(array $existing, array $incoming): array
    {
        $existingChildren = \is_array($existing['children'] ?? null) ? \array_values($existing['children']) : [];
        $incomingChildren = \is_array($incoming['children'] ?? null) ? \array_values($incoming['children']) : [];
        if ($existingChildren !== [] || $incomingChildren !== []) {
            $existing['children'] = $this->mergePanelContributionChildren($existingChildren, $incomingChildren);
        }

        foreach (['description', 'group', 'module_name', 'plugin_name'] as $key) {
            if (\trim((string)($existing[$key] ?? '')) === '' && \trim((string)($incoming[$key] ?? '')) !== '') {
                $existing[$key] = $incoming[$key];
            }
        }

        if ((int)($incoming['order'] ?? 500) < (int)($existing['order'] ?? 500)) {
            $existing['order'] = (int)$incoming['order'];
        }

        return $existing;
    }

    /**
     * @param array<int, mixed> $existingChildren
     * @param array<int, mixed> $incomingChildren
     * @return array<int, array<string, mixed>>
     */
    private function mergePanelContributionChildren(array $existingChildren, array $incomingChildren): array
    {
        $merged = [];
        $seen = [];
        foreach ([$existingChildren, $incomingChildren] as $children) {
            foreach ($children as $child) {
                if (!\is_array($child)) {
                    continue;
                }
                $identity = \trim((string)($child['url'] ?? ''));
                if ($identity === '') {
                    $identity = \trim((string)($child['key'] ?? ''));
                }
                if ($identity === '') {
                    $merged[] = $child;
                    continue;
                }
                if (isset($seen[$identity])) {
                    $merged[$seen[$identity]] = $this->mergePanelContributionItems($merged[$seen[$identity]], $child);
                    continue;
                }
                $seen[$identity] = \count($merged);
                $merged[] = $child;
            }
        }

        \usort($merged, static function (array $left, array $right): int {
            $order = ((int)($left['order'] ?? 500)) <=> ((int)($right['order'] ?? 500));
            if ($order !== 0) {
                return $order;
            }

            return \strnatcasecmp((string)($left['label'] ?? ''), (string)($right['label'] ?? ''));
        });

        return $merged;
    }

    /**
     * @return array{plugins: array<string, mixed>, operations: array<string, mixed>, contributions: array<string, mixed>, plugin_count: int, installed_operation_count: int, missing_operation_count: int, contribution_count: int, error: string}
     */
    public function refreshCapabilities(?string $locale = null): array
    {
        $plugins = $this->getInstalledPlugins($locale);
        $operations = $this->getOperationCapabilities($locale, $plugins);
        $contributions = $this->getPanelContributions($locale, $plugins);
        $error = (string)($plugins['error'] ?? '');
        if ($error === '') {
            $error = (string)($operations['error'] ?? '');
        }
        if ($error === '') {
            $error = (string)($contributions['error'] ?? '');
        }

        return [
            'plugins' => $plugins,
            'operations' => $operations,
            'contributions' => $contributions,
            'plugin_count' => (int)($plugins['count'] ?? 0),
            'installed_operation_count' => (int)($operations['installed_count'] ?? 0),
            'missing_operation_count' => (int)($operations['missing_count'] ?? 0),
            'contribution_count' => (int)($contributions['count'] ?? 0),
            'error' => $error,
        ];
    }

    /**
     * @param array<int, mixed> $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeInstalledPluginItems(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $item = $this->withLocalMarketplaceMeta($item);
            $panelUrl = $this->resolvePluginPanelUrl($item);
            if ($panelUrl !== '') {
                $hasPrimaryUrl = false;
                foreach (['wls_panel_url', 'panel_url', 'backend_url', 'capability_url', 'url'] as $key) {
                    if ($this->normalizePanelUrl($item[$key] ?? null) !== '') {
                        $hasPrimaryUrl = true;
                        break;
                    }
                }

                if (!$hasPrimaryUrl) {
                    $item['wls_panel_url'] = $panelUrl;
                }
                $item['panel_entry_url'] = $panelUrl;
            }

            $normalized[] = $item;
        }

        return $normalized;
    }

    /**
     * Installed AppStore snapshots can lag behind the local module during panel
     * development. The WLS shell should honor the module's latest local
     * wls_panel.menu declaration so secondary menu changes appear after refresh.
     *
     * @param array<string, mixed> $plugin
     * @return array<string, mixed>
     */
    private function withLocalMarketplaceMeta(array $plugin): array
    {
        $moduleName = \trim((string)($plugin['module_name'] ?? ''));
        if ($moduleName === '') {
            return $plugin;
        }

        $localMeta = $this->loadLocalMarketplaceMeta($moduleName);
        if ($localMeta === []) {
            return $plugin;
        }

        $currentMeta = \is_array($plugin['marketplace_meta'] ?? null) ? $plugin['marketplace_meta'] : [];
        $plugin['marketplace_meta'] = $currentMeta === []
            ? $localMeta
            : \array_replace_recursive($currentMeta, $localMeta);

        return $plugin;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadLocalMarketplaceMeta(string $moduleName): array
    {
        try {
            $moduleInfo = Env::getInstance()->getModuleInfo($moduleName);
            $moduleDir = \trim((string)($moduleInfo['base_path'] ?? $moduleInfo['path'] ?? ''));
            if ($moduleDir === '' && \defined('APP_CODE_PATH')) {
                $moduleDir = \rtrim((string)APP_CODE_PATH, "\\/") . DIRECTORY_SEPARATOR . \str_replace('_', DIRECTORY_SEPARATOR, $moduleName);
            }
            if ($moduleDir === '') {
                return [];
            }

            $result = (new MarketplaceMetaReader())->readFromModuleDir($moduleDir, $moduleName);
            return \is_array($result['meta'] ?? null) ? $result['meta'] : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, array{key: string, title: string, description: string, module: string, required_tag: string, feature_tag: string, install_query: string, capabilities: array<int, string>}>
     */
    private function getOperationDefinitions(): array
    {
        return [
            [
                'key' => 'php-profile',
                'title' => 'PHP Profiles',
                'description' => 'Configure PHP runtime profiles, versions, extensions, and per-project bindings.',
                'module' => 'Weline_PhpManager',
                'required_tag' => 'custom:wls-php-manager',
                'feature_tag' => 'feature:php-config',
                'install_query' => 'WLS PHP Manager',
                'capabilities' => ['php.config', 'php.extensions', 'project.php_profile'],
            ],
            [
                'key' => 'database-profile',
                'title' => 'Database Profiles',
                'description' => 'Store and open project database connection profiles from the WLS panel.',
                'module' => 'Weline_DbManager',
                'required_tag' => 'custom:wls-database-manager',
                'feature_tag' => 'feature:database-profile',
                'install_query' => 'WLS Database Manager',
                'capabilities' => ['database.profile', 'project.database_profile'],
            ],
            [
                'key' => 'file-manager',
                'title' => 'File Manager',
                'description' => 'Manage project paths through a path-guarded WLS panel plugin.',
                'module' => 'Weline_FileManager',
                'required_tag' => 'custom:wls-file-manager',
                'feature_tag' => 'feature:file-manager',
                'install_query' => 'WLS File Manager',
                'capabilities' => ['files.read', 'files.write', 'project.path'],
            ],
            [
                'key' => 'deploy',
                'title' => 'Deploy Releases',
                'description' => 'Add webhook and tag-driven release flows for panel-managed child projects.',
                'module' => 'Weline_Deploy',
                'required_tag' => 'custom:wls-deploy',
                'feature_tag' => 'feature:tag-deploy',
                'install_query' => 'WLS Deploy',
                'capabilities' => ['deploy.webhook', 'deploy.tag', 'project.release'],
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $plugins
     */
    private function findPluginByTag(array $plugins, string $requiredTag): ?array
    {
        $needle = $this->normalizeTagCode($requiredTag);
        foreach ($plugins as $plugin) {
            if (!\is_array($plugin)) {
                continue;
            }
            if (\in_array($needle, $this->collectPluginTagCodes($plugin), true)) {
                return $plugin;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $plugin
     * @return array<int, array<string, mixed>>
     */
    private function extractPluginPanelContributions(array $plugin, ?string $locale): array
    {
        $containers = [];
        $this->appendContributionContainers($plugin, $containers);

        $marketplaceMeta = $plugin['marketplace_meta'] ?? null;
        if (\is_array($marketplaceMeta)) {
            $this->appendContributionContainers($marketplaceMeta, $containers);
        }

        $items = [];
        $index = 0;
        foreach ($containers as $container) {
            $menuEntries = $this->extractMenuEntries($container);
            foreach ($menuEntries as $entry) {
                $item = $this->normalizePanelContribution($entry, $plugin, $locale, $index++);
                if ($item !== null) {
                    $items[] = $item;
                }
            }
            if ($menuEntries !== []) {
                continue;
            }

            $item = $this->normalizePanelContribution($container, $plugin, $locale, $index++);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        if ($items === []) {
            $url = $this->resolvePluginPanelUrl($plugin);
            if ($url !== '') {
                $item = $this->normalizePanelContribution(['url' => $url], $plugin, $locale, $index);
                if ($item !== null) {
                    $items[] = $item;
                }
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, array<string, mixed>> $containers
     */
    private function appendContributionContainers(array $source, array &$containers): void
    {
        $containers[] = $source;
        foreach (['wls_panel', 'panel_entry', 'backend_entry'] as $key) {
            if (\is_array($source[$key] ?? null)) {
                $containers[] = $source[$key];
            }
        }
    }

    /**
     * @param array<string, mixed> $container
     * @return array<int, array<string, mixed>>
     */
    private function extractMenuEntries(array $container): array
    {
        $menu = $container['menu'] ?? $container['menus'] ?? null;
        if (!\is_array($menu)) {
            return [];
        }

        if (!\array_is_list($menu)) {
            if ($this->resolveEntryPanelUrl($menu) !== '') {
                return [$menu];
            }

            $items = [];
            foreach ($menu as $entry) {
                if (\is_array($entry)) {
                    $items[] = $entry;
                }
            }

            return $items;
        }

        return \array_values(\array_filter(
            $menu,
            static fn (mixed $entry): bool => \is_array($entry)
        ));
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $plugin
     * @return array<string, mixed>|null
     */
    private function normalizePanelContribution(array $entry, array $plugin, ?string $locale, int $index): ?array
    {
        $url = $this->resolvePluginPanelUrlFromContainer($entry);
        $children = $this->normalizePanelContributionChildren($entry, $plugin, $locale, $index);
        if ($url === '' && $children === []) {
            return null;
        }

        $moduleName = (string)($plugin['module_name'] ?? '');
        $pluginName = (string)($plugin['display_name'] ?? $moduleName);
        $tagCodes = $this->collectPluginTagCodes($plugin);
        $key = $this->normalizeContributionKey((string)($entry['key'] ?? $entry['id'] ?? ''));
        if ($key === '') {
            $key = $this->normalizeContributionKey($moduleName !== '' ? $moduleName . '-' . $index : 'wls-plugin-' . $index);
        }
        if ($key === '') {
            $key = 'wls-plugin-' . $index;
        }

        $label = $this->resolveLocalizedText($entry['label'] ?? $entry['title'] ?? $entry['name'] ?? null, $locale);
        if ($label === '') {
            $label = $pluginName !== '' ? $pluginName : (string)__('Plugin Panel');
        }

        $description = $this->resolveLocalizedText($entry['description'] ?? $entry['summary'] ?? null, $locale);
        $group = $this->normalizeContributionKey((string)($entry['group'] ?? 'tools'));
        if ($group === '') {
            $group = 'tools';
        }

        return [
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'url' => $url,
            'group' => $group,
            'order' => (int)($entry['order'] ?? (500 + $index)),
            'module_name' => $moduleName,
            'plugin_name' => $pluginName,
            'tag_codes' => $tagCodes,
            'children' => $children,
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $plugin
     * @return array<int, array<string, mixed>>
     */
    private function normalizePanelContributionChildren(array $entry, array $plugin, ?string $locale, int $parentIndex): array
    {
        $children = $entry['children'] ?? $entry['items'] ?? $entry['submenu'] ?? null;
        if (!\is_array($children)) {
            return [];
        }

        if (!\array_is_list($children)) {
            $children = \array_values(\array_filter(
                $children,
                static fn (mixed $child): bool => \is_array($child)
            ));
        }

        $normalized = [];
        foreach ($children as $childIndex => $child) {
            if (!\is_array($child)) {
                continue;
            }

            $child['group'] ??= $entry['group'] ?? 'tools';
            $item = $this->normalizePanelContribution($child, $plugin, $locale, ($parentIndex * 100) + $childIndex + 1);
            if ($item !== null && (string)($item['url'] ?? '') !== '') {
                $normalized[] = $item;
            }
        }

        \usort(
            $normalized,
            static fn (array $left, array $right): int => ((int)($left['order'] ?? 0)) <=> ((int)($right['order'] ?? 0))
        );

        return $normalized;
    }

    private function resolveLocalizedText(mixed $value, ?string $locale): string
    {
        if (\is_string($value) || \is_numeric($value)) {
            return \trim((string)$value);
        }
        if (!\is_array($value)) {
            return '';
        }

        $locale = \trim((string)$locale);
        foreach ([$locale, 'zh_Hans_CN', 'en_US', 'default', 'label', 'title', 'name'] as $key) {
            if ($key !== '' && (\is_string($value[$key] ?? null) || \is_numeric($value[$key] ?? null))) {
                return \trim((string)$value[$key]);
            }
        }

        foreach ($value as $item) {
            if (\is_string($item) || \is_numeric($item)) {
                return \trim((string)$item);
            }
        }

        return '';
    }

    private function normalizeContributionKey(string $value): string
    {
        $value = \trim(\strtolower($value));
        if ($value === '') {
            return '';
        }

        $value = (string)\preg_replace('/[^a-z0-9:_-]+/', '-', $value);
        return \trim($value, '-_');
    }

    /**
     * @param array<string, mixed> $plugin
     * @return array<int, string>
     */
    private function collectPluginTagCodes(array $plugin): array
    {
        $codes = [];
        foreach (['tag_codes', 'tags', 'tags_resolved', 'custom_identity_tags', 'custom_tag_code'] as $key) {
            $this->appendTagCodes($plugin[$key] ?? null, $codes);
        }
        if (\is_array($plugin['marketplace_meta'] ?? null)) {
            $marketplaceMeta = $plugin['marketplace_meta'];
            $this->appendTagCodes($marketplaceMeta['tags'] ?? null, $codes);
            $this->appendTagCodes($marketplaceMeta['tags_resolved'] ?? null, $codes);
        }

        return \array_values(\array_unique(\array_filter($codes)));
    }

    /**
     * @param array<int, string> $codes
     */
    private function appendTagCodes(mixed $value, array &$codes): void
    {
        if (\is_string($value) || \is_numeric($value) || \is_bool($value)) {
            $code = $this->normalizeTagCode((string)$value);
            if ($code !== '') {
                $codes[] = $code;
            }
            return;
        }

        if (!\is_array($value)) {
            return;
        }

        foreach (['code', 'tag', 'value'] as $key) {
            if (\array_key_exists($key, $value)) {
                $this->appendTagCodes($value[$key], $codes);
            }
        }

        foreach ($value as $item) {
            $this->appendTagCodes($item, $codes);
        }
    }

    private function normalizeTagCode(string $code): string
    {
        $code = \trim(\strtolower($code));
        if ($code === '') {
            return '';
        }
        if (\str_starts_with($code, 'surface.')) {
            return 'surface:' . \substr($code, 8);
        }

        return $code;
    }

    /**
     * @param array<string, mixed> $plugin
     */
    private function resolvePluginPanelUrl(array $plugin): string
    {
        $url = $this->resolvePluginPanelUrlFromContainer($plugin);
        if ($url !== '') {
            return $url;
        }

        $marketplaceMeta = $plugin['marketplace_meta'] ?? null;
        if (\is_array($marketplaceMeta)) {
            $url = $this->resolvePluginPanelUrlFromContainer($marketplaceMeta);
            if ($url !== '') {
                return $url;
            }
        }

        $url = $this->resolvePluginPanelUrlFromCapabilities($plugin['capabilities'] ?? null);
        if ($url !== '') {
            return $url;
        }

        if (\is_array($marketplaceMeta ?? null)) {
            return $this->resolvePluginPanelUrlFromCapabilities($marketplaceMeta['capabilities'] ?? null);
        }

        return '';
    }

    /**
     * @param array<string, mixed> $container
     */
    private function resolvePluginPanelUrlFromContainer(array $container): string
    {
        $url = $this->resolveDirectPanelUrlFromContainer($container);
        if ($url !== '') {
            return $url;
        }

        foreach (['panel_entry', 'backend_entry', 'wls_panel'] as $key) {
            $entry = $container[$key] ?? null;
            if (!\is_array($entry)) {
                continue;
            }
            $url = $this->resolveEntryPanelUrl($entry);
            if ($url !== '') {
                return $url;
            }
        }

        return $this->resolvePanelMenuUrl($container);
    }

    /**
     * @param array<string, mixed> $container
     */
    private function resolveDirectPanelUrlFromContainer(array $container): string
    {
        foreach (['wls_panel_url', 'panel_url', 'backend_url', 'capability_url', 'url', 'href', 'route', 'backend_route'] as $key) {
            $url = $this->normalizePanelUrl($container[$key] ?? null);
            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function resolveEntryPanelUrl(array $entry): string
    {
        $url = $this->resolveDirectPanelUrlFromContainer($entry);
        if ($url !== '') {
            return $url;
        }

        foreach (['panel_entry', 'backend_entry', 'wls_panel'] as $key) {
            if (!\is_array($entry[$key] ?? null)) {
                continue;
            }

            $url = $this->resolveDirectPanelUrlFromContainer($entry[$key]);
            if ($url !== '') {
                return $url;
            }
        }

        return $this->resolvePanelMenuUrl($entry);
    }

    /**
     * @param array<string, mixed> $container
     */
    private function resolvePanelMenuUrl(array $container): string
    {
        $menu = $container['menu'] ?? $container['menus'] ?? null;
        if (!\is_array($menu)) {
            return '';
        }

        if (!\array_is_list($menu)) {
            $url = $this->resolveEntryPanelUrl($menu);
            if ($url !== '') {
                return $url;
            }

            $menu = \array_values(\array_filter(
                $menu,
                static fn (mixed $entry): bool => \is_array($entry)
            ));
        }

        foreach ($menu as $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            $url = $this->resolveEntryPanelUrl($entry);
            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }

    private function resolvePluginPanelUrlFromCapabilities(mixed $capabilities): string
    {
        if (!\is_array($capabilities)) {
            return '';
        }

        $url = $this->resolvePluginPanelUrlFromContainer($capabilities);
        if ($url !== '') {
            return $url;
        }

        foreach ($capabilities as $capability) {
            if (!\is_array($capability)) {
                continue;
            }
            $url = $this->resolvePluginPanelUrlFromContainer($capability);
            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }

    private function normalizePanelUrl(mixed $value): string
    {
        if (!\is_scalar($value)) {
            return '';
        }

        $url = \trim((string)$value);
        $lower = \strtolower(\ltrim($url));
        if ($url === ''
            || \str_starts_with($lower, 'javascript:')
            || \str_starts_with($lower, 'data:')
            || \str_starts_with($lower, 'vbscript:')
        ) {
            return '';
        }

        return $url;
    }
}
