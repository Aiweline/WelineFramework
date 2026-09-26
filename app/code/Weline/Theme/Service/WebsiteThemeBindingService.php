<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Model\WelineTheme;

/**
 * Website-scoped storefront theme_binding + published version summary.
 *
 * Website info form owns per-site theme selection. Global is_active_frontend /
 * Global theme_binding remain Default-only fallback and must not stomp sites.
 */
final class WebsiteThemeBindingService
{
    public const EXTENSION_KEY = 'theme';

    public function __construct(
        private readonly ThemeScopedWorkspaceInterface $workspace,
        private readonly ScopeHierarchyInterface $scopes,
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
            $identity = ScopeIdentity::website($websiteId, $websiteCode);
            $scope = $this->scopes->contextFromIdentity($identity);
            $storageScope = trim((string)$scope->storageScope);
            $context = new ThemeEditorContext(
                scope: $scope,
                area: 'frontend',
                resourceType: ThemeEditorContext::RESOURCE_THEME_BINDING,
            );
            $state = $this->workspace->load($context, true);
            $ownThemeId = (int)($state['published_payload']['theme_id'] ?? 0);
            $effectiveThemeId = (int)($state['effective_payload']['theme_id']
                ?? $state['published_payload']['theme_id']
                ?? 0);
            $inherited = $ownThemeId <= 0 && $effectiveThemeId > 0;
            $themeId = $ownThemeId > 0 ? $ownThemeId : $effectiveThemeId;
            $themeName = $themeId > 0 ? $this->themeName($themeId) : '';

            $version = $themeId > 0 && $storageScope !== ''
                ? $this->versions->getPublished($themeId, $storageScope, 'normal', 'frontend')
                : null;

            return [
                'ok' => true,
                'website_id' => $websiteId,
                'website_code' => $websiteCode,
                'storage_scope' => $storageScope,
                'theme_id' => $themeId,
                'theme_name' => $themeName,
                'binding_source' => $inherited ? 'inherited' : ($ownThemeId > 0 ? 'website' : 'none'),
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
     * Persist website-scoped theme binding (and optional published version)
     * after the Website save transaction commits.
     *
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
                $this->bindThemeForWebsite($websiteId, $websiteCode, $themeId);
            }
            if ($themeId > 0 && $versionId > 0) {
                $this->selectPublishedVersionForWebsite($websiteId, $websiteCode, $themeId, $versionId);
            }
        };

        if ($connection instanceof ConnectionFactory) {
            $this->transactions->afterCommit(
                $connection,
                'theme.website_binding.' . $websiteId,
                $runner,
            );

            return;
        }

        $runner();
    }

    public function bindThemeForWebsite(int $websiteId, string $websiteCode, int $themeId): void
    {
        if ($websiteId < 0 || $themeId <= 0) {
            throw new \InvalidArgumentException((string)__('网站主题绑定参数无效'));
        }
        $websiteCode = strtolower(trim($websiteCode));
        if ($websiteCode === '') {
            throw new \InvalidArgumentException((string)__('网站代码无效'));
        }
        $this->assertFrontendTheme($themeId);

        $identity = ScopeIdentity::website($websiteId, $websiteCode);
        $scope = $this->scopes->contextFromIdentity($identity);
        $context = new ThemeEditorContext(
            scope: $scope,
            area: 'frontend',
            resourceType: ThemeEditorContext::RESOURCE_THEME_BINDING,
        );
        $before = $this->workspace->load($context, true);
        $publishedThemeId = (int)($before['published_payload']['theme_id'] ?? 0);
        $draftThemeId = (int)($before['draft_payload']['theme_id'] ?? 0);
        if ($publishedThemeId === $themeId && ($draftThemeId === 0 || $draftThemeId === $themeId)) {
            return;
        }

        $parentReleaseId = array_key_exists('expected_parent_release_id', $before)
            ? ($before['expected_parent_release_id'] === null
                ? null
                : (int)$before['expected_parent_release_id'])
            : null;
        $this->workspace->applyChanges(
            context: $context,
            expectedRevision: (int)($before['revision'] ?? 0),
            expectedParentReleaseId: $parentReleaseId,
            changes: [[
                'op' => 'set',
                'path' => '/theme_id',
                'value' => $themeId,
            ]],
            actorId: 'website-theme-binding',
            actorName: 'WebsiteThemeBindingService',
            summary: 'Website form theme_binding',
        );
        $afterDraft = $this->workspace->load($context, true);
        $this->workspace->publish(
            context: $context,
            expectedRevision: (int)($afterDraft['revision'] ?? 0),
            expectedParentReleaseId: array_key_exists('expected_parent_release_id', $afterDraft)
                ? ($afterDraft['expected_parent_release_id'] === null
                    ? null
                    : (int)$afterDraft['expected_parent_release_id'])
                : null,
            actorId: 'website-theme-binding',
            actorName: 'WebsiteThemeBindingService',
            reason: 'Website form theme_binding',
        );
        $this->workspace->invalidateRequestLoadCache();
    }

    public function selectPublishedVersionForWebsite(
        int $websiteId,
        string $websiteCode,
        int $themeId,
        int $versionId,
    ): void {
        if ($websiteId < 0 || $themeId <= 0 || $versionId <= 0) {
            return;
        }
        $websiteCode = strtolower(trim($websiteCode));
        if ($websiteCode === '') {
            return;
        }
        $identity = ScopeIdentity::website($websiteId, $websiteCode);
        $scope = $this->scopes->contextFromIdentity($identity);
        $storageScope = trim((string)$scope->storageScope);
        if ($storageScope === '') {
            return;
        }

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
        if ($themeId <= 0) {
            return false;
        }
        try {
            $theme = clone $this->themes;
            $theme->clearData()->clearQuery()->load($themeId);
            if ((int)$theme->getId() !== $themeId) {
                return false;
            }
            $name = strtolower(trim((string)$theme->getName()));
            if ($name === 'default' || str_starts_with($name, 'default ')) {
                return true;
            }
            $path = str_replace('\\', '/', (string)$theme->getPath());

            return str_contains($path, '/Weline/Theme/view/theme')
                || str_ends_with(rtrim($path, '/'), '/Theme/view/theme');
        } catch (\Throwable) {
            return false;
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
