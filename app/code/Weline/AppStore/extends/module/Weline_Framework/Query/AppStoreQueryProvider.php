<?php
declare(strict_types=1);

namespace Weline\AppStore\Extends\Module\Weline_Framework\Query;

use Weline\AppStore\Model\AppStoreInstalledModule;
use Weline\AppStore\Service\InstalledModuleMetaService;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\MarketplaceMeta\MarketplaceMetaReader;
use Weline\Framework\MarketplaceMeta\MarketplaceTag;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

class AppStoreQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly InstalledModuleMetaService $metaService
    ) {
    }

    public function getProviderName(): string
    {
        return 'appstore';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'installedModules' => $this->installedModules($params),
            default => throw new \InvalidArgumentException(
                (string)__('AppStore query provider unsupported operation: %{1}', [$operation])
            ),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'appstore',
            'name' => __('AppStore Queries'),
            'description' => __('Provides installed module marketplace metadata for other modules.'),
            'module' => 'Weline_AppStore',
            'operations' => [
                [
                    'name' => 'installedModules',
                    'description' => __('List installed modules with marketplace meta tags and surfaces.'),
                    'params' => [
                        ['name' => 'tag', 'type' => 'string|null', 'required' => false, 'description' => __('Typed tag filter, for example module:wls')],
                        ['name' => 'surface', 'type' => 'string|null', 'required' => false, 'description' => __('Surface filter, for example backend or surface:backend')],
                        ['name' => 'module_name', 'type' => 'string|null', 'required' => false, 'description' => __('Optional module name filter')],
                        ['name' => 'locale', 'type' => 'string|null', 'required' => false, 'description' => __('Locale used for localized marketplace meta')],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{items: array<int, array<string, mixed>>, count: int, filters: array<string, string>}
     */
    private function installedModules(array $params): array
    {
        $tagFilter = MarketplaceTag::normalizeCode((string)($params['tag'] ?? ''));
        $surfaceFilter = $this->normalizeSurface((string)($params['surface'] ?? ''));
        $moduleFilter = trim((string)($params['module_name'] ?? $params['module'] ?? ''));
        $locale = trim((string)($params['locale'] ?? ''));
        if ($locale === '') {
            $locale = (string)w_env('user.lang', Env::get('user.lang', 'zh_Hans_CN'));
        }

        /** @var AppStoreInstalledModule $moduleModel */
        $moduleModel = ObjectManager::getInstance(AppStoreInstalledModule::class);
        $moduleModel->reset()
            ->order(AppStoreInstalledModule::schema_fields_installed_at, 'DESC')
            ->select()
            ->fetch();

        $items = [];
        $seenModuleNames = [];
        foreach (($moduleModel->getItems() ?? []) as $module) {
            $data = is_object($module) && method_exists($module, 'getData') ? $module->getData() : (array)$module;
            $moduleName = (string)($data[AppStoreInstalledModule::schema_fields_module_name] ?? $data['module_name'] ?? '');
            if ($moduleName === '') {
                continue;
            }
            $seenModuleNames[strtolower($moduleName)] = true;
            if ($moduleFilter !== '' && strcasecmp($moduleName, $moduleFilter) !== 0) {
                continue;
            }

            $item = $this->normalizeInstalledModule($data, $locale);
            if (!$this->matchesFilters($item, $tagFilter, $surfaceFilter)) {
                continue;
            }

            $items[] = $item;
        }
        $this->appendLocalMarketplaceMetaItems($items, $seenModuleNames, $tagFilter, $surfaceFilter, $moduleFilter, $locale);

        return [
            'items' => $items,
            'count' => count($items),
            'filters' => [
                'tag' => $tagFilter,
                'surface' => $surfaceFilter,
                'module_name' => $moduleFilter,
                'locale' => $locale,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeInstalledModule(array $data, string $locale): array
    {
        $moduleName = (string)($data[AppStoreInstalledModule::schema_fields_module_name] ?? $data['module_name'] ?? '');
        $marketplaceMeta = $this->marketplaceMetaFromData($data);
        if ($marketplaceMeta === []) {
            $marketplaceMeta = $this->loadLocalMarketplaceMeta($moduleName);
        }
        $info = [];
        try {
            $info = $this->metaService->getLocalizedInfo($moduleName, $locale);
        } catch (\Throwable) {
            $info = [];
        }

        $tags = is_array($info['tags'] ?? null) ? $info['tags'] : [];
        $tagCodes = $this->tagCodes($tags);
        $primaryTagCode = MarketplaceTag::normalizeCode(
            (string)($data[AppStoreInstalledModule::schema_fields_primary_tag_code] ?? $data['primary_tag_code'] ?? '')
        );
        if ($primaryTagCode !== '' && !in_array($primaryTagCode, $tagCodes, true)) {
            $tagCodes[] = $primaryTagCode;
        }

        $surfaceCodes = is_array($info['surfaces'] ?? null)
            ? $this->surfaceCodes($info['surfaces'])
            : [];
        if ($surfaceCodes === []) {
            $surfaceCodes = $this->surfaceCodes(
                $data[AppStoreInstalledModule::schema_fields_surface_codes] ?? $data['surface_codes'] ?? []
            );
        }

        return [
            'install_id' => (int)($data[AppStoreInstalledModule::schema_fields_ID] ?? $data['install_id'] ?? 0),
            'module_name' => $moduleName,
            'display_name' => (string)($info['display_name']
                ?? $data[AppStoreInstalledModule::schema_fields_display_name]
                ?? $data['display_name']
                ?? $moduleName),
            'description' => (string)($info['description']
                ?? $data[AppStoreInstalledModule::schema_fields_description]
                ?? $data['description']
                ?? ''),
            'version' => (string)($data[AppStoreInstalledModule::schema_fields_version] ?? $data['version'] ?? ''),
            'license_status' => (string)($data[AppStoreInstalledModule::schema_fields_license_status] ?? $data['license_status'] ?? ''),
            'platform_module_id' => (int)($data[AppStoreInstalledModule::schema_fields_platform_module_id] ?? $data['platform_module_id'] ?? 0),
            'installed_at' => (string)($data[AppStoreInstalledModule::schema_fields_installed_at] ?? $data['installed_at'] ?? ''),
            'updated_at' => (string)($data[AppStoreInstalledModule::schema_fields_updated_at] ?? $data['updated_at'] ?? ''),
            'marketplace_meta_hash' => (string)($data[AppStoreInstalledModule::schema_fields_marketplace_meta_hash] ?? $data['marketplace_meta_hash'] ?? ''),
            'primary_tag_code' => $primaryTagCode,
            'custom_tag_code' => $this->firstTagCodeByType($tagCodes, 'custom'),
            'tags' => $tags,
            'tag_codes' => array_values(array_unique($tagCodes)),
            'surface_codes' => $surfaceCodes,
            'marketplace_meta' => $marketplaceMeta,
            'capabilities' => $this->capabilitiesFromMeta($marketplaceMeta),
            ...$this->panelFieldsFromMeta($marketplaceMeta),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, bool> $seenModuleNames
     */
    private function appendLocalMarketplaceMetaItems(
        array &$items,
        array &$seenModuleNames,
        string $tagFilter,
        string $surfaceFilter,
        string $moduleFilter,
        string $locale
    ): void {
        foreach ((array)Env::getInstance()->getModuleList() as $moduleName => $moduleData) {
            $moduleName = trim((string)$moduleName);
            if ($moduleName === '' || isset($seenModuleNames[strtolower($moduleName)])) {
                continue;
            }
            if ($moduleFilter !== '' && strcasecmp($moduleName, $moduleFilter) !== 0) {
                continue;
            }

            $data = $this->moduleData($moduleData);
            if (!$this->isModuleEnabled($data)) {
                continue;
            }

            $moduleDir = $this->moduleDirectory($moduleName, $data);
            if ($moduleDir === '') {
                continue;
            }

            $metaResult = $this->marketplaceMetaReader()->readFromModuleDir($moduleDir, $moduleName);
            $marketplaceMeta = is_array($metaResult['meta'] ?? null) ? $metaResult['meta'] : [];
            if ($marketplaceMeta === []) {
                continue;
            }

            $item = $this->normalizeLocalMarketplaceModule($moduleName, $data, $marketplaceMeta, (string)($metaResult['hash'] ?? ''), $locale);
            if (!$this->matchesFilters($item, $tagFilter, $surfaceFilter)) {
                continue;
            }

            $items[] = $item;
            $seenModuleNames[strtolower($moduleName)] = true;
        }
    }

    /**
     * @param array<string, mixed> $moduleData
     * @param array<string, mixed> $marketplaceMeta
     * @return array<string, mixed>
     */
    private function normalizeLocalMarketplaceModule(
        string $moduleName,
        array $moduleData,
        array $marketplaceMeta,
        string $hash,
        string $locale
    ): array {
        $info = $this->localizedInfoFromMeta($marketplaceMeta, $locale);
        try {
            $tags = $this->metaService->resolveTags($marketplaceMeta, $locale);
        } catch (\Throwable) {
            $tags = is_array($marketplaceMeta['tags'] ?? null) ? $marketplaceMeta['tags'] : [];
        }

        $tagCodes = $this->tagCodes(is_array($tags) ? $tags : []);
        if ($tagCodes === [] && is_array($marketplaceMeta['tags'] ?? null)) {
            $tagCodes = $this->tagCodes($marketplaceMeta['tags']);
        }
        $primaryTagCode = $this->primaryTagCodeFromMeta($marketplaceMeta);
        if ($primaryTagCode !== '' && !in_array($primaryTagCode, $tagCodes, true)) {
            $tagCodes[] = $primaryTagCode;
        }

        $surfaceCodes = $this->surfaceCodes($marketplaceMeta['surfaces'] ?? []);

        return [
            'install_id' => 0,
            'module_name' => $moduleName,
            'display_name' => (string)($info['display_name'] ?? $moduleData['description'] ?? $moduleName),
            'description' => (string)($info['description'] ?? $moduleData['description'] ?? ''),
            'version' => (string)($moduleData['version'] ?? '0.0.0'),
            'license_status' => 'local',
            'platform_module_id' => 0,
            'installed_at' => $this->localModuleInstalledAt($moduleName, $moduleData),
            'updated_at' => '',
            'marketplace_meta_hash' => $hash,
            'primary_tag_code' => $primaryTagCode,
            'custom_tag_code' => $this->firstTagCodeByType($tagCodes, 'custom'),
            'tags' => $tags,
            'tag_codes' => array_values(array_unique($tagCodes)),
            'surface_codes' => $surfaceCodes,
            'marketplace_meta' => $marketplaceMeta,
            'capabilities' => $this->capabilitiesFromMeta($marketplaceMeta),
            ...$this->panelFieldsFromMeta($marketplaceMeta),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function marketplaceMetaFromData(array $data): array
    {
        $value = $data[AppStoreInstalledModule::schema_fields_marketplace_meta_json] ?? $data['marketplace_meta_json'] ?? [];
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $marketplaceMeta
     * @return array<string, mixed>
     */
    private function capabilitiesFromMeta(array $marketplaceMeta): array
    {
        $capabilities = $marketplaceMeta['capabilities'] ?? [];
        return is_array($capabilities) ? $capabilities : [];
    }

    /**
     * @param array<string, mixed> $marketplaceMeta
     * @return array<string, mixed>
     */
    private function panelFieldsFromMeta(array $marketplaceMeta): array
    {
        $fields = [];
        foreach (['wls_panel_url', 'panel_url', 'backend_url', 'capability_url', 'url'] as $key) {
            $fields[$key] = $this->metaString($marketplaceMeta[$key] ?? null);
        }
        foreach (['panel_entry', 'backend_entry', 'wls_panel'] as $key) {
            $entry = $marketplaceMeta[$key] ?? [];
            $fields[$key] = is_array($entry) ? $entry : [];
        }

        return $fields;
    }

    private function metaString(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }

    /**
     * @param array<int, array<string, mixed>> $tags
     * @return string[]
     */
    private function tagCodes(array $tags): array
    {
        $codes = [];
        foreach ($tags as $tag) {
            if (!is_array($tag)) {
                continue;
            }
            $code = MarketplaceTag::normalizeCode((string)($tag['code'] ?? ''));
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * @param mixed $value
     * @return string[]
     */
    private function surfaceCodes(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            } else {
                $value = [$value];
            }
        }

        $codes = [];
        foreach ((array)$value as $surface) {
            $surface = $this->normalizeSurface((string)$surface);
            if ($surface !== '') {
                $codes[] = $surface;
            }
        }

        return array_values(array_unique($codes));
    }

    private function normalizeSurface(string $surface): string
    {
        $surface = strtolower(trim($surface));
        if ($surface === '') {
            return '';
        }

        $surfaceFromTag = MarketplaceTag::surfaceFromCode($surface);
        return $surfaceFromTag !== '' ? $surfaceFromTag : $surface;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function matchesFilters(array $item, string $tagFilter, string $surfaceFilter): bool
    {
        if ($tagFilter !== '' && !in_array($tagFilter, (array)($item['tag_codes'] ?? []), true)) {
            return false;
        }

        if ($surfaceFilter !== '' && !in_array($surfaceFilter, (array)($item['surface_codes'] ?? []), true)) {
            return false;
        }

        return true;
    }

    /**
     * @param mixed $moduleData
     * @return array<string, mixed>
     */
    private function moduleData(mixed $moduleData): array
    {
        if (is_object($moduleData) && method_exists($moduleData, 'getData')) {
            $data = $moduleData->getData();
            return is_array($data) ? $data : [];
        }

        return is_array($moduleData) ? $moduleData : [];
    }

    /**
     * @param array<string, mixed> $moduleData
     */
    private function isModuleEnabled(array $moduleData): bool
    {
        $status = $moduleData['status'] ?? true;
        if (is_bool($status)) {
            return $status;
        }
        if (is_int($status)) {
            return $status === 1;
        }

        $status = strtolower(trim((string)$status));
        return !in_array($status, ['0', 'false', 'disabled', 'off', 'no'], true);
    }

    /**
     * @param array<string, mixed> $moduleData
     */
    private function moduleDirectory(string $moduleName, array $moduleData): string
    {
        foreach (['base_path', 'path', 'dir_path'] as $key) {
            $candidate = trim((string)($moduleData[$key] ?? ''));
            if ($candidate !== '' && is_dir($candidate)) {
                return rtrim($candidate, DS);
            }
        }

        if (!str_contains($moduleName, '_')) {
            return '';
        }
        [$vendor, $module] = explode('_', $moduleName, 2);
        $candidate = rtrim(APP_CODE_PATH, DS) . DS . $vendor . DS . $module;

        return is_dir($candidate) ? $candidate : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function loadLocalMarketplaceMeta(string $moduleName): array
    {
        $moduleData = $this->moduleData(Env::getInstance()->getModuleList()[$moduleName] ?? []);
        $moduleDir = $this->moduleDirectory($moduleName, $moduleData);
        if ($moduleDir === '') {
            return [];
        }

        $result = $this->marketplaceMetaReader()->readFromModuleDir($moduleDir, $moduleName);
        return is_array($result['meta'] ?? null) ? $result['meta'] : [];
    }

    /**
     * @param array<string, mixed> $marketplaceMeta
     * @return array<string, mixed>
     */
    private function localizedInfoFromMeta(array $marketplaceMeta, string $locale): array
    {
        $i18n = is_array($marketplaceMeta['i18n'] ?? null) ? $marketplaceMeta['i18n'] : [];
        $sourceLocale = (string)($i18n['source_locale'] ?? 'zh_Hans_CN');
        $locales = is_array($i18n['locales'] ?? null) ? $i18n['locales'] : [];
        $source = is_array($locales[$sourceLocale] ?? null) ? $locales[$sourceLocale] : [];
        $localized = is_array($locales[$locale] ?? null) ? $locales[$locale] : [];

        return array_replace($source, $localized);
    }

    /**
     * @param array<string, mixed> $marketplaceMeta
     */
    private function primaryTagCodeFromMeta(array $marketplaceMeta): string
    {
        $fallback = '';
        foreach ((array)($marketplaceMeta['tags'] ?? []) as $tag) {
            if (!is_array($tag)) {
                continue;
            }
            $code = MarketplaceTag::normalizeCode((string)($tag['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            if ($fallback === '') {
                $fallback = $code;
            }
            if (!empty($tag['primary'])) {
                return $code;
            }
        }

        return $fallback;
    }

    /**
     * @param array<string, mixed> $moduleData
     */
    private function localModuleInstalledAt(string $moduleName, array $moduleData): string
    {
        $moduleDir = $this->moduleDirectory($moduleName, $moduleData);
        $registerFile = $moduleDir !== '' ? $moduleDir . DS . 'register.php' : '';
        $timestamp = $registerFile !== '' && is_file($registerFile) ? (int)@filemtime($registerFile) : 0;

        return $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : '';
    }

    private function marketplaceMetaReader(): MarketplaceMetaReader
    {
        /** @var MarketplaceMetaReader $reader */
        $reader = ObjectManager::getInstance(MarketplaceMetaReader::class);
        return $reader;
    }

    /**
     * @param string[] $codes
     */
    private function firstTagCodeByType(array $codes, string $type): string
    {
        foreach ($codes as $code) {
            $parsed = MarketplaceTag::parse($code);
            if ($parsed['type'] === $type) {
                return $code;
            }
        }

        return '';
    }
}
