<?php
declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Theme\Model\ThemeLayoutVersion;

/**
 * 解析当前主题「可视化编辑器已发布版本」（ThemeLayoutVersion），供前台 runtime / 错误监控 / 维护波次标签使用。
 *
 * 版本权威来源为 theme_layout_version.is_published（编辑器版本面板），不是 theme_scope_release reason/修订号。
 */
class ThemePublishedVersionRuntimeResolver
{
    /**
     * @return array{themePublishedVersionId: string, themePublishedVersion: string}
     */
    public function resolve(?int $themeId = null, string $pageType = 'homepage'): array
    {
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

            /** @var ThemeLayoutVersionService $versions */
            $versions = ObjectManager::getInstance(ThemeLayoutVersionService::class);
            foreach ($this->identityCandidates() as $identity) {
                $published = $versions->getPublishedVersion($themeId, $pageType, $identity);
                if (!$published instanceof ThemeLayoutVersion || $published->getVersionId() <= 0) {
                    continue;
                }

                return [
                    'themePublishedVersionId' => (string)$published->getVersionId(),
                    'themePublishedVersion' => $published->getDisplayName(),
                ];
            }
        } catch (\Throwable) {
            return $empty;
        }

        return $empty;
    }

    /**
     * @return list<array{layout_option:string,scope:string,locale_code:string,target_type:string,target_id:int}>
     */
    private function identityCandidates(): array
    {
        $list = [];
        $seen = [];
        $push = static function (string $scope) use (&$list, &$seen): void {
            $scope = \trim($scope);
            if ($scope === '' || isset($seen[$scope])) {
                return;
            }
            $seen[$scope] = true;
            $list[] = [
                'layout_option' => 'default',
                'scope' => $scope,
                'locale_code' => '',
                'target_type' => 'global',
                'target_id' => 0,
            ];
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

        // 无请求 Scope（CLI / 维护固化）：对齐前台 website/store 已发布编辑器版本。
        $push('default.__store__.default');
        $push('default.__website__.default');
        $push('default.default.default');

        return $list;
    }
}
