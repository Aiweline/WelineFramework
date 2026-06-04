<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Model\Page;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Http\Sse\SseWriter;

class AiSiteAgentRegeneratePageOperationService
{
    private const REGENERATE_PAGE_SCOPE_ARTIFACT_KEYS = [
        'plan_json',
        'content_manifest',
        'build_contracts',
        'render_data_contract',
        'task_results',
        'qa_report',
        'repair_patch',
    ];

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
            throw new \RuntimeException((string)__('Missing page type for regeneration.'));
        }
        /** @var AiSiteAgentSessionService $sessionService */
        $sessionService = ObjectManager::getInstance(AiSiteAgentSessionService::class);
        $scope = ($ports->normalizeScope)(
            $sessionService->loadScopeForStage(
                $session,
                AiSiteAgentSession::STAGE_VISUAL_EDIT,
                self::REGENERATE_PAGE_SCOPE_ARTIFACT_KEYS
            )
        );
        $pageTypes = ($ports->resolveScopedPageTypes)($scope);
        if (!\in_array($pageType, $pageTypes, true)) {
            throw new \RuntimeException((string)__('Selected page is not available for regeneration.'));
        }
        $scope['website_profile'] = ($ports->generateProfile)($scope);
        $scope = ($ports->ensureTaskScope)($scope, \is_array($scope['website_profile']) ? $scope['website_profile'] : [], (string)($scope['workspace_track'] ?? ''));
        $scope = ($ports->resetPageTasksForRetry)($scope, $pageType);

        $workspaceTrack = ($ports->normalizeWorkspaceTrack)((string)($scope['workspace_track'] ?? ''));
        $pageLabel = (string)((($ports->resolvePageTypeLabels)()[$pageType] ?? $pageType));

        if ($workspaceTrack === AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCK_NODES) {
            ($ports->sendOperationProgress)($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'regenerate_page', __('Regenerating page: {page}', ['page' => $pageLabel]), 20, $pageType);
            $virtualPages = ($ports->buildVirtualPagesByType)($pageTypes, $scope);
            $blueprint = ($ports->buildPageBlueprint)($pageType, $scope, $scope['website_profile']);
            $blocks = ($ports->buildPlaceholderBlocksForPageType)($pageType, $scope['website_profile'], $scope);
            $row = $virtualPages[$pageType] ?? [];
            $row['block_nodes'] = $blocks;
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
                AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCK_NODES,
                \max((int)($scope['draft_website_id'] ?? 0), (int)($scope['website_id'] ?? 0), (int)$session->getWebsiteId()),
                $scope['website_profile'],
                \array_keys($virtualPages),
                [],
                $virtualPages
            );
            $scope = ($ports->mergeMaterializedPagesIntoScope)($scope, $materialized);
            $scope['plan_json_execution_summary'] = ($ports->summarizePlanJsonTasks)($scope);
            $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
            $scope['active_operation'] = \array_replace(\is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [], ['status' => 'done', 'updated_at' => \date('Y-m-d H:i:s'), 'message' => (string)__('Page blocks regenerated')]);
            ($ports->replaceScope)($session->getId(), $adminId, $scope);
            ($ports->sendOperationProgress)($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'regenerate_page', __('Page regeneration complete'), 100, $pageType);

            ($ports->appendWorkspaceEvent)(
                $session->getId(),
                $adminId,
                AiSiteAgentSession::STAGE_VISUAL_EDIT,
                'page_generated',
                (string)__('Page regenerated: {page}', ['page' => $pageLabel]),
                [
                    'operation' => 'regenerate_page',
                    'page_type' => $pageType,
                    'details' => [
                        'section_count' => \count(\is_array($blueprint['sections'] ?? null) ? $blueprint['sections'] : []),
                    ],
                ]
            );

            return ['message' => (string)__('Page regeneration complete'), 'page_type' => $pageType, 'virtual_theme_id' => 0];
        }

        ($ports->sendOperationProgress)($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'regenerate_page', __('Regenerating page: {page}', ['page' => $pageLabel]), 20, $pageType);
        $pageTypeLayouts = ($ports->normalizePageTypeLayouts)($scope['page_type_layouts'] ?? [], $pageTypes);
        $pageTypeLayouts[$pageType] = ($ports->normalizeLayoutConfig)([], $pageType);

        $theme = $ports->regenerateAiGeneratedVirtualThemePage instanceof \Closure
            ? ($ports->regenerateAiGeneratedVirtualThemePage)($scope, $scope['website_profile'], $pageTypes, $pageTypeLayouts, $pageType, $session->getId())
            : ($ports->ensureAiGeneratedVirtualTheme)($scope, $scope['website_profile'], $pageTypes, $pageTypeLayouts, $session->getId(), false);
        $scope['page_type_layouts'] = $theme['page_type_layouts'];
        $scope['virtual_theme_id'] = (int)$theme['virtual_theme_id'];

        $virtualPages = ($ports->buildVirtualPagesByType)($pageTypes, $scope);
        $blueprint = \is_array($theme['page_blueprint'] ?? null)
            ? $theme['page_blueprint']
            : ($ports->buildPageBlueprint)($pageType, $scope, $scope['website_profile']);
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
        $scope = $this->dropPrePublishMaterializedPagesFromVirtualThemeScope($scope, $session);
        $scope['plan_json_execution_summary'] = ($ports->summarizePlanJsonTasks)($scope);
        $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
        $scope['active_operation'] = \array_replace(\is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [], ['status' => 'done', 'updated_at' => \date('Y-m-d H:i:s'), 'message' => (string)__('Page regeneration complete')]);

        ($ports->replaceScope)($session->getId(), $adminId, $scope);
        ($ports->bindVirtualTheme)($session->getId(), $adminId, (int)$theme['virtual_theme_id']);
        ($ports->appendWorkspaceEvent)(
            $session->getId(),
            $adminId,
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'page_generated',
            (string)__('Page regenerated: {page}', ['page' => $pageLabel]),
            [
                'operation' => 'regenerate_page',
                'page_type' => $pageType,
                'details' => [
                    'section_count' => \count(\is_array($blueprint['sections'] ?? null) ? $blueprint['sections'] : []),
                    'virtual_theme_id' => (int)$theme['virtual_theme_id'],
                ],
            ]
        );

        ($ports->sendOperationProgress)($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'regenerate_page', __('Page regeneration complete; you can continue editing components'), 100, $pageType);
        return ['message' => (string)__('Page regeneration complete'), 'page_type' => $pageType, 'virtual_theme_id' => (int)$theme['virtual_theme_id']];
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function dropPrePublishMaterializedPagesFromVirtualThemeScope(array $scope, AiSiteAgentSession $session): array
    {
        if ($session->getPublishStatus() === AiSiteAgentSession::PUBLISH_STATUS_PUBLISHED) {
            return $scope;
        }

        $scope['pagebuilder_pages_by_type'] = [];
        $scope['materialized_pages_by_type'] = [];
        $scope['preview_page_id'] = 0;
        if (\is_array($scope['virtual_pages_by_type'] ?? null)) {
            foreach ($scope['virtual_pages_by_type'] as $pageType => $pageData) {
                if (!\is_array($pageData)) {
                    continue;
                }
                unset($pageData['page_id'], $pageData['materialized_page_id']);
                $scope['virtual_pages_by_type'][$pageType] = $pageData;
            }
        }

        return $scope;
    }
}
