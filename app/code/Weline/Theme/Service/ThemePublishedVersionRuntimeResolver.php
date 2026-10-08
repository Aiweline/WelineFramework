<?php
declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Model\WelineTheme;
use Weline\Framework\Runtime\ProcessSharedInterface;

/**
 * Resolve the published ThemeScopeVersion (theme version V) for storefront runtime tags.
 *
 * Authority is ThemeScopeVersion selection / is_published — not ThemeLayoutVersion page axis.
 */
class ThemePublishedVersionRuntimeResolver implements ProcessSharedInterface
{
    /**
     * @return array{themePublishedVersionId: string, themePublishedVersion: string}
     */
    public function resolve(?int $themeId = null, string $pageType = 'homepage'): array
    {
        unset($pageType);
        $empty = [
            'themePublishedVersionId' => '',
            'themePublishedVersion' => '',
        ];

        try {
            if ($themeId === null || $themeId <= 0) {
                $theme = Env::getInstance()->getTheme();
                $themeId = (int)($theme['id'] ?? $theme['theme_id'] ?? 0);
            }
            if ($themeId <= 0) {
                return $empty;
            }

            /** @var ThemeScopeVersionService $versions */
            $versions = ObjectManager::getInstance(ThemeScopeVersionService::class);
            foreach ($this->themeIdChain($themeId) as $candidateThemeId) {
                foreach ($this->scopeCandidates() as $scope) {
                    $published = $versions->getPublished($candidateThemeId, $scope);
                    if (!$published instanceof ThemeScopeVersion || $published->getVersionId() <= 0) {
                        continue;
                    }

                    $name = \trim((string)($published->getVersionName() ?? ''));
                    if ($name === '') {
                        $name = 'v' . $published->getVersionNumber();
                    }

                    return [
                        'themePublishedVersionId' => (string)$published->getVersionId(),
                        'themePublishedVersion' => $name,
                    ];
                }
            }
        } catch (\Throwable) {
            return $empty;
        }

        return $empty;
    }

    /**
     * @return list<int>
     */
    private function themeIdChain(int $themeId): array
    {
        $chain = [];
        $seen = [];
        $candidateId = $themeId;
        while ($candidateId > 0 && !isset($seen[$candidateId])) {
            $seen[$candidateId] = true;
            $chain[] = $candidateId;
            $theme = (clone ObjectManager::getInstance(WelineTheme::class))->clearData()->clearQuery()->load($candidateId);
            $parentId = (int)$theme->getParentId();
            if ($parentId < 1 || (int)$theme->getId() !== $candidateId) {
                break;
            }
            $candidateId = $parentId;
        }

        return $chain;
    }

    /**
     * @return list<string>
     */
    private function scopeCandidates(): array
    {
        $list = [];
        $seen = [];
        $push = static function (string $scope) use (&$list, &$seen): void {
            $scope = \trim($scope);
            if ($scope === '' || isset($seen[$scope])) {
                return;
            }
            $seen[$scope] = true;
            $list[] = $scope;
        };

        try {
            $scopeIdentity = RequestContext::scopeIdentity();
            if ($scopeIdentity instanceof ScopeIdentity && !$scopeIdentity->isGlobal()) {
                /** @var ScopeHierarchyInterface $scopes */
                $scopes = ObjectManager::getInstance(ScopeHierarchyInterface::class);
                $context = $scopes->contextFromIdentity($scopeIdentity);
                foreach ($context->fallbackStorageScopes as $storageScope) {
                    $push((string)$storageScope);
                }
            }
        } catch (\Throwable) {
            // CLI / early bootstrap.
        }

        $push('default.__store__.default');
        $push('default.__website__.default');
        $push('default.default.default');

        return $list;
    }
}
