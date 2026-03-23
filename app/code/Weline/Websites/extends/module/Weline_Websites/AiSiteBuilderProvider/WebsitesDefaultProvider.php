<?php

declare(strict_types=1);

namespace Weline\Websites\Extends\Module\Weline_Websites\AiSiteBuilderProvider;

use Weline\Framework\Http\Url;
use Weline\Websites\Api\AiSiteBuilderWorkbenchProviderInterface;

class WebsitesDefaultProvider implements AiSiteBuilderWorkbenchProviderInterface
{
    public function __construct(
        private readonly Url $url,
    ) {
    }

    public function getCode(): string
    {
        return 'websites_default';
    }

    public function getName(): string
    {
        return (string)__('AI 建站工作台');
    }

    public function getDescription(): string
    {
        return (string)__('适合想快速完成域名、网站和默认页面方案的建站任务。');
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getSortOrder(): int
    {
        return 10;
    }

    public function getWorkbenchConfig(
        ?array $sessionState,
        int $adminUserId,
        array $scope = [],
        array $providerState = [],
        array $context = []
    ): array {
        $entryUrl = $this->url->getBackendUrl('*/backend/site-builder-agent/index', ['provider' => 'websites_default']) . '#quick-build';
        $resolvedScope = [
            'provider_code' => 'websites_default',
            'preferred_flow' => 'websites_default',
            'theme_generation_mode' => 'ai_new_theme',
        ];
        if (($context['source'] ?? '') !== '') {
            $resolvedScope['created_from'] = (string)$context['source'];
        }

        return [
            'badge' => (string)__('默认流程'),
            'target_url' => $entryUrl,
            'target_label' => (string)__('使用快速建站'),
            'workspace_label' => (string)__('创建工作区'),
            'handoff_label' => (string)__('返回快速建站入口'),
            'native_entry_url' => $entryUrl,
            'welcome_message' => (string)__('已为你创建 AI 建站工作区。默认流程会优先帮你整理需求、推荐域名，再生成站点页面方案。'),
            'initial_stage' => 'prepare',
            'scope' => $resolvedScope,
            'provider_state' => [
                'provider' => [
                    'code' => 'websites_default',
                    'native_entry_url' => $entryUrl,
                ],
            ],
            'stage_guides' => [
                'prepare' => [
                    'description' => (string)__('先说清楚要做什么站，AI 会推荐域名、购买服务商和自动准备顺序。'),
                    'ai_recommendation' => (string)__('默认会先推荐 1 到 3 个可读性更好的域名，并给出优先购买建议。'),
                    'confirm_label' => (string)__('确认域名和准备方案'),
                    'scope_patch' => [
                        'preferred_flow' => 'websites_default',
                        'journey_stage' => 'prepare',
                    ],
                    'key_points' => [
                        (string)__('AI 推荐域名'),
                        (string)__('AI 推荐购买服务商'),
                        (string)__('确认后进入自动准备'),
                    ],
                ],
                'generate' => [
                    'description' => (string)__('默认流程会先给出页面类型、主题方向和内容结构，尽量减少人工配置。'),
                    'ai_recommendation' => (string)__('AI 会优先推荐：首页、关于我们、产品或服务、FAQ、联系页，并自动保留固定 Header / Footer。'),
                    'confirm_label' => (string)__('确认页面方案'),
                    'scope_patch' => [
                        'preferred_flow' => 'websites_default',
                        'theme_generation_mode' => 'ai_new_theme',
                        'content_generation_mode' => 'ai_sections',
                        'header_footer_locked' => 1,
                    ],
                    'key_points' => [
                        (string)__('默认所有核心页面类型'),
                        (string)__('可选 AI 新主题或复用已有模板'),
                        (string)__('Header / Footer 固定不可编辑'),
                    ],
                ],
                'complete' => [
                    'description' => (string)__('最后等待域名环境准备完成，再预览站点或回退修改。'),
                    'ai_recommendation' => (string)__('AI 会提醒你优先检查首页、转化页和联系方式页，再决定是否返回上一阶段。'),
                    'scope_patch' => [
                        'journey_stage' => 'complete',
                        'delivery_mode' => 'preview_then_publish',
                    ],
                ],
            ],
            'tools' => [
                [
                    'code' => 'open_quick_build',
                    'label' => (string)__('打开快速建站'),
                    'description' => (string)__('回到 Websites 默认的一键建站入口。'),
                    'type' => 'link',
                    'icon' => 'mdi mdi-rocket-launch-outline',
                    'button_class' => 'btn-primary',
                    'url' => $entryUrl,
                ],
                [
                    'code' => 'focus_domain_stage',
                    'label' => (string)__('回到信息准备'),
                    'description' => (string)__('把当前工作区重新聚焦到域名和准备动作。'),
                    'type' => 'scope_patch',
                    'icon' => 'mdi mdi-earth',
                    'button_class' => 'btn-outline-primary',
                    'stage' => 'prepare',
                    'scope_patch' => [
                        'workbench_focus' => 'domain_and_dns',
                        'journey_stage' => 'prepare',
                        'preferred_flow' => 'websites_default',
                    ],
                ],
            ],
        ];
    }
}
