<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\SystemConfig\Api\Scope\ScopeIdentityCatalogInterface;
use Weline\Theme\Api\DefaultThemeInterface;
use Weline\Theme\Api\Version\ThemeApplicationReferenceReaderInterface;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Model\WelineTheme;
use Weline\Websites\Api\Theme\ThemeApplicationInterface;
use Weline\Websites\Api\Theme\ThemeApplicationReference;

/**
 * 网站信息表单拥有的店面主题应用引用（websites_theme_application）。
 * 缺省展示 Theme 模块全局 Default（磁盘；不假定库 id）；不再写 scoped theme_binding。
 */
final class WebsiteThemeBindingService
{
    public const EXTENSION_KEY = 'theme';

    public function __construct(
        private readonly ThemeApplicationInterface $applications,
        private readonly ScopeHierarchyInterface $scopes,
        private readonly ScopeIdentityCatalogInterface $catalog,
        private readonly DefaultThemeInterface $defaultTheme,
        private readonly ThemeApplicationReferenceReaderInterface $references,
        private readonly ThemeScopeVersionService $versions,
        private readonly ThemeScopeVersion $versionModel,
        private readonly WelineTheme $themes,
        private readonly TransactionCoordinatorInterface $transactions,
    ) {
    }

    /**
     * @param array<string, mixed> $website
     * @return array{
     *   ok:bool,
     *   website_id:int,
     *   website_code:string,
     *   storage_scope:string,
     *   theme_id:int,
     *   theme_name:string,
     *   binding_source:string,
     *   inherited:bool,
     *   version_id:int,
     *   version_number:int,
     *   version_name:string,
     *   themes:list<array{id:int,name:string}>,
     *   versions:list<array{id:int,number:int,name:string,lifecycle:string}>
     * }
     */
    public function summarize(array $website): array
    {
        $websiteId = $this->normalizeWebsiteId($website['website_id'] ?? $website['id'] ?? null);
        $websiteCode = strtolower(trim((string)($website['code'] ?? '')));
        $empty = $this->emptySummary($websiteId, $websiteCode);
        if ($websiteId < 0 || $websiteCode === '') {
            return $empty;
        }

        try {
            $identity = $this->catalog->authoritativeIdentity(ScopeIdentity::website($websiteId, $websiteCode));
            $storageScope = $this->scopes->toStorageScope($identity);
            $keys = [];
            $cursor = $identity;
            do {
                $keys[] = $cursor->canonicalKey();
                $cursor = $this->scopes->parentIdentity($cursor);
            } while ($cursor !== null);

            $resolution = $this->applications->resolve($keys, ScopeIdentity::MODE_NORMAL, 'frontend');
            $reference = $resolution->reference;
            $inherited = false;
            $bindingSource = 'none';
            if ($reference === null) {
                $validated = $this->defaultTheme->defaultApplicationReference(
                    'frontend',
                    $storageScope,
                    ScopeIdentity::MODE_NORMAL,
                );
                $themeId = (int)$validated['theme_id'];
                $inherited = true;
                $bindingSource = 'theme_default';
                $versionId = 0;
            } else {
                $themeId = $reference->themeId;
                $inherited = !$resolution->own;
                $bindingSource = $resolution->own ? 'website' : 'inherited';
                $versionId = $reference->themeVersionId;
            }

            $themeName = $themeId > 0 ? $this->themeName($themeId) : '';
            $version = $versionId > 0
                ? (clone $this->versionModel)->clearData()->clearQuery()->load($versionId)
                : null;
            if ($version instanceof ThemeScopeVersion && (int)$version->getVersionId() !== $versionId) {
                $version = null;
            }

            return [
                'ok' => true,
                'website_id' => $websiteId,
                'website_code' => $websiteCode,
                'storage_scope' => $storageScope,
                'theme_id' => $themeId,
                'theme_name' => $themeName,
                'binding_source' => $bindingSource,
                'inherited' => $inherited,
                'version_id' => $version instanceof ThemeScopeVersion ? (int)$version->getVersionId() : 0,
                'version_number' => $version instanceof ThemeScopeVersion ? (int)$version->getVersionNumber() : 0,
                'version_name' => $version instanceof ThemeScopeVersion
                    ? trim((string)$version->getVersionName())
                    : '',
                'themes' => $this->listFrontendThemes(),
                'versions' => $themeId > 0 && $storageScope !== ''
                    ? $this->listVersions($themeId, $storageScope)
                    : [],
            ];
        } catch (\Throwable) {
            return $empty;
        }
    }

    /**
     * @param array<string, mixed> $website
     * @param array<string, mixed> $payload extensions[theme]
     */
    public function scheduleSaveFromWebsiteForm(
        int $websiteId,
        array $website,
        array $payload,
        ?ConnectionFactory $connection,
    ): void {
        if ($websiteId < 0) {
            return;
        }
        if (!array_key_exists('theme_id', $payload) && !array_key_exists('version_id', $payload)) {
            return;
        }

        $themeId = (int)($payload['theme_id'] ?? 0);
        $versionId = (int)($payload['version_id'] ?? 0);
        $websiteCode = strtolower(trim((string)($website['code'] ?? '')));
        if ($websiteCode === '') {
            return;
        }

        $runner = function () use ($websiteId, $websiteCode, $themeId, $versionId): void {
            if ($themeId > 0) {
                $this->bindThemeForWebsite($websiteId, $websiteCode, $themeId, $versionId);
            } elseif ($versionId > 0) {
                $this->bindThemeForWebsite($websiteId, $websiteCode, 0, $versionId);
            }
        };

        if ($connection instanceof ConnectionFactory) {
            $this->transactions->afterCommit(
                $connection,
                'theme.website_application.' . $websiteId,
                $runner,
            );

            return;
        }

        $runner();
    }

    public function bindThemeForWebsite(
        int $websiteId,
        string $websiteCode,
        int $themeId,
        int $versionId = 0,
    ): void {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException((string)__('网站主题绑定参数无效'));
        }
        $websiteCode = strtolower(trim($websiteCode));
        if ($websiteCode === '') {
            throw new \InvalidArgumentException((string)__('网站代码无效'));
        }
        $identity = $this->catalog->authoritativeIdentity(ScopeIdentity::website($websiteId, $websiteCode));
        $storageScope = $this->scopes->toStorageScope($identity);
        $scopeKey = $identity->canonicalKey();
        $current = $this->applications->getOwn($scopeKey, ScopeIdentity::MODE_NORMAL, 'frontend');

        if ($themeId <= 0) {
            $themeId = $current['reference']?->themeId ?? 0;
        }
        if ($themeId <= 0 || $this->defaultTheme->isModuleDefaultThemeId($themeId)) {
            // 写回 Theme 模块包默认引用（theme_id 可能为目录 id 或 0），不假定 id=1。
            $fallback = $this->defaultTheme->defaultApplicationReference(
                'frontend',
                $storageScope,
                ScopeIdentity::MODE_NORMAL,
            );
            $themeId = (int)$fallback['theme_id'];
        } else {
            $this->assertFrontendTheme($themeId);
        }

        if ($versionId > 0) {
            $version = clone $this->versionModel;
            $version->clearData()->clearQuery()->load($versionId);
            if ((int)$version->getVersionId() !== $versionId
                || (int)$version->getThemeId() !== $themeId
                || trim((string)$version->getScope()) !== $storageScope
                || strtolower(trim((string)$version->getArea())) !== 'frontend'
            ) {
                throw new \InvalidArgumentException((string)__('所选主题版本不属于当前网站范围'));
            }
            $this->versions->markPublished($version);
            $ownerScope = trim((string)$version->getScope());
            $contentRevision = (int)$version->getContentRevision();
            $themeVersionId = $versionId;
        } else {
            $published = $this->versions->getPublished($themeId, $storageScope, ScopeIdentity::MODE_NORMAL, 'frontend');
            if ($published instanceof ThemeScopeVersion && (int)$published->getVersionId() > 0) {
                $themeVersionId = (int)$published->getVersionId();
                $contentRevision = (int)$published->getContentRevision();
                $ownerScope = trim((string)$published->getScope());
            } else {
                $themeVersionId = 0;
                $contentRevision = 0;
                $ownerScope = $storageScope;
            }
        }

        $validated = $this->references->validateReference([
            'theme_id' => $themeId,
            'theme_version_id' => $themeVersionId,
            'content_revision' => $contentRevision,
            'owner_scope' => $ownerScope !== '' ? $ownerScope : $storageScope,
            'store_mode' => ScopeIdentity::MODE_NORMAL,
            'area' => 'frontend',
        ]);
        $reference = new ThemeApplicationReference(
            themeId: (int)$validated['theme_id'],
            themeVersionId: (int)$validated['theme_version_id'],
            contentRevision: (int)$validated['content_revision'],
            versionOwnerScope: (string)$validated['owner_scope'],
            versionOwnerStoreMode: (string)$validated['store_mode'],
            area: (string)$validated['area'],
        );
        $this->applications->save(
            $scopeKey,
            ScopeIdentity::MODE_NORMAL,
            'frontend',
            $reference,
            $current['revision'],
        );
    }

    /** @deprecated 版本选择已并入 bindThemeForWebsite */
    public function selectPublishedVersionForWebsite(
        int $websiteId,
        string $websiteCode,
        int $themeId,
        int $versionId,
    ): void {
        $this->bindThemeForWebsite($websiteId, $websiteCode, $themeId, $versionId);
    }

    /**
     * @return list<array{id:int,name:string}>
     */
    public function listFrontendThemes(): array
    {
        try {
            $rows = $this->themes->reset()
                ->order(WelineTheme::schema_fields_ID, 'ASC')
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return [];
        }
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int)($row[WelineTheme::schema_fields_ID] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $theme = clone $this->themes;
            $theme->clearData()->clearQuery()->setData($row);
            if (!$this->themeSupportsFrontend($theme)) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'name' => trim((string)($row[WelineTheme::schema_fields_NAME] ?? ('#' . $id))),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{id:int,number:int,name:string,lifecycle:string}>
     */
    public function listVersions(int $themeId, string $storageScope): array
    {
        if ($themeId <= 0 || trim($storageScope) === '') {
            return [];
        }
        try {
            $rows = $this->versionModel->reset()
                ->where(ThemeScopeVersion::schema_fields_THEME_ID, $themeId)
                ->where(ThemeScopeVersion::schema_fields_SCOPE, $storageScope)
                ->where(ThemeScopeVersion::schema_fields_AREA, 'frontend')
                ->order(ThemeScopeVersion::schema_fields_VERSION_NUMBER, 'DESC')
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return [];
        }
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int)($row[ThemeScopeVersion::schema_fields_ID] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'number' => (int)($row[ThemeScopeVersion::schema_fields_VERSION_NUMBER] ?? 0),
                'name' => trim((string)($row[ThemeScopeVersion::schema_fields_VERSION_NAME] ?? '')),
                'lifecycle' => trim((string)($row[ThemeScopeVersion::schema_fields_LIFECYCLE] ?? '')),
            ];
        }

        return $out;
    }

    public function isDefaultTheme(int $themeId): bool
    {
        try {
            return $this->defaultTheme->isModuleDefaultThemeId($themeId);
        } catch (\Throwable) {
            return $themeId === \Weline\Theme\Api\DefaultThemeInterface::MODULE_DEFAULT_THEME_ID;
        }
    }

    /**
     * @return array{
     *   ok:bool,
     *   website_id:int,
     *   website_code:string,
     *   storage_scope:string,
     *   theme_id:int,
     *   theme_name:string,
     *   binding_source:string,
     *   inherited:bool,
     *   version_id:int,
     *   version_number:int,
     *   version_name:string,
     *   themes:list<array{id:int,name:string}>,
     *   versions:list<array{id:int,number:int,name:string,lifecycle:string}>
     * }
     */
    private function emptySummary(int $websiteId, string $websiteCode): array
    {
        return [
            'ok' => false,
            'website_id' => $websiteId,
            'website_code' => $websiteCode,
            'storage_scope' => '',
            'theme_id' => 0,
            'theme_name' => '',
            'binding_source' => 'none',
            'inherited' => false,
            'version_id' => 0,
            'version_number' => 0,
            'version_name' => '',
            'themes' => $this->listFrontendThemes(),
            'versions' => [],
        ];
    }

    private function themeName(int $themeId): string
    {
        try {
            $theme = clone $this->themes;
            $theme->clearData()->clearQuery()->load($themeId);
            if ((int)$theme->getId() === $themeId) {
                return trim((string)$theme->getName());
            }
        } catch (\Throwable) {
        }

        return '';
    }

    private function assertFrontendTheme(int $themeId): void
    {
        $theme = clone $this->themes;
        $theme->clearData()->clearQuery()->load($themeId);
        if ((int)$theme->getId() !== $themeId || !$this->themeSupportsFrontend($theme)) {
            throw new \InvalidArgumentException((string)__('主题不可用于前台'));
        }
    }

    private function themeSupportsFrontend(WelineTheme $theme): bool
    {
        $basePath = rtrim($theme->getPath(), '/\\');
        if ($basePath === '') {
            return false;
        }
        $separator = \DIRECTORY_SEPARATOR;

        return is_dir($basePath . $separator . 'frontend')
            || is_dir($basePath . $separator . 'view' . $separator . 'theme' . $separator . 'frontend')
            || is_dir($basePath . $separator . 'theme' . $separator . 'frontend');
    }

    private function normalizeWebsiteId(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/D', $value) === 1) {
            return (int)$value;
        }

        return -1;
    }
}
