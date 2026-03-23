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
        return (string)__('AI 建站工作台 · PageBuilder 扩展');
    }

    public function getDescription(): string
    {
        return (string)__('适合需要走 PageBuilder styles 模板、页面组件和可视化精修的建站任务。');
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
            'preferred_flow' => 'pagebuilder_style_template',
            'theme_generation_mode' => 'existing_style_template',
            'pagebuilder_theme_source' => 'styles',
        ];
        if (($context['source'] ?? '') !== '') {
            $resolvedScope['created_from'] = (string)$context['source'];
        }

        $initialStage = ((int)($scope['preview_page_id'] ?? 0) > 0 || (int)($scope['website_id'] ?? 0) > 0)
            ? 'generate'
            : 'prepare';

        return [
            'badge' => (string)__('扩展流程'),
            'target_url' => $legacyUrl,
            'target_label' => (string)__('进入 PageBuilder 扩展流程'),
            'workspace_label' => (string)__('创建兼容工作区'),
            'handoff_label' => (string)__('继续到 PageBuilder 工作台'),
            'native_entry_url' => $legacyUrl,
            'welcome_message' => (string)__('已为你创建兼容 PageBuilder 的 AI 建站工作区。这里会先给出 AI 建议，再把 styles 模板和可视化编辑入口交给 PageBuilder。'),
            'initial_stage' => $initialStage,
            'scope' => $resolvedScope,
            'provider_state' => [
                'provider' => [
                    'code' => 'pagebuilder',
                    'native_entry_url' => $legacyUrl,
                    'quick_build_url' => $quickBuildUrl,
                ],
            ],
            'stage_guides' => [
                'prepare' => [
                    'description' => (string)__('先让 AI 整理建站需求和域名建议，后面再进入 PageBuilder 的 styles 模板流程。'),
                    'ai_recommendation' => (string)__('AI 会先帮你决定更适合走哪个 PageBuilder 风格方向，减少后面模板试错。'),
                    'confirm_label' => (string)__('确认基础信息，进入模板与页面生成'),
                    'scope_patch' => [
                        'journey_stage' => 'prepare',
                        'preferred_editor' => 'pagebuilder',
                    ],
                ],
                'generate' => [
                    'title' => (string)__('页面生成（PageBuilder）'),
                    'description' => (string)__('PageBuilder 的主题不是 Websites 通用主题，而是 `styles` 目录里的风格模板。建议先选最接近的 styles 模板，再替换页面区域和内容组件。'),
                    'ai_recommendation_title' => (string)__('AI 模板建议'),
                    'ai_recommendation' => (string)__('AI 会优先推荐一个 `styles` 模板方向，再给出页面类型和组件建议。Header / Footer 默认固定，不建议在这一步随意改。'),
                    'confirm_label' => (string)__('确认 styles 模板与页面方案'),
                    'tool_codes' => [
                        'resume_legacy_workspace',
                        'open_page_index',
                        'prepare_visual_edit_stage',
                    ],
                    'scope_patch' => [
                        'journey_stage' => 'generate',
                        'preferred_editor' => 'pagebuilder',
                        'preferred_flow' => 'pagebuilder_style_template',
                        'theme_generation_mode' => 'existing_style_template',
                        'pagebuilder_theme_source' => 'styles',
                        'header_footer_locked' => 1,
                    ],
                    'key_points' => [
                        (string)__('先选 styles 模板'),
                        (string)__('再替换内容区域和页面组件'),
                        (string)__('Header / Footer 固定不可编辑'),
                    ],
                ],
                'complete' => [
                    'description' => (string)__('最后以预览和可视化精修为主，确认域名环境准备好后再交付。'),
                    'ai_recommendation' => (string)__('AI 建议先从 PageBuilder 预览页检查首页首屏、导航和页脚，再决定是否回退到模板阶段。'),
                    'tool_codes' => [
                        'resume_legacy_workspace',
                        'open_website_management',
                    ],
                    'scope_patch' => [
                        'journey_stage' => 'complete',
                        'provider_handoff_mode' => 'legacy_workspace',
                    ],
                ],
            ],
            'tools' => [
                [
                    'code' => 'resume_legacy_workspace',
                    'label' => (string)__('打开 PageBuilder 工作台'),
                    'description' => (string)__('进入 PageBuilder 原生 AI 建站工作台，继续 styles 模板与精修流程。'),
                    'type' => 'link',
                    'icon' => 'mdi mdi-view-dashboard-edit-outline',
                    'button_class' => 'btn-primary',
                    'url' => $legacyUrl,
                ],
                [
                    'code' => 'open_quick_build_wizard',
                    'label' => (string)__('打开快速建站向导'),
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
                    'label' => (string)__('应用 PageBuilder 模板流'),
                    'description' => (string)__('把工作区切换成 PageBuilder styles 模板优先的生成方式，并进入页面生成阶段。'),
                    'type' => 'scope_patch',
                    'icon' => 'mdi mdi-image-edit-outline',
                    'button_class' => 'btn-outline-primary',
                    'stage' => 'generate',
                    'scope_patch' => [
                        'preferred_editor' => 'pagebuilder',
                        'preferred_flow' => 'pagebuilder_style_template',
                        'theme_generation_mode' => 'existing_style_template',
                        'pagebuilder_theme_source' => 'styles',
                        'provider_handoff_mode' => 'legacy_workspace',
                        'header_footer_locked' => 1,
                    ],
                ],
            ],
        ];
    }
}
