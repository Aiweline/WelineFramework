<?php
declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Theme\Model\ThemeScopeVersion;

/**
 * Resolve the published ThemeScopeVersion (theme version V) for storefront runtime tags.
 *
 * Authority is ThemeScopeVersion selection / is_published — not ThemeLayoutVersion page axis.
 */
class ThemePublishedVersionRuntimeResolver
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
            foreach ($this->scopeCandidates() as $scope) {
                $published = $versions->getPublished($themeId, $scope);
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
        } catch (\Throwable) {
            return $empty;
        }

        return $empty;
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
