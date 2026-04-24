<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Model\Page;
use Weline\Framework\Http\Sse\SseWriter;

class AiSiteAgentRegeneratePageOperationService
{
    /**
     * @return array<string, mixed>
     */
    public function runRegeneratePageOperation(
        SseWriter $sse,
        AiSiteAgentSession $session,
        int $adminId,
        string $pageType,
        AiSiteAgentRegeneratePageOperationPorts $ports
    ): array {
        ($ports->assertActiveStreamLeaseAlive)($session, $adminId);
        if ($pageType === '') {
            throw new \RuntimeException((string)__('缺少要重建的页面类型'));
        }
        $scope = ($ports->normalizeScope)($session->getScopeArray());
        $pageTypes = ($ports->resolveScopedPageTypes)($scope);
        if (!\in_array($pageType, $pageTypes, true)) {
            throw new \RuntimeException((string)__('页面类型不在当前工作区中'));
        }
        $scope['website_profile'] = ($ports->generateProfile)($scope);
        $scope = ($ports->ensureTaskScope)($scope, \is_array($scope['website_profile']) ? $scope['website_profile'] : [], (string)($scope['workspace_track'] ?? ''));
        $scope = ($ports->resetPageTasksForRetry)($scope, $pageType);

        $workspaceTrack = ($ports->normalizeWorkspaceTrack)((string)($scope['workspace_track'] ?? ''));
        $pageLabel = (string)((($ports->resolvePageTypeLabels)()[$pageType] ?? $pageType));

        if ($workspaceTrack === AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS) {
            ($ports->sendOperationProgress)($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'regenerate_page', __('正在重建页面：%{page}', ['page' => $pageLabel]), 20, $pageType);
            $virtualPages = ($ports->buildVirtualPagesByType)($pageTypes, $scope);
            $blueprint = ($ports->buildPageBlueprint)($pageType, $scope, $scope['website_profile']);
            $blocks = ($ports->buildPlaceholderBlocksForPageType)($pageType, $scope['website_profile'], $scope);
            $row = $virtualPages[$pageType] ?? [];
            $row['blocks'] = $blocks;
            $row['last_generated_at'] = \date('Y-m-d H:i:s');
            $row['title'] = (string)($blueprint['page_title'] ?? ($row['title'] ?? ''));
            $row['ai_description'] = (string)($blueprint['ai_description'] ?? ($row['ai_description'] ?? ''));
            $row['meta_title'] = (string)($blueprint['meta_title'] ?? ($row['meta_title'] ?? ''));
            $row['meta_description'] = (string)($blueprint['meta_description'] ?? ($row['meta_description'] ?? ''));
            $row['meta_keywords'] = (string)($blueprint['meta_keywords'] ?? ($row['meta_keywords'] ?? ''));
            $row['section_refinements'] = \is_array($blueprint['section_refinements'] ?? null) ? $blueprint['section_refinements'] : [];
            $virtualPages[$pageType] = $row;
            foreach (\is_array($blueprint['sections'] ?? null) ? $blueprint['sections'] : [] as $section) {
                if (!\is_array($section)) {
                    continue;
                }
                $sectionCode = \trim((string)($section['code'] ?? ''));
                if ($sectionCode === '') {
                    continue;
                }
                $scope = ($ports->markTaskDone)(
                    $scope,
                    'page:' . $pageType . ':' . $sectionCode,
                    ['page_type' => $pageType, 'section_code' => $sectionCode]
                );
            }
            $scope['virtual_pages_by_type'] = $virtualPages;
            $scope['preview_page_type'] = $pageType;
            $materialized = ($ports->materializeGeneratedPages)(
                AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS,
                \max((int)($scope['draft_website_id'] ?? 0), (int)($scope['website_id'] ?? 0), (int)$session->getWebsiteId()),
                $scope['website_profile'],
                \array_keys($virtualPages),
                [],
                $virtualPages
            );
            $scope = ($ports->mergeMaterializedPagesIntoScope)($scope, $materialized);
            $scope['build_summary'] = \array_replace(
                \is_array($scope['build_summary'] ?? null) ? $scope['build_summary'] : [],
                ['task_summary' => ($ports->summarizeBuildTasks)($scope)]
            );
            $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
            $scope['active_operation'] = \array_replace(\is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [], ['status' => 'done', 'updated_at' => \date('Y-m-d H:i:s'), 'message' => (string)__('页面区块已重建')]);
            ($ports->replaceScope)($session->getId(), $adminId, $scope);
            ($ports->sendOperationProgress)($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'regenerate_page', __('页面重建完成'), 100, $pageType);

            ($ports->appendWorkspaceEvent)(
                $session->getId(),
                $adminId,
                AiSiteAgentSession::STAGE_VISUAL_EDIT,
                'page_generated',
                (string)__('页面已重建：%{page}', ['page' => $pageLabel]),
                [
                    'operation' => 'regenerate_page',
                    'page_type' => $pageType,
                    'details' => [
                        'section_count' => \count(\is_array($blueprint['sections'] ?? null) ? $blueprint['sections'] : []),
                    ],
                ]
            );

            return ['message' => (string)__('页面重建完成'), 'page_type' => $pageType, 'virtual_theme_id' => 0];
        }

        ($ports->sendOperationProgress)($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'regenerate_page', __('正在重建页面：%{page}', ['page' => $pageLabel]), 20, $pageType);
        $pageTypeLayouts = ($ports->normalizePageTypeLayouts)($scope['page_type_layouts'] ?? [], $pageTypes);
        $pageTypeLayouts[$pageType] = ($ports->normalizeLayoutConfig)([], $pageType);

        $theme = ($ports->ensureAiGeneratedVirtualTheme)($scope, $scope['website_profile'], $pageTypes, $pageTypeLayouts, $session->getId(), false);
        $scope['page_type_layouts'] = $theme['page_type_layouts'];
        $scope['virtual_theme_id'] = (int)$theme['virtual_theme_id'];

        $virtualPages = ($ports->buildVirtualPagesByType)($pageTypes, $scope);
        $blueprint = ($ports->buildPageBlueprint)($pageType, $scope, $scope['website_profile']);
        $virtualPages[$pageType]['last_generated_at'] = \date('Y-m-d H:i:s');
        $virtualPages[$pageType]['title'] = (string)($blueprint['page_title'] ?? ($virtualPages[$pageType]['title'] ?? ''));
        $virtualPages[$pageType]['ai_description'] = (string)($blueprint['ai_description'] ?? ($virtualPages[$pageType]['ai_description'] ?? ''));
        $virtualPages[$pageType]['meta_title'] = (string)($blueprint['meta_title'] ?? ($virtualPages[$pageType]['meta_title'] ?? ''));
        $virtualPages[$pageType]['meta_description'] = (string)($blueprint['meta_description'] ?? ($virtualPages[$pageType]['meta_description'] ?? ''));
        $virtualPages[$pageType]['meta_keywords'] = (string)($blueprint['meta_keywords'] ?? ($virtualPages[$pageType]['meta_keywords'] ?? ''));
        $virtualPages[$pageType]['section_refinements'] = \is_array($blueprint['section_refinements'] ?? null) ? $blueprint['section_refinements'] : [];
        foreach (\is_array($blueprint['sections'] ?? null) ? $blueprint['sections'] : [] as $section) {
            if (!\is_array($section)) {
                continue;
            }
            $sectionCode = \trim((string)($section['code'] ?? ''));
            if ($sectionCode === '') {
                continue;
            }
            $scope = ($ports->markTaskDone)(
                $scope,
                'page:' . $pageType . ':' . $sectionCode,
                ['page_type' => $pageType, 'section_code' => $sectionCode]
            );
        }
        $scope['virtual_pages_by_type'] = $virtualPages;
        $scope['preview_page_type'] = $pageType;
        $materialized = ($ports->materializeGeneratedPages)(
            AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME,
            \max((int)($scope['draft_website_id'] ?? 0), (int)($scope['website_id'] ?? 0), (int)$session->getWebsiteId()),
            $scope['website_profile'],
            \array_keys($virtualPages),
            $scope['page_type_layouts'] ?? [],
            $virtualPages
        );
        $scope = ($ports->mergeMaterializedPagesIntoScope)($scope, $materialized);
        $scope['build_summary'] = \array_replace(
            \is_array($scope['build_summary'] ?? null) ? $scope['build_summary'] : [],
            ['task_summary' => ($ports->summarizeBuildTasks)($scope)]
        );
        $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
        $scope['active_operation'] = \array_replace(\is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [], ['status' => 'done', 'updated_at' => \date('Y-m-d H:i:s'), 'message' => (string)__('页面重建完成')]);

        ($ports->replaceScope)($session->getId(), $adminId, $scope);
        ($ports->bindVirtualTheme)($session->getId(), $adminId, (int)$theme['virtual_theme_id']);
        ($ports->appendWorkspaceEvent)(
            $session->getId(),
            $adminId,
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'page_generated',
            (string)__('页面已重建：%{page}', ['page' => $pageLabel]),
            [
                'operation' => 'regenerate_page',
                'page_type' => $pageType,
                'details' => [
                    'section_count' => \count(\is_array($blueprint['sections'] ?? null) ? $blueprint['sections'] : []),
                    'virtual_theme_id' => (int)$theme['virtual_theme_id'],
                ],
            ]
        );

        ($ports->sendOperationProgress)($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'regenerate_page', __('页面重建完成，可继续调整组件'), 100, $pageType);
        return ['message' => (string)__('页面重建完成'), 'page_type' => $pageType, 'virtual_theme_id' => (int)$theme['virtual_theme_id']];
    }
}
