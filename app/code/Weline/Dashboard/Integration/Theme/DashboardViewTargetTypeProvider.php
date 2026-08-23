<?php

declare(strict_types=1);

namespace Weline\Dashboard\Integration\Theme;

use Weline\Dashboard\Model\DashboardView;
use Weline\Dashboard\Service\DashboardViewService;
use Weline\Theme\Api\Scoped\ThemeLegacyIdentityMapperInterface;
use Weline\Theme\Api\Scoped\ThemeLegacyIdentityMapping;
use Weline\Theme\Api\TargetTypeProviderInterface;

/** Exposes Dashboard views as Theme business targets, independent of Scope. */
final class DashboardViewTargetTypeProvider implements TargetTypeProviderInterface, ThemeLegacyIdentityMapperInterface
{
    public function __construct(
        private readonly DashboardViewService $dashboardViews,
        private readonly DashboardView $dashboardView,
    ) {
    }

    public function getCode(): string
    {
        return DashboardView::TARGET_TYPE_DASHBOARD_VIEW;
    }

    public function getLabel(): string
    {
        return (string)__('Dashboard 视图');
    }

    public function getModule(): string
    {
        return 'Weline_Dashboard';
    }

    public function getLayoutTypes(): array
    {
        return [DashboardView::PAGE_TYPE];
    }

    public function getCapabilities(): array
    {
        return ['layout_selection', 'visual_editor_lock', 'virtual_layout', 'meta', 'preview', 'render'];
    }

    public function validate(int $targetId, array $context = []): bool
    {
        $view = $this->dashboardViews->getViewForUser($targetId);
        if (!$view instanceof DashboardView || !$view->isActive()) {
            return false;
        }

        $scopeIdentity = $context['scope']['identity'] ?? null;
        if (!is_array($scopeIdentity)) {
            return true;
        }

        return (int)($scopeIdentity['website_id'] ?? -1) === $view->getWebsiteId()
            && in_array((string)($scopeIdentity['scope_kind'] ?? ''), ['website', 'store', 'channel'], true);
    }

    public function resolve(int $targetId, array $context = []): ?array
    {
        if (!$this->validate($targetId, $context)) {
            return null;
        }
        $view = $this->dashboardViews->getViewForUser($targetId);
        if (!$view instanceof DashboardView) {
            return null;
        }

        return [
            'target_type' => $this->getCode(),
            'target_id' => $view->getViewId(),
            'label' => $view->getName(),
            'code' => $view->getCode(),
            'website_id' => $view->getWebsiteId(),
        ];
    }

    public function canUseLayoutType(string $layoutType): bool
    {
        return strtolower(trim($layoutType)) === DashboardView::PAGE_TYPE;
    }

    public function mapLegacyIdentity(
        string $scope,
        string $targetType,
        int $targetId,
    ): ?ThemeLegacyIdentityMapping {
        if (
            strtolower(trim($targetType)) !== DashboardView::TARGET_TYPE_WEBSITE
            || preg_match('/^dashboard_view:([1-9][0-9]*)$/D', strtolower(trim($scope)), $matches) !== 1
        ) {
            return null;
        }

        $viewId = (int)$matches[1];
        $view = clone $this->dashboardView;
        try {
            $view->clearData()->clearQuery()->load($viewId);
        } catch (\Throwable) {
            return null;
        }
        if ($view->getViewId() !== $viewId || $view->getWebsiteId() !== $targetId) {
            return null;
        }

        try {
            $identity = $this->dashboardViews->layoutIdentity($view);
        } catch (\Throwable) {
            return null;
        }

        return new ThemeLegacyIdentityMapping(
            scope: $identity->scope,
            targetType: $identity->targetType,
            targetId: $identity->targetId,
        );
    }
}
