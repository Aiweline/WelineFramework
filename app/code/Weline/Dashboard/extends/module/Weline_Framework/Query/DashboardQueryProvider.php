<?php

declare(strict_types=1);

namespace Weline\Dashboard\Extends\Module\Weline_Framework\Query;

use Weline\Dashboard\Model\DashboardView;
use Weline\Dashboard\Service\DashboardViewService;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

class DashboardQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly DashboardViewService $viewService
    ) {
    }

    public function getProviderName(): string
    {
        return 'dashboard';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'listWebsites' => $this->listWebsites(),
            'listViews' => $this->listViews($params),
            'getView' => $this->getView($params),
            'createView' => $this->createView($params),
            'renameView' => $this->renameView($params),
            'publishView' => $this->publishView($params),
            'privatizeView' => $this->privatizeView($params),
            'duplicateView' => $this->duplicateView($params),
            'setDefaultView' => $this->setDefaultView($params),
            'deleteView' => $this->deleteView($params),
            default => throw new \InvalidArgumentException((string)__('Dashboard 查询器不支持的操作：%{1}', [$operation])),
        };
    }

    private function listWebsites(): array
    {
        return [
            'success' => true,
            'items' => $this->viewService->listWebsites(),
            'default_website_id' => $this->viewService->getDefaultWebsiteId(),
        ];
    }

    private function listViews(array $params): array
    {
        $websiteId = (int)($params['website_id'] ?? 0);
        $userId = $this->viewService->getCurrentUserId();

        return [
            'success' => true,
            'items' => $this->viewService->getVisibleViews($websiteId, $userId),
            'website_id' => $websiteId > 0 ? $websiteId : $this->viewService->getDefaultWebsiteId(),
        ];
    }

    private function getView(array $params): array
    {
        $view = $this->viewService->getViewForUser((int)($params['view_id'] ?? 0), $this->viewService->getCurrentUserId());
        if (!$view) {
            return ['success' => false, 'message' => (string)__('视图不存在或无权访问。')];
        }

        return [
            'success' => true,
            'item' => $this->viewService->viewToPayload($view, $this->viewService->getCurrentUserId()),
        ];
    }

    private function createView(array $params): array
    {
        try {
            $view = $this->viewService->createView(
                (int)($params['website_id'] ?? 0),
                $this->viewService->getCurrentUserId(),
                (string)($params['name'] ?? __('未命名视图')),
                (string)($params['visibility'] ?? DashboardView::VISIBILITY_PRIVATE)
            );

            return [
                'success' => true,
                'message' => (string)__('视图已创建。'),
                'item' => $this->viewService->viewToPayload($view, $this->viewService->getCurrentUserId()),
            ];
        } catch (\Throwable $throwable) {
            return ['success' => false, 'message' => $throwable->getMessage()];
        }
    }

    private function renameView(array $params): array
    {
        try {
            $view = $this->viewService->renameView(
                (int)($params['view_id'] ?? 0),
                $this->viewService->getCurrentUserId(),
                (string)($params['name'] ?? '')
            );

            return [
                'success' => true,
                'message' => (string)__('视图已重命名。'),
                'item' => $this->viewService->viewToPayload($view, $this->viewService->getCurrentUserId()),
            ];
        } catch (\Throwable $throwable) {
            return ['success' => false, 'message' => $throwable->getMessage()];
        }
    }

    private function publishView(array $params): array
    {
        try {
            $view = $this->viewService->publishView(
                (int)($params['view_id'] ?? 0),
                $this->viewService->getCurrentUserId()
            );

            return [
                'success' => true,
                'message' => (string)__('视图已公开。'),
                'item' => $this->viewService->viewToPayload($view, $this->viewService->getCurrentUserId()),
            ];
        } catch (\Throwable $throwable) {
            return ['success' => false, 'message' => $throwable->getMessage()];
        }
    }

    private function privatizeView(array $params): array
    {
        try {
            $view = $this->viewService->privatizeView(
                (int)($params['view_id'] ?? 0),
                $this->viewService->getCurrentUserId()
            );

            return [
                'success' => true,
                'message' => (string)__('视图已转为私有。'),
                'item' => $this->viewService->viewToPayload($view, $this->viewService->getCurrentUserId()),
            ];
        } catch (\Throwable $throwable) {
            return ['success' => false, 'message' => $throwable->getMessage()];
        }
    }

    private function duplicateView(array $params): array
    {
        try {
            $view = $this->viewService->duplicateView(
                (int)($params['view_id'] ?? 0),
                $this->viewService->getCurrentUserId(),
                isset($params['name']) ? (string)$params['name'] : null
            );

            return [
                'success' => true,
                'message' => (string)__('视图已复制到我的视图。'),
                'item' => $this->viewService->viewToPayload($view, $this->viewService->getCurrentUserId()),
            ];
        } catch (\Throwable $throwable) {
            return ['success' => false, 'message' => $throwable->getMessage()];
        }
    }

    private function setDefaultView(array $params): array
    {
        try {
            $view = $this->viewService->setDefaultView(
                (int)($params['view_id'] ?? 0),
                $this->viewService->getCurrentUserId()
            );

            return [
                'success' => true,
                'message' => (string)__('默认视图已更新。'),
                'item' => $this->viewService->viewToPayload($view, $this->viewService->getCurrentUserId()),
            ];
        } catch (\Throwable $throwable) {
            return ['success' => false, 'message' => $throwable->getMessage()];
        }
    }

    private function deleteView(array $params): array
    {
        try {
            $this->viewService->deleteView(
                (int)($params['view_id'] ?? 0),
                $this->viewService->getCurrentUserId()
            );

            return [
                'success' => true,
                'message' => (string)__('视图已删除。'),
            ];
        } catch (\Throwable $throwable) {
            return ['success' => false, 'message' => $throwable->getMessage()];
        }
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'dashboard',
            'name' => __('Dashboard 报表视图'),
            'description' => __('管理后台 Dashboard 的站点范围、个人私有视图、公开视图和默认视图。'),
            'module' => 'Weline_Dashboard',
            'operations' => [
                ['name' => 'listWebsites', 'description' => __('列出站点'), 'params' => []],
                ['name' => 'listViews', 'description' => __('列出当前用户可见视图'), 'params' => [
                    ['name' => 'website_id', 'type' => 'int', 'required' => false],
                ]],
                ['name' => 'getView', 'description' => __('读取视图'), 'params' => [
                    ['name' => 'view_id', 'type' => 'int', 'required' => true],
                ]],
                ['name' => 'createView', 'description' => __('创建个人视图'), 'params' => [
                    ['name' => 'website_id', 'type' => 'int', 'required' => true],
                    ['name' => 'name', 'type' => 'string', 'required' => true],
                    ['name' => 'visibility', 'type' => 'string', 'required' => false],
                ]],
                ['name' => 'renameView', 'description' => __('重命名视图'), 'params' => [
                    ['name' => 'view_id', 'type' => 'int', 'required' => true],
                    ['name' => 'name', 'type' => 'string', 'required' => true],
                ]],
                ['name' => 'publishView', 'description' => __('公开视图'), 'params' => [
                    ['name' => 'view_id', 'type' => 'int', 'required' => true],
                ]],
                ['name' => 'privatizeView', 'description' => __('转为私有视图'), 'params' => [
                    ['name' => 'view_id', 'type' => 'int', 'required' => true],
                ]],
                ['name' => 'duplicateView', 'description' => __('复制可见视图为我的私有视图'), 'params' => [
                    ['name' => 'view_id', 'type' => 'int', 'required' => true],
                    ['name' => 'name', 'type' => 'string', 'required' => false],
                ]],
                ['name' => 'setDefaultView', 'description' => __('设置站点默认视图'), 'params' => [
                    ['name' => 'view_id', 'type' => 'int', 'required' => true],
                ]],
                ['name' => 'deleteView', 'description' => __('删除我的视图'), 'params' => [
                    ['name' => 'view_id', 'type' => 'int', 'required' => true],
                ]],
            ],
        ];
    }
}
