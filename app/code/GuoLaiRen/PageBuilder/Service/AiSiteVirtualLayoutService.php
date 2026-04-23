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

        $scope = $this->scopeCompatibilityService->normalizeScope($session->getScopeArray());
        $virtualThemeId = \max(
            (int)($scope['virtual_theme_id'] ?? 0),
            (int)$session->getVirtualThemeId()
        );
        $virtualPages = \is_array($scope['virtual_pages_by_type'] ?? null) ? $scope['virtual_pages_by_type'] : [];
        $virtualPage = \is_array($virtualPages[$pageType] ?? null) ? $virtualPages[$pageType] : [];
        $hasHtmlBlocks = \is_array($virtualPage['blocks'] ?? null) && $virtualPage['blocks'] !== [];
        if ($virtualThemeId <= 0 && !$hasHtmlBlocks) {
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

        return $this->scopeCompatibilityService->normalizeLayoutConfig($resolved, $pageType);
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
        $virtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType(
            $this->scopeCompatibilityService->normalizePageTypes($scope['page_types'] ?? []),
            $scope
        );
        $virtualPages[$pageType] = \array_replace(
            $virtualPages[$pageType] ?? [],
            $patch
        );
        $scope['virtual_pages_by_type'] = $virtualPages;
        $this->sessionService->replaceScope($sessionId, $adminId, $scope);

        return $virtualPages[$pageType];
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
            ->find();

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
            ->find();

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
