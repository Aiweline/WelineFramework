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
        return (string)__('Websites 默认建站流程');
    }

    public function getDescription(): string
    {
        return (string)__('Weline_Websites 内置的 AI 建站工作台默认流程提供者。');
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
        ];
        if (($context['source'] ?? '') !== '') {
            $resolvedScope['created_from'] = (string)$context['source'];
        }

        return [
            'badge' => (string)__('默认流程'),
            'target_url' => $entryUrl,
            'target_label' => (string)__('使用极速建站'),
            'workspace_label' => (string)__('创建工作区'),
            'handoff_label' => (string)__('返回极速建站入口'),
            'native_entry_url' => $entryUrl,
            'welcome_message' => (string)__('已为你创建一个可恢复的 AI 建站工作区。你可以先整理需求、域名、阶段和备注，再决定继续走极速建站还是更深入的扩展流程。'),
            'scope' => $resolvedScope,
            'provider_state' => [
                'provider' => [
                    'code' => 'websites_default',
                    'native_entry_url' => $entryUrl,
                ],
            ],
            'tools' => [
                [
                    'code' => 'open_quick_build',
                    'label' => (string)__('打开极速建站'),
                    'description' => (string)__('回到 Websites 默认的极速建站入口。'),
                    'type' => 'link',
                    'icon' => 'mdi mdi-rocket-launch-outline',
                    'button_class' => 'btn-primary',
                    'url' => $entryUrl,
                ],
                [
                    'code' => 'focus_domain_stage',
                    'label' => (string)__('切到域名准备'),
                    'description' => (string)__('把工作区重点切换到域名与基础设施准备阶段。'),
                    'type' => 'scope_patch',
                    'icon' => 'mdi mdi-earth',
                    'button_class' => 'btn-outline-primary',
                    'stage' => 'domain',
                    'scope_patch' => [
                        'workbench_focus' => 'domain_and_dns',
                        'preferred_flow' => 'websites_default',
                    ],
                ],
            ],
        ];
    }
}
