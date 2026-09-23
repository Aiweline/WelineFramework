<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Model\ThemeScopeVersion;

/**
 * Theme-scope version CRUD for shared chrome authority (theme_id + scope, no page_type).
 */
final class ThemeScopeVersionService
{
    public function __construct(
        private readonly ThemeScopeVersion $versionModel,
    ) {
    }

    public function ensureCurrent(
        int $themeId,
        string $scope,
        string $scopeKind = 'website',
        ?int $websiteId = null,
        string $storeMode = 'normal',
    ): ThemeScopeVersion {
        $scope = $this->normalizeScope($scope);
        if ($themeId < 1 || $scope === '') {
            throw new \InvalidArgumentException((string)__('Theme 范围版本参数无效。'));
        }

        $current = $this->getCurrent($themeId, $scope);
        if ($current instanceof ThemeScopeVersion) {
            return $current;
        }

        // Orphan rows may exist with is_current=0 (unique on version_number still holds).
        $orphan = $this->loadLatestForScope($themeId, $scope);
        if ($orphan instanceof ThemeScopeVersion) {
            $this->unsetCurrent($themeId, $scope);
            $orphan->setIsCurrent(true)->save();
            $this->forgetFlagged($themeId, $scope);

            return $orphan;
        }

        $version = clone $this->versionModel;
        $version->reset()
            ->clearData()
            ->setThemeId($themeId)
            ->setScope($scope)
            ->setScopeKind($scopeKind)
            ->setWebsiteId($websiteId)
            ->setStoreMode($storeMode !== '' ? $storeMode : 'normal')
            ->setVersionNumber(1)
            ->setVersionName('v1')
            ->setVersionType(ThemeScopeVersion::TYPE_MANUAL)
            ->setChromePayload([])
            ->setStructureKey($this->hashStructure([]))
            ->setParentVersionId(null)
            ->setIsCurrent(true)
            ->setIsPublished(false)
            ->save();

        return $version;
    }

    private function loadLatestForScope(int $themeId, string $scope): ?ThemeScopeVersion
    {
        $result = $this->versionModel->reset()
            ->where(ThemeScopeVersion::schema_fields_THEME_ID, $themeId)
            ->where(ThemeScopeVersion::schema_fields_SCOPE, $scope)
            ->order(ThemeScopeVersion::schema_fields_VERSION_NUMBER, 'DESC')
            ->limit(1)
            ->select()
            ->fetchArray();

        if (!\is_array($result) || $result === []) {
            return null;
        }

        $row = \is_array($result[0] ?? null) ? $result[0] : $result;
        $version = clone $this->versionModel;
        $version->setData($row);

        return $version->getVersionId() > 0 ? $version : null;
    }

    public function getCurrent(int $themeId, string $scope): ?ThemeScopeVersion
    {
        $scope = $this->normalizeScope($scope);
        if ($themeId < 1 || $scope === '') {
            return null;
        }

        return $this->loadFlagged($themeId, $scope, ThemeScopeVersion::schema_fields_IS_CURRENT);
    }

    /**
     * Resolve published chrome version for scope, walking ancestors by trimming
     * the last dotted segment (a.b.c → a.b → a) until found or exhausted.
     */
    public function getPublished(int $themeId, string $scope): ?ThemeScopeVersion
    {
        $scope = $this->normalizeScope($scope);
        if ($themeId < 1 || $scope === '') {
            return null;
        }

        foreach ($this->scopeAncestorChain($scope) as $candidate) {
            $version = $this->loadFlagged(
                $themeId,
                $candidate,
                ThemeScopeVersion::schema_fields_IS_PUBLISHED,
            );
            if ($version instanceof ThemeScopeVersion) {
                return $version;
            }
        }

        return null;
    }

    public function markPublished(ThemeScopeVersion $version): void
    {
        $themeId = $version->getThemeId();
        $scope = $this->normalizeScope($version->getScope());
        if ($themeId < 1 || $scope === '' || !$version->getVersionId()) {
            throw new \InvalidArgumentException((string)__('Theme 范围发布版本无效。'));
        }

        $this->versionModel->reset()
            ->where(ThemeScopeVersion::schema_fields_THEME_ID, $themeId)
            ->where(ThemeScopeVersion::schema_fields_SCOPE, $scope)
            ->where(ThemeScopeVersion::schema_fields_IS_PUBLISHED, 1)
            ->update([ThemeScopeVersion::schema_fields_IS_PUBLISHED => 0])
            ->fetch();

        $version->setIsPublished(true)->save();
        $this->forgetFlagged($themeId, $scope);

        try {
            /** @var \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPointerResolver $pointers */
            $pointers = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPointerResolver::class,
            );
            $pointers->invalidateChrome($themeId, $scope);
            // Leaf scopes inherit published chrome; flush their cached pointers too.
            foreach (['default.__store__.default', 'default.__store__.__channel__', 'default.__website__.default', 'default.default.default'] as $leaf) {
                if ($leaf !== $scope) {
                    $pointers->invalidateChrome($themeId, $leaf);
                }
            }
        } catch (\Throwable) {
            // Pointer invalidation is best-effort; ThemeRuntimeCacheCleaner may also bump.
        }
    }

    /**
     * Persist full chrome nodes (including config) and refresh structure_key
     * from structure-only fields (excludes config body).
     *
     * @param array<string|int, mixed> $nodes
     */
    public function setChromePayload(ThemeScopeVersion $version, array $nodes): void
    {
        if (!$version->getVersionId()) {
            throw new \InvalidArgumentException((string)__('Theme 范围版本未持久化。'));
        }

        $normalized = $this->normalizeNodesMap($nodes);
        $version
            ->setChromePayload($normalized)
            ->setStructureKey($this->hashStructure($normalized))
            ->save();
        $this->forgetFlagged($version->getThemeId(), $this->normalizeScope((string)$version->getScope()));
    }

    public function createRevisionFrom(
        ThemeScopeVersion $source,
        string $name = '',
        string $actor = '',
    ): ThemeScopeVersion {
        $themeId = $source->getThemeId();
        $scope = $this->normalizeScope($source->getScope());
        if ($themeId < 1 || $scope === '' || !$source->getVersionId()) {
            throw new \InvalidArgumentException((string)__('Theme 范围修订源无效。'));
        }

        $nextNumber = $this->nextVersionNumber($themeId, $scope);
        $this->unsetCurrent($themeId, $scope);

        $revision = clone $this->versionModel;
        $revision->reset()
            ->clearData()
            ->setThemeId($themeId)
            ->setScope($scope)
            ->setScopeKind($source->getScopeKind())
            ->setWebsiteId($source->getWebsiteId())
            ->setStoreMode($source->getStoreMode())
            ->setVersionNumber($nextNumber)
            ->setVersionName($name !== '' ? $name : ('v' . $nextNumber))
            ->setVersionType(ThemeScopeVersion::TYPE_MANUAL)
            ->setChromePayload($source->getChromePayload())
            ->setStructureKey($source->getStructureKey() !== ''
                ? $source->getStructureKey()
                : $this->hashStructure($source->getChromePayload()))
            ->setParentVersionId($source->getVersionId())
            ->setIsCurrent(true)
            ->setIsPublished(false)
            ->setDescription($actor !== '' ? ('by ' . $actor) : null)
            ->save();

        return $revision;
    }

    private function loadFlagged(int $themeId, string $scope, string $flagField): ?ThemeScopeVersion
    {
        $cacheKey = $this->flaggedCacheKey($themeId, $scope, $flagField);
        $cached = RequestContext::get($cacheKey);
        if (\is_array($cached) && \array_key_exists('version', $cached)) {
            $version = $cached['version'];

            return $version instanceof ThemeScopeVersion ? $version : null;
        }

        $result = $this->versionModel->reset()
            ->where(ThemeScopeVersion::schema_fields_THEME_ID, $themeId)
            ->where(ThemeScopeVersion::schema_fields_SCOPE, $scope)
            ->where($flagField, 1)
            ->order(ThemeScopeVersion::schema_fields_VERSION_NUMBER, 'DESC')
            ->limit(1)
            ->select()
            ->fetchArray();

        if (!\is_array($result) || $result === []) {
            RequestContext::set($cacheKey, ['version' => null]);

            return null;
        }

        $row = \is_array($result[0] ?? null) ? $result[0] : $result;
        $version = clone $this->versionModel;
        $version->setData($row);
        $resolved = $version->getVersionId() > 0 ? $version : null;
        RequestContext::set($cacheKey, ['version' => $resolved]);

        return $resolved;
    }

    private function flaggedCacheKey(int $themeId, string $scope, string $flagField): string
    {
        return 'theme.scope_version.flag.' . $themeId . '|' . $scope . '|' . $flagField;
    }

    private function forgetFlagged(int $themeId, string $scope): void
    {
        foreach ([
            ThemeScopeVersion::schema_fields_IS_CURRENT,
            ThemeScopeVersion::schema_fields_IS_PUBLISHED,
        ] as $flagField) {
            RequestContext::set($this->flaggedCacheKey($themeId, $scope, $flagField), null);
        }
    }

    private function nextVersionNumber(int $themeId, string $scope): int
    {
        $result = $this->versionModel->reset()
            ->where(ThemeScopeVersion::schema_fields_THEME_ID, $themeId)
            ->where(ThemeScopeVersion::schema_fields_SCOPE, $scope)
            ->order(ThemeScopeVersion::schema_fields_VERSION_NUMBER, 'DESC')
            ->limit(1)
            ->select()
            ->fetchArray();

        if (!\is_array($result) || $result === []) {
            return 1;
        }

        $row = \is_array($result[0] ?? null) ? $result[0] : $result;

        return ((int)($row[ThemeScopeVersion::schema_fields_VERSION_NUMBER] ?? 0)) + 1;
    }

    private function unsetCurrent(int $themeId, string $scope): void
    {
        $this->versionModel->reset()
            ->where(ThemeScopeVersion::schema_fields_THEME_ID, $themeId)
            ->where(ThemeScopeVersion::schema_fields_SCOPE, $scope)
            ->where(ThemeScopeVersion::schema_fields_IS_CURRENT, 1)
            ->update([ThemeScopeVersion::schema_fields_IS_CURRENT => 0])
            ->fetch();
        $this->forgetFlagged($themeId, $scope);
    }

    private function normalizeScope(string $scope): string
    {
        return \trim($scope);
    }

    /**
     * @return list<string>
     */
    private function scopeAncestorChain(string $scope): array
    {
        $scope = $this->normalizeScope($scope);
        if ($scope === '') {
            return [];
        }

        try {
            /** @var \Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface $scopes */
            $scopes = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface::class,
            );
            $identity = $scopes->fromStorageScope($scope, true);
            if ($identity !== null) {
                $chain = $scopes->chainFromIdentity($identity);
                $out = [];
                foreach ($chain as $candidate) {
                    $candidate = $this->normalizeScope((string)$candidate);
                    if ($candidate !== '' && !\in_array($candidate, $out, true)) {
                        $out[] = $candidate;
                    }
                }
                if ($out !== []) {
                    return $out;
                }
            }
        } catch (\Throwable) {
            // Fall through to dotted trim only when hierarchy is unavailable.
        }

        $chain = [];
        $current = $scope;
        while ($current !== '') {
            $chain[] = $current;
            $pos = \strrpos($current, '.');
            if ($pos === false) {
                break;
            }
            $current = \substr($current, 0, $pos);
        }

        return $chain;
    }

    /**
     * @param array<string|int, mixed> $nodes
     * @return array<string, array<string, mixed>>
     */
    private function normalizeNodesMap(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $key => $node) {
            if (!\is_array($node)) {
                continue;
            }
            $uid = \strtolower(\trim((string)($node['node_uid'] ?? $key)));
            if ($uid === '') {
                continue;
            }
            $node['node_uid'] = $uid;
            $out[$uid] = $node;
        }

        return $out;
    }

    /**
     * Structure-only fingerprint: node_uid, area, slot_id, widget_*, sort_order, is_active.
     * Config body is excluded from the hash but still stored in chrome_payload_json.
     *
     * @param array<string, array<string, mixed>> $nodes
     */
    public function hashStructure(array $nodes): string
    {
        $rows = [];
        foreach ($nodes as $uid => $node) {
            if (!\is_array($node)) {
                continue;
            }
            $row = [
                'node_uid' => (string)($node['node_uid'] ?? $uid),
                'area' => (string)($node['area'] ?? ''),
                'slot_id' => (string)($node['slot_id'] ?? ''),
                'sort_order' => (int)($node['sort_order'] ?? 0),
                'is_active' => (int)(!empty($node['is_active']) || !\array_key_exists('is_active', $node) ? 1 : 0),
            ];
            foreach ($node as $field => $value) {
                if (!\is_string($field) || !\str_starts_with($field, 'widget_')) {
                    continue;
                }
                if (\is_scalar($value) || $value === null) {
                    $row[$field] = $value;
                }
            }
            \ksort($row);
            $rows[] = $row;
        }
        \usort(
            $rows,
            static fn(array $a, array $b): int => \strcmp((string)$a['node_uid'], (string)$b['node_uid']),
        );

        return \hash(
            'sha256',
            (string)\json_encode($rows, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
        );
    }
}
