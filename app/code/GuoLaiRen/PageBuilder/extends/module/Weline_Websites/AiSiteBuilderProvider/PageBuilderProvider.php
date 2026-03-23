<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Extends\Module\Weline_Websites\AiSiteBuilderProvider;

use Weline\Framework\Http\Url;
use Weline\Websites\Api\AiSiteBuilderWorkbenchProviderInterface;

class PageBuilderProvider implements AiSiteBuilderWorkbenchProviderInterface
{
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
        return (string)__('PageBuilder 分阶段工作台');
    }

    public function getDescription(): string
    {
        return (string)__('适合需要会话恢复、阶段推进、页面预览、主题绑定与发布前检查的 AI 建站任务。');
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
        $legacyUrl = $this->url->getBackendUrl('pagebuilder/backend/aiSiteAgent/index', ['legacy' => 1]);
        $quickBuildUrl = $this->url->getBackendUrl('pagebuilder/backend/quickBuild/wizard');
        $domainManagementUrl = $this->url->getBackendUrl('pagebuilder/backend/domainManagement/index');
        $websiteManagementUrl = $this->url->getBackendUrl('pagebuilder/backend/websiteManagement/index');
        $pageIndexUrl = $this->url->getBackendUrl('pagebuilder/backend/page/index');

        $resolvedScope = [
            'provider_code' => 'pagebuilder',
            'preferred_editor' => 'pagebuilder',
            'preferred_flow' => 'pagebuilder_visual_edit',
        ];
        if (($context['source'] ?? '') !== '') {
            $resolvedScope['created_from'] = (string)$context['source'];
        }

        $tools = [
            [
                'code' => 'resume_legacy_workspace',
                'label' => (string)__('打开 PageBuilder 工作台'),
                'description' => (string)__('进入 PageBuilder 原生 AI 建站工作台，继续旧版精修流程。'),
                'type' => 'link',
                'icon' => 'mdi mdi-view-dashboard-edit-outline',
                'button_class' => 'btn-primary',
                'url' => $legacyUrl,
            ],
            [
                'code' => 'open_quick_build_wizard',
                'label' => (string)__('打开 QuickBuild 向导'),
                'description' => (string)__('直接进入 PageBuilder 的站点初始化向导。'),
                'type' => 'link',
                'icon' => 'mdi mdi-wizard-hat',
                'button_class' => 'btn-outline-secondary',
                'url' => $quickBuildUrl,
            ],
            [
                'code' => 'open_domain_management',
                'label' => (string)__('管理域名'),
                'description' => (string)__('查看或处理 PageBuilder 侧的域名相关任务。'),
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
                'code' => 'prepare_visual_edit_stage',
                'label' => (string)__('切到可视精修'),
                'description' => (string)__('把工作区切到 PageBuilder 更擅长的可视化编辑阶段。'),
                'type' => 'scope_patch',
                'icon' => 'mdi mdi-image-edit-outline',
                'button_class' => 'btn-outline-primary',
                'stage' => 'visual_edit',
                'scope_patch' => [
                    'preferred_editor' => 'pagebuilder',
                    'preferred_flow' => 'pagebuilder_visual_edit',
                    'provider_handoff_mode' => 'legacy_workspace',
                ],
            ],
        ];

        $initialStage = '';
        if ((int)($scope['preview_page_id'] ?? 0) > 0 || (int)($scope['website_id'] ?? 0) > 0) {
            $initialStage = 'visual_edit';
        }

        return [
            'badge' => (string)__('扩展流程'),
            'target_url' => $legacyUrl,
            'target_label' => (string)__('进入分阶段工作台'),
            'workspace_label' => (string)__('创建兼容工作区'),
            'handoff_label' => (string)__('继续到 PageBuilder 旧工作台'),
            'native_entry_url' => $legacyUrl,
            'welcome_message' => (string)__('已为你创建一个兼容 PageBuilder 的 AI 建站工作区。你可以先在这里整理需求、阶段与 scope，再切换到 PageBuilder 的原生精修工作台。'),
            'initial_stage' => $initialStage,
            'scope' => $resolvedScope,
            'provider_state' => [
                'provider' => [
                    'code' => 'pagebuilder',
                    'native_entry_url' => $legacyUrl,
                    'quick_build_url' => $quickBuildUrl,
                ],
            ],
            'tools' => $tools,
        ];
    }
}
