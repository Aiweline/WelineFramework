<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Model\VirtualThemeLayout;

class AiSiteVirtualLayoutService
{
    public function __construct(
        private readonly AiSiteAgentSessionService $sessionService,
        private readonly AiSiteScopeCompatibilityService $scopeCompatibilityService,
        private readonly VirtualThemeLayout $virtualThemeLayoutModel,
    ) {
    }

    /**
     * @return array{
     *   session:AiSiteAgentSession,
     *   scope:array<string, mixed>,
     *   virtual_theme_id:int,
     *   page_type:string
     * }|null
     */
    public function loadContext(string $publicId, int $adminId, string $pageType): ?array
    {
        $publicId = \trim($publicId);
        $pageType = \trim($pageType);
        if ($publicId === '' || $adminId <= 0 || $pageType === '') {
            return null;
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return null;
        }

        $stageScope = $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT, []);
        if (!\is_array($stageScope) || $stageScope === []) {
            $stageScope = $session->getScopeArray();
        }
        $stageScope = $this->scopeCompatibilityService->normalizePreviewContentLocale($stageScope);
        $scope = $this->scopeCompatibilityService->normalizeScope($stageScope);
        $virtualThemeId = \max(
            (int)($scope['virtual_theme_id'] ?? 0),
            (int)$session->getVirtualThemeId()
        );
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $planPages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        $planPage = \is_array($planPages[$pageType] ?? null) ? $planPages[$pageType] : [];
        $hasPlanBlocks = $this->extractPlanJsonPageBlocks($planPage) !== [];
        if ($virtualThemeId <= 0 && !$hasPlanBlocks) {
            return null;
        }

        return [
            'session' => $session,
            'scope' => $scope,
            'virtual_theme_id' => $virtualThemeId,
            'page_type' => $pageType,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getResolvedLayout(int $virtualThemeId, string $pageType): array
    {
        $layout = $this->loadOwnLayout($virtualThemeId, $pageType);
        $resolved = $layout;

        if ($pageType !== Page::TYPE_HOME) {
            $homeLayout = $this->loadOwnLayout($virtualThemeId, Page::TYPE_HOME);
            if (!empty($homeLayout['header']['component'])) {
                $resolved['header'] = $homeLayout['header'];
            }
            if (!empty($homeLayout['footer']['component'])) {
                $resolved['footer'] = $homeLayout['footer'];
            }
        }

        return $this->sanitizeSharedIdentityAssetUrls(
            $this->scopeCompatibilityService->normalizeLayoutConfig($resolved, $pageType)
        );
    }

    /**
     * @param array<string, mixed> $layout
     * @return array<string, mixed>
     */
    private function sanitizeSharedIdentityAssetUrls(array $layout): array
    {
        foreach (['header', 'footer'] as $region) {
            if (!\is_array($layout[$region]['config'] ?? null)) {
                continue;
            }
            $layout[$region]['config'] = $this->clearInvalidIdentityAssetReferences($layout[$region]['config']);
        }

        return $layout;
    }

    /**
     * @param array<string,mixed> $value
     * @return array<string,mixed>
     */
    private function clearInvalidIdentityAssetReferences(array $value): array
    {
        foreach ($value as $key => $item) {
            $normalizedKey = \strtolower(\trim((string)$key));
            if (\is_array($item)) {
                $value[$key] = $this->clearInvalidIdentityAssetReferences($item);
                continue;
            }
            if (!\is_string($item) || \trim($item) === '') {
                continue;
            }

            if (
                \in_array($normalizedKey, ['logo', 'logo.image', 'logo.url', 'brand.logo', 'identity.shared_logo_asset', 'shared_logo_asset'], true)
                && $this->identityAssetUrlIsInvalidForRole($item, 'logo')
            ) {
                unset($value[$key]);
                continue;
            }

            if (
                \in_array($normalizedKey, ['icon', 'favicon', 'site.icon', 'identity.shared_icon_asset', 'shared_icon_asset'], true)
                && $this->identityAssetUrlIsInvalidForRole($item, 'icon')
            ) {
                unset($value[$key]);
            }
        }

        return $value;
    }

    private function identityAssetUrlIsInvalidForRole(string $url, string $role): bool
    {
        unset($role);
        $url = \trim($url);
        if ($url === '') {
            return false;
        }
        $path = \parse_url($url, \PHP_URL_PATH);
        $path = \is_string($path) && $path !== '' ? $path : $url;
        $path = '/' . \ltrim(\preg_replace('#/+#', '/', \str_replace('\\', '/', $path)) ?? $path, '/');
        $lowerPath = \strtolower($path);
        $isPageBuilderGeneratedAsset = \str_contains($lowerPath, '/pub/media/page-build/')
            && \str_contains($lowerPath, '/ai-generated/');
        return $isPageBuilderGeneratedAsset;
    }

    private function pngAppearsToHaveTransparentBackground(string $bytes): bool
    {
        if (\function_exists('imagecreatefromstring')) {
            $image = @\imagecreatefromstring($bytes);
            if ($image !== false) {
                $width = \imagesx($image);
                $height = \imagesy($image);
                $points = [
                    [0, 0],
                    [\max(0, $width - 1), 0],
                    [0, \max(0, $height - 1)],
                    [\max(0, $width - 1), \max(0, $height - 1)],
                ];
                $transparent = 0;
                foreach ($points as [$x, $y]) {
                    $alpha = (\imagecolorat($image, $x, $y) >> 24) & 0x7F;
                    if ($alpha >= 80) {
                        $transparent++;
                    }
                }
                \imagedestroy($image);

                return $transparent >= 3;
            }
        }

        $colorType = \ord($bytes[25] ?? "\0");
        return \in_array($colorType, [4, 6], true) || \str_contains($bytes, 'tRNS');
    }

    /**
     * @param array<string, mixed> $layout
     * @return array<string, mixed>
     */
    public function saveResolvedLayout(int $virtualThemeId, string $pageType, array $layout, ?string $region = null): array
    {
        $region = $region !== null ? \strtolower(\trim($region)) : null;
        $layout = $this->scopeCompatibilityService->normalizeLayoutConfig($layout, $pageType);
        $ownLayout = $this->loadOwnLayout($virtualThemeId, $pageType);

        if ($pageType !== Page::TYPE_HOME) {
            if ($region === 'header' || $region === 'footer') {
                $homeLayout = $this->loadOwnLayout($virtualThemeId, Page::TYPE_HOME);
                if ($region === 'header') {
                    $homeLayout['header'] = $layout['header'];
                }
                if ($region === 'footer') {
                    $homeLayout['footer'] = $layout['footer'];
                }
                $this->persistOwnLayout($virtualThemeId, Page::TYPE_HOME, $homeLayout);
            } else {
                $ownLayout['content'] = $layout['content'];
                $ownLayout['use_original_template'] = (bool)($layout['use_original_template'] ?? false);
                $this->persistOwnLayout($virtualThemeId, $pageType, $ownLayout);
            }

            return $this->getResolvedLayout($virtualThemeId, $pageType);
        }

        $this->persistOwnLayout($virtualThemeId, $pageType, $layout);
        return $this->getResolvedLayout($virtualThemeId, $pageType);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function saveVirtualPagePatch(
        int $sessionId,
        int $adminId,
        array $scope,
        string $pageType,
        array $patch
    ): array {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $planPages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        $planPages[$pageType] = \array_replace(
            \is_array($planPages[$pageType] ?? null) ? $planPages[$pageType] : ['page_type' => $pageType],
            $patch
        );
        $planJson['pages'] = $planPages;
        $scope['plan_json'] = $planJson;
        $this->sessionService->replaceScope($sessionId, $adminId, $scope);

        return $planPages[$pageType];
    }

    /**
     * @param array<string, mixed> $page
     * @return array<string, array<string, mixed>>
     */
    private function extractPlanJsonPageBlocks(array $page): array
    {
        $reserved = [
            'page_id' => true,
            'id' => true,
            'page_type' => true,
            'type' => true,
            'title' => true,
            'description' => true,
            'page_goal' => true,
            'page_design_plan' => true,
            'theme_alignment_summary' => true,
            'status' => true,
            'seo' => true,
            'route' => true,
            'meta' => true,
            'layout' => true,
            'blocks' => true,
            'block_previews' => true,
            'sections' => true,
            'components' => true,
        ];
        $blocks = [];
        foreach ($page as $key => $value) {
            if (!\is_string($key) || isset($reserved[$key]) || !\is_array($value)) {
                continue;
            }
            $blocks[$key] = $value;
        }

        return $blocks;
    }

    /**
     * @return array<string, mixed>
     */
    public function loadOwnLayout(int $virtualThemeId, string $pageType): array
    {
        $layout = clone $this->virtualThemeLayoutModel;
        $layout->clearData()->clearQuery()
            ->where(VirtualThemeLayout::schema_fields_VIRTUAL_THEME_ID, $virtualThemeId)
            ->where(VirtualThemeLayout::schema_fields_PAGE_TYPE, $pageType)
            ->where(VirtualThemeLayout::schema_fields_AREA, 'frontend')
            ->order(VirtualThemeLayout::schema_fields_ID, 'DESC')
            ->find()
            ->fetch();

        if (!$layout->getId()) {
            return $this->scopeCompatibilityService->normalizeLayoutConfig([], $pageType);
        }

        return $this->scopeCompatibilityService->normalizeLayoutConfig($layout->getConfig(), $pageType);
    }

    /**
     * @param array<string, mixed> $layout
     */
    private function persistOwnLayout(int $virtualThemeId, string $pageType, array $layout): void
    {
        $record = clone $this->virtualThemeLayoutModel;
        $record->clearData()->clearQuery()
            ->where(VirtualThemeLayout::schema_fields_VIRTUAL_THEME_ID, $virtualThemeId)
            ->where(VirtualThemeLayout::schema_fields_PAGE_TYPE, $pageType)
            ->where(VirtualThemeLayout::schema_fields_AREA, 'frontend')
            ->order(VirtualThemeLayout::schema_fields_ID, 'DESC')
            ->find()
            ->fetch();

        $layout = $this->scopeCompatibilityService->normalizeLayoutConfig($layout, $pageType);
        $record->setVirtualThemeId($virtualThemeId)
            ->setPageType($pageType)
            ->setArea('frontend')
            ->setConfig($layout)
            ->setVersion((string)($layout['version'] ?? '1.0'))
            ->setUseOriginalTemplate((bool)($layout['use_original_template'] ?? false))
            ->setPageId((int)($layout['page_id'] ?? 0))
            ->save();
    }
}
