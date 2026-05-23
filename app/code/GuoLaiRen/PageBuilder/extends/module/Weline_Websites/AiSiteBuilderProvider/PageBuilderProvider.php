<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Extends\Module\Weline_Websites\AiSiteBuilderProvider;

use Weline\Framework\Http\Url;
use Weline\Websites\Api\AiSiteBuilderWorkbenchProviderInterface;

class PageBuilderProvider implements AiSiteBuilderWorkbenchProviderInterface
{
    private const HANDOFF_MODE_NATIVE_WORKSPACE = 'pagebuilder_native_workspace';

    public function __construct(
        private readonly Url $url,
    ) {
    }

    public function getCode(): string
    {
        return 'pagebuilder';
    }

    public function getName(): string
    {
        return (string)__('AI 建站工作台 · PageBuilder');
    }

    public function getDescription(): string
    {
        return (string)__('Websites 负责准备信息、域名和镜像工作区，真正的 AI 建站、虚拟主题、页面物化和可视化编辑全部交给 PageBuilder 原生流程。');
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getSortOrder(): int
    {
        return 30;
    }

    public function getWorkbenchConfig(
        ?array $sessionState,
        int $adminUserId,
        array $scope = [],
        array $providerState = [],
        array $context = []
    ): array {
        $entryUrl = $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/index');
        $nativeEntryUrl = $this->resolveNativeEntryUrl($sessionState, $scope, $entryUrl);
        $domainManagementUrl = $this->url->getBackendUrl('pagebuilder/backend/domainManagement/index');
        $websiteManagementUrl = $this->url->getBackendUrl('pagebuilder/backend/websiteManagement/index');
        $pageIndexUrl = $this->url->getBackendUrl('pagebuilder/backend/page/index');

        $resolvedScope = [
            'provider_code' => 'pagebuilder',
            'preferred_editor' => 'pagebuilder',
            'provider_handoff_mode' => self::HANDOFF_MODE_NATIVE_WORKSPACE,
            'provider_authority' => 'pagebuilder_native',
            /** 与 PageBuilder can_publish 组合：1=域名就绪可发布，0=仅草稿 */
            'site_ready' => (int)($scope['site_ready'] ?? 1),
            /** virtual_theme | html_blocks，全站二选一轨 */
            'workspace_track' => \trim((string)($scope['workspace_track'] ?? 'virtual_theme')) !== 'html_blocks'
                ? 'virtual_theme'
                : 'html_blocks',
        ];
        if (($context['source'] ?? '') !== '') {
            $resolvedScope['created_from'] = (string)$context['source'];
        }

        $initialStage = ((int)($scope['draft_website_id'] ?? 0) > 0
            || (int)($scope['preview_page_id'] ?? 0) > 0
            || \trim((string)($scope['pagebuilder_workspace_public_id'] ?? '')) !== '')
            ? 'generate'
            : 'prepare';

        return [
            'badge' => (string)__('PageBuilder 扩展'),
            'target_url' => $nativeEntryUrl,
            'target_label' => (string)__('进入 PageBuilder 原生流程'),
            'workspace_label' => (string)__('创建 Websites 镜像工作区'),
            'handoff_label' => (string)__('继续到 PageBuilder 原生工作台'),
            'native_entry_url' => $nativeEntryUrl,
            'welcome_message' => (string)__('已为你创建兼容 PageBuilder 的镜像工作区。这里继续收集站点准备信息，真正的虚拟主题和可视化编辑会在 PageBuilder 中完成。'),
            'initial_stage' => $initialStage,
            'scope' => $resolvedScope,
            'provider_state' => [
                'provider' => [
                    'code' => 'pagebuilder',
                    'native_entry_url' => $nativeEntryUrl,
                ],
            ],
            'stage_guides' => [
                'prepare' => [
                    'description' => (string)__('先完成站点简介、目标域名、注册商选择等准备信息；目标域名补齐前不进入方案生成，然后把流程交给 PageBuilder。'),
                    'ai_recommendation' => (string)__('AI 会先整理网站级资料输入，进入 PageBuilder 后再一次性生成草稿站点、虚拟主题、页面和可视化预览。'),
                    'confirm_label' => (string)__('确认准备信息并进入 PageBuilder'),
                    'scope_patch' => [
                        'journey_stage' => 'prepare',
                        'preferred_editor' => 'pagebuilder',
                        'provider_handoff_mode' => self::HANDOFF_MODE_NATIVE_WORKSPACE,
                    ],
                ],
                'generate' => [
                    'title' => (string)__('PageBuilder AI 建站'),
                    'description' => (string)__('从这一步开始由 PageBuilder 原生工作区接管。虚拟主题、页面物化、可视化预览和编辑都以 PageBuilder 状态为准。'),
                    'ai_recommendation_title' => (string)__('PageBuilder 原生闭环'),
                    'ai_recommendation' => (string)__('进入 PageBuilder 后执行“虚拟主题编排”，系统会创建或恢复真实草稿站点，批量物化页面，并直接给出 preview/full?visual_editor=1 的真实可视化地址。'),
                    'confirm_label' => (string)__('继续到 PageBuilder 原生工作台'),
                    'tool_codes' => [
                        'open_pagebuilder_workspace',
                        'open_page_index',
                    ],
                    'scope_patch' => [
                        'journey_stage' => 'generate',
                        'preferred_editor' => 'pagebuilder',
                        'provider_handoff_mode' => self::HANDOFF_MODE_NATIVE_WORKSPACE,
                    ],
                    'key_points' => [
                        (string)__('草稿站点会先创建，后续发布仍是同一个 website_id'),
                        (string)__('虚拟主题和页面都由 PageBuilder 服务层负责写入'),
                        (string)__('可视化预览与编辑完全复用现有 preview/full 与 page/edit 核心'),
                    ],
                ],
                'complete' => [
                    'description' => (string)__('最后在 Websites 里只做镜像确认，真实发布仍针对同一个草稿站点。'),
                    'ai_recommendation' => (string)__('优先回看镜像工作区里的可视化 iframe 和编辑器入口，确认 URLs 与 PageBuilder 原生工作区保持一致。'),
                    'tool_codes' => [
                        'open_pagebuilder_workspace',
                        'open_website_management',
                    ],
                    'scope_patch' => [
                        'journey_stage' => 'complete',
                        'provider_handoff_mode' => self::HANDOFF_MODE_NATIVE_WORKSPACE,
                    ],
                ],
            ],
            'tools' => [
                [
                    'code' => 'open_pagebuilder_workspace',
                    'label' => (string)__('打开 PageBuilder 工作台'),
                    'description' => (string)__('进入 PageBuilder 原生 AI 建站工作区，继续虚拟主题和可视化编辑流程。'),
                    'type' => 'link',
                    'icon' => 'mdi mdi-view-dashboard-edit-outline',
                    'button_class' => 'btn-primary',
                    'url' => $nativeEntryUrl,
                ],
                [
                    'code' => 'open_domain_management',
                    'label' => (string)__('管理域名'),
                    'description' => (string)__('查看或处理 PageBuilder 侧的域名任务。'),
                    'type' => 'link',
                    'icon' => 'mdi mdi-earth-box',
                    'button_class' => 'btn-outline-secondary',
                    'url' => $domainManagementUrl,
                ],
                [
                    'code' => 'open_website_management',
                    'label' => (string)__('管理站点'),
                    'description' => (string)__('跳到 PageBuilder 的站点管理列表。'),
                    'type' => 'link',
                    'icon' => 'mdi mdi-sitemap-outline',
                    'button_class' => 'btn-outline-secondary',
                    'url' => $websiteManagementUrl,
                ],
                [
                    'code' => 'open_page_index',
                    'label' => (string)__('打开页面列表'),
                    'description' => (string)__('进入 PageBuilder 页面管理界面。'),
                    'type' => 'link',
                    'icon' => 'mdi mdi-file-document-multiple-outline',
                    'button_class' => 'btn-outline-secondary',
                    'url' => $pageIndexUrl,
                ],
                [
                    'code' => 'handoff_scope_site_ready',
                    'label' => (string)__('写入 site_ready（域名门禁）'),
                    'description' => (string)__('通过 Websites 会话 merge-scope 写入 site_ready=1 表示域名流程完成；0 时 PageBuilder 仅允许草稿。详见模块 doc 计划-AI建站工作台-Websites侧.md。'),
                    'type' => 'link',
                    'icon' => 'mdi mdi-web-check',
                    'button_class' => 'btn-outline-secondary',
                    'url' => $this->url->getBackendUrl('websites/backend/site-builder-agent/index', ['provider' => 'pagebuilder']),
                ],
                [
                    'code' => 'handoff_scope_workspace_track',
                    'label' => (string)__('说明：workspace_track 双轨'),
                    'description' => (string)__('handoff 可带 workspace_track=html_blocks（默认 HTML 区块）或 virtual_theme（高级虚拟主题）。进入 PageBuilder 工作区后可在「阶段2」卡片切换。'),
                    'type' => 'link',
                    'icon' => 'mdi mdi-source-branch',
                    'button_class' => 'btn-outline-secondary',
                    'url' => $nativeEntryUrl,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed>|null $sessionState
     * @param array<string, mixed> $scope
     */
    private function resolveNativeEntryUrl(?array $sessionState, array $scope, string $legacyUrl): string
    {
        $workspaceUrl = \trim((string)($scope['pagebuilder_workspace_url'] ?? ''));
        if ($workspaceUrl !== '') {
            return $workspaceUrl;
        }

        $workspacePublicId = \trim((string)($scope['pagebuilder_workspace_public_id'] ?? ''));
        if ($workspacePublicId !== '') {
            return $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/workspace', ['public_id' => $workspacePublicId]);
        }

        $sessionPublicId = \trim((string)($sessionState['public_id'] ?? ''));
        if ($sessionPublicId !== '') {
            return $this->url->getBackendUrl('websites/backend/site-builder-agent/pagebuilder-handoff', ['public_id' => $sessionPublicId]);
        }

        return $legacyUrl;
    }
}
