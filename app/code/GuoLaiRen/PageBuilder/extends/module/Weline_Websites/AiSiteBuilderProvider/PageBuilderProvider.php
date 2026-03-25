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
        return (string)__('AI 建站工作台 · PageBuilder 扩展');
    }

    public function getDescription(): string
    {
        return (string)__('适合在 Websites 基础建站准备完成后，切换到 PageBuilder styles 模板、页面组件和可视化精修扩展流程的建站任务。');
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
        $nativeEntryUrl = $this->resolveNativeEntryUrl($sessionState, $scope, $legacyUrl);
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
            'target_url' => $nativeEntryUrl,
            'target_label' => (string)__('进入 PageBuilder 扩展流程'),
            'workspace_label' => (string)__('创建兼容工作区'),
            'handoff_label' => (string)__('继续到 PageBuilder 扩展工作台'),
            'native_entry_url' => $nativeEntryUrl,
            'welcome_message' => (string)__('已为你创建兼容 PageBuilder 的 AI 建站工作区。这里会沿用 Websites 的信息准备结果，但页面生成和精修会由 PageBuilder 扩展流程接管，不属于 Websites 默认流程。'),
            'initial_stage' => $initialStage,
            'scope' => $resolvedScope,
            'provider_state' => [
                'provider' => [
                    'code' => 'pagebuilder',
                    'native_entry_url' => $nativeEntryUrl,
                    'quick_build_url' => $quickBuildUrl,
                ],
            ],
            'stage_guides' => [
                'prepare' => [
                    'description' => (string)__('这一阶段先沿用 Websites 的基础建站准备，整理需求和域名建议；确认后再切换到 PageBuilder 的 styles 模板扩展流程。'),
                    'ai_recommendation' => (string)__('AI 会先帮你收敛适合的 PageBuilder 风格方向，为后面的 styles 模板选择减少试错。'),
                    'confirm_label' => (string)__('确认基础信息，切换到 PageBuilder 扩展'),
                    'scope_patch' => [
                        'journey_stage' => 'prepare',
                        'preferred_editor' => 'pagebuilder',
                    ],
                ],
                'generate' => [
                    'title' => (string)__('页面生成（PageBuilder 扩展）'),
                    'description' => (string)__('从这一步开始由 PageBuilder 扩展流程接管，不再沿用 Websites 默认主题生成。PageBuilder 的主题来自 `styles` 目录里的风格模板，建议先选最接近的 styles 模板，再替换页面区域和内容组件。'),
                    'ai_recommendation_title' => (string)__('AI 模板建议'),
                    'ai_recommendation' => (string)__('AI 会优先推荐一个 `styles` 模板方向，再给出页面类型和组件建议。Header / Footer 默认固定，不建议在这一步随意改。'),
                    'confirm_label' => (string)__('确认 styles 模板与扩展页面方案'),
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
                    'description' => (string)__('进入 PageBuilder 原生 AI 建站工作台，继续由扩展流程处理 styles 模板与精修。'),
                    'type' => 'link',
                    'icon' => 'mdi mdi-view-dashboard-edit-outline',
                    'button_class' => 'btn-primary',
                    'url' => $nativeEntryUrl,
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
                    'description' => (string)__('把当前工作区从 Websites 基础准备阶段切换到 PageBuilder styles 模板扩展流程，并进入页面生成阶段。'),
                    'type' => 'scope_patch',
                    'icon' => 'mdi mdi-image-edit-outline',
                    'button_class' => 'btn-outline-primary',
                    'stage' => 'generate',
                    'scope_patch' => [
                        'preferred_editor' => 'pagebuilder',
                        'preferred_flow' => 'pagebuilder_style_template',
                        'theme_generation_mode' => 'existing_style_template',
                        'pagebuilder_theme_source' => 'styles',
                        'provider_handoff_mode' => self::HANDOFF_MODE_NATIVE_WORKSPACE,
                        'header_footer_locked' => 1,
                    ],
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
            return $this->url->getBackendUrl('pagebuilder/backend/aiSiteAgent/workspace', ['public_id' => $workspacePublicId]);
        }

        $sessionPublicId = \trim((string)($sessionState['public_id'] ?? ''));
        if ($sessionPublicId !== '') {
            return $this->url->getBackendUrl('websites/backend/site-builder-agent/pagebuilder-handoff', ['public_id' => $sessionPublicId]);
        }

        return $legacyUrl;
    }
}
