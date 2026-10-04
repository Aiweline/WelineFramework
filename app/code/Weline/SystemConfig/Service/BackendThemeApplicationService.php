<?php
declare(strict_types=1);
namespace Weline\SystemConfig\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Runtime\ThemeApplicationContext;
use Weline\SystemConfig\Api\ConfigStore;
use Weline\Theme\Api\DefaultThemeInterface;
use Weline\Theme\Api\Version\ThemeApplicationReferenceReaderInterface;

/** 后台外观由系统配置拥有；请求只读取应用引用，缺省回落 Theme 模块全局 Default。 */
final class BackendThemeApplicationService
{
    public const CONFIG_KEY = 'backend_theme_application';
    public const MODULE = 'Weline_SystemConfig';
    public const PROVIDER = 'system-config';
    public const SCOPE_KEY = 'backendglobal';

    public function __construct(
        private readonly ConfigStore $config,
        private readonly ThemeApplicationReferenceReaderInterface $references,
        private readonly DefaultThemeInterface $defaultTheme,
    ) {}

    public function buildContext(): ThemeApplicationContext
    {
        $stored = $this->readStored();
        if (!is_array($stored)) {
            // 未配置后台应用引用时回落 Theme 模块全局 Default（不写库、不抛 not_configured）。
            $stored = $this->defaultTheme->defaultApplicationReference(
                'backend',
                ConfigStore::SCOPE_GLOBAL,
                'normal',
            ) + ['default_locale' => Env::default_LANGUAGE_CODE];
        }
        if (($stored['area'] ?? '') !== 'backend') { throw new \InvalidArgumentException('backend_theme_application_area_invalid'); }
        $ref = $this->references->validateReference($stored);
        $locale = (string)($stored['default_locale'] ?? Env::default_LANGUAGE_CODE);
        return new ThemeApplicationContext(
            provider: self::PROVIDER, scopeKey: self::SCOPE_KEY, storeMode: $ref['store_mode'], area: 'backend',
            themeId: $ref['theme_id'], versionOwnerScope: $ref['owner_scope'], versionOwnerStoreMode: $ref['store_mode'],
            themeVersionId: $ref['theme_version_id'], contentRevision: $ref['content_revision'], defaultLocale: $locale,
            contentScopes: [['provider' => self::PROVIDER, 'scope_key' => self::SCOPE_KEY, 'store_mode' => $ref['store_mode'],
                'default_locale' => $locale, 'resource_references' => $this->references->resourceReferences($ref)]],
            purpose: 'runtime', invalidationNamespaces: ['global/backend/theme'],
        );
    }

    /** 一次升级迁移；已存在的系统配置永不由旧数据覆盖。 */
    public function migrateLegacy(string $defaultLocale = Env::default_LANGUAGE_CODE): bool
    {
        if ($this->readStored() !== null) { return false; }
        $snapshot = $this->references->exportLegacyApplicationSnapshot();
        foreach (($snapshot['unresolved'] ?? []) as $item) {
            if (($item['area'] ?? '') === 'backend' && (!isset($item['scope']) || $item['scope'] === ConfigStore::SCOPE_GLOBAL)) {
                throw new \RuntimeException((string)($item['reason'] ?? 'backend_theme_legacy_unresolved'));
            }
        }
        $bindings = array_values(array_filter($snapshot['bindings'] ?? [], static fn(array $row): bool =>
            ($row['area'] ?? '') === 'backend' && ($row['scope'] ?? '') === ConfigStore::SCOPE_GLOBAL
            && ($row['store_mode'] ?? '') === 'normal'));
        if (count($bindings) > 1) { throw new \RuntimeException('backend_theme_legacy_binding_ambiguous'); }
        if ($bindings !== []) {
            $binding = $bindings[0];
            $ref = ['theme_id' => $binding['theme_id'], 'theme_version_id' => $binding['theme_version_id'],
                'content_revision' => $binding['content_revision'], 'owner_scope' => $binding['owner_scope'],
                'store_mode' => $binding['owner_store_mode'], 'area' => 'backend'];
        } else {
            $themeId = (int)($snapshot['active_defaults']['backend']['theme_id'] ?? 0);
            if ($themeId < 1) {
                $themeId = (int)($this->defaultTheme->getRegisteredDefault('backend')['id'] ?? 0);
            }
            if ($themeId < 1) { throw new \RuntimeException('backend_theme_legacy_default_missing'); }
            $owner = ConfigStore::SCOPE_GLOBAL;
            $versions = array_values(array_filter($snapshot['source_records']['versions'] ?? [], static fn(array $row): bool =>
                (int)($row['theme_id'] ?? 0) == $themeId && ($row['scope'] ?? '') === $owner
                && ($row['store_mode'] ?? '') === 'normal' && ($row['area'] ?? '') === 'backend'));
            $selections = array_values(array_filter($snapshot['source_records']['selections'] ?? [], static fn(array $row): bool =>
                (int)($row['theme_id'] ?? 0) == $themeId && ($row['scope'] ?? '') === $owner
                && ($row['store_mode'] ?? '') === 'normal' && ($row['area'] ?? '') === 'backend'
                && (int)($row['published_version_id'] ?? 0) > 0));
            if (count($selections) > 1) { throw new \RuntimeException('backend_theme_legacy_selection_ambiguous'); }
            $selected = null;
            if ($selections !== []) {
                foreach ($versions as $version) {
                    if ((int)$version['version_id'] === (int)$selections[0]['published_version_id']) { $selected = $version; break; }
                }
                if ($selected === null) { throw new \RuntimeException('backend_theme_legacy_selected_version_missing'); }
            } elseif ($versions !== []) { throw new \RuntimeException('backend_theme_legacy_version_selection_missing'); }
            $ref = ['theme_id' => $themeId, 'theme_version_id' => (int)($selected['version_id'] ?? 0),
                'content_revision' => (int)($selected['content_revision'] ?? 0), 'owner_scope' => $owner,
                'store_mode' => 'normal', 'area' => 'backend'];
        }
        $ref = $this->references->validateReference($ref) + ['default_locale' => $defaultLocale];
        if (!$this->config->setScopedConfig(self::CONFIG_KEY, $ref, self::MODULE, 'backend', ConfigStore::SCOPE_GLOBAL, ConfigStore::LOCALE_DEFAULT)) {
            throw new \RuntimeException('backend_theme_application_migration_save_failed');
        }
        return true;
    }

    private function readStored(): mixed
    {
        return $this->config->getConfig(self::CONFIG_KEY, self::MODULE, 'backend', null, ConfigStore::SCOPE_GLOBAL, ConfigStore::LOCALE_DEFAULT);
    }
}
