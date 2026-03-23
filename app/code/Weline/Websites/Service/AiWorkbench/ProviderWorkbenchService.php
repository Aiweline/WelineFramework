<?php

declare(strict_types=1);

namespace Weline\Websites\Service\AiWorkbench;

use Weline\Websites\Api\AiSiteBuilderProviderInterface;
use Weline\Websites\Api\AiSiteBuilderWorkbenchProviderInterface;
use Weline\Websites\Model\AiSiteBuilderSession;

class ProviderWorkbenchService
{
    public function __construct(
        private readonly ProviderRegistry $providerRegistry,
    ) {
    }

    /**
     * @param array<string, mixed>|null $sessionState
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $providerState
     * @param array<string, mixed> $context
     * @return array{
     *   code:string,
     *   name:string,
     *   description:string,
     *   badge:string,
     *   target_url:string,
     *   target_label:string,
     *   workspace_label:string,
     *   handoff_label:string,
     *   native_entry_url:string,
     *   welcome_message:string,
     *   initial_stage:string,
     *   scope:array<string, mixed>,
     *   provider_state:array<string, mixed>,
     *   stage_guides:list<array{
     *     code:string,
     *     label:string,
     *     title:string,
     *     description:string,
     *     ai_recommendation_title:string,
     *     ai_recommendation:string,
     *     confirm_label:string,
     *     previous_label:string,
     *     next_stage:string,
     *     previous_stage:string,
     *     tool_codes:list<string>,
     *     scope_patch:array<string, mixed>,
     *     key_points:list<string>
     *   }>,
     *   tools:list<array{
     *     code:string,
     *     label:string,
     *     description:string,
     *     type:string,
     *     icon:string,
     *     button_class:string,
     *     url:string,
     *     target:string,
     *     stage:string,
     *     scope_patch:array<string, mixed>,
     *     enabled:bool
     *   }>
     * }
     */
    public function buildWorkbenchConfig(
        string $providerCode,
        int $adminUserId,
        ?array $sessionState = null,
        array $scope = [],
        array $providerState = [],
        array $context = []
    ): array {
        $provider = $this->providerRegistry->getProvider($providerCode);
        if ($provider === null) {
            return $this->buildMissingProviderConfig($providerCode, $scope, $providerState, $sessionState);
        }

        $base = $this->buildBaseProviderConfig($provider, $scope, $providerState, $sessionState);
        $extension = $provider instanceof AiSiteBuilderWorkbenchProviderInterface
            ? $provider->getWorkbenchConfig($sessionState, $adminUserId, $scope, $providerState, $context)
            : [];

        $resolvedScope = $scope;
        if (isset($extension['scope']) && \is_array($extension['scope'])) {
            $resolvedScope = \array_replace($resolvedScope, $extension['scope']);
        }

        $resolvedProviderState = $providerState;
        if (isset($extension['provider_state']) && \is_array($extension['provider_state'])) {
            $resolvedProviderState = \array_replace($resolvedProviderState, $extension['provider_state']);
        }

        return [
            'code' => $base['code'],
            'name' => $base['name'],
            'description' => $base['description'],
            'badge' => $this->resolveString($extension, 'badge', $base['badge']),
            'target_url' => $this->resolveString($extension, 'target_url', $base['target_url']),
            'target_label' => $this->resolveString($extension, 'target_label', $base['target_label']),
            'workspace_label' => $this->resolveString($extension, 'workspace_label', $base['workspace_label']),
            'handoff_label' => $this->resolveString($extension, 'handoff_label', $base['handoff_label']),
            'native_entry_url' => $this->resolveString($extension, 'native_entry_url', $base['native_entry_url']),
            'welcome_message' => $this->resolveString($extension, 'welcome_message', $base['welcome_message']),
            'initial_stage' => $this->resolveInitialStage($extension, $base['initial_stage']),
            'scope' => $resolvedScope,
            'provider_state' => $resolvedProviderState,
            'stage_guides' => $this->normalizeStageGuides(
                $extension['stage_guides'] ?? [],
                $base['stage_guides']
            ),
            'tools' => $this->normalizeTools($extension['tools'] ?? []),
        ];
    }

    /**
     * @return array{
     *   code:string,
     *   name:string,
     *   description:string,
     *   badge:string,
     *   target_url:string,
     *   target_label:string,
     *   workspace_label:string,
     *   handoff_label:string,
     *   native_entry_url:string,
     *   welcome_message:string,
     *   initial_stage:string,
     *   scope:array<string, mixed>,
     *   provider_state:array<string, mixed>,
     *   stage_guides:list<array{
     *     code:string,
     *     label:string,
     *     title:string,
     *     description:string,
     *     ai_recommendation_title:string,
     *     ai_recommendation:string,
     *     confirm_label:string,
     *     previous_label:string,
     *     next_stage:string,
     *     previous_stage:string,
     *     tool_codes:list<string>,
     *     scope_patch:array<string, mixed>,
     *     key_points:list<string>
     *   }>,
     *   tools:list<array{
     *     code:string,
     *     label:string,
     *     description:string,
     *     type:string,
     *     icon:string,
     *     button_class:string,
     *     url:string,
     *     target:string,
     *     stage:string,
     *     scope_patch:array<string, mixed>,
     *     enabled:bool
     *   }>
     * }
     */
    public function buildWorkbenchConfigForSession(
        AiSiteBuilderSession $session,
        int $adminUserId,
        array $context = []
    ): array {
        return $this->buildWorkbenchConfig(
            $session->getProviderCode(),
            $adminUserId,
            $this->buildSessionState($session),
            $session->getScopeArray(),
            $session->getProviderStateArray(),
            $context
        );
    }

    /**
     * @return array{
     *   public_id:string,
     *   current_stage:string,
     *   website_id:int,
     *   selected_domain:string,
     *   registrar_account_id:int,
     *   preview_url:string
     * }
     */
    public function buildSessionState(AiSiteBuilderSession $session): array
    {
        return [
            'public_id' => $session->getPublicId(),
            'current_stage' => $session->getCurrentStage(),
            'website_id' => $session->getWebsiteId(),
            'selected_domain' => $session->getSelectedDomain(),
            'registrar_account_id' => $session->getRegistrarAccountId(),
            'preview_url' => $session->getPreviewUrl(),
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $providerState
     * @param array<string, mixed>|null $sessionState
     * @return array{
     *   code:string,
     *   name:string,
     *   description:string,
     *   badge:string,
     *   target_url:string,
     *   target_label:string,
     *   workspace_label:string,
     *   handoff_label:string,
     *   native_entry_url:string,
     *   welcome_message:string,
     *   initial_stage:string,
     *   scope:array<string, mixed>,
     *   provider_state:array<string, mixed>,
     *   stage_guides:list<array{
     *     code:string,
     *     label:string,
     *     title:string,
     *     description:string,
     *     ai_recommendation_title:string,
     *     ai_recommendation:string,
     *     confirm_label:string,
     *     previous_label:string,
     *     next_stage:string,
     *     previous_stage:string,
     *     tool_codes:list<string>,
     *     scope_patch:array<string, mixed>,
     *     key_points:list<string>
     *   }>,
     *   tools:list<array{
     *     code:string,
     *     label:string,
     *     description:string,
     *     type:string,
     *     icon:string,
     *     button_class:string,
     *     url:string,
     *     target:string,
     *     stage:string,
     *     scope_patch:array<string, mixed>,
     *     enabled:bool
     *   }>
     * }
     */
    private function buildBaseProviderConfig(
        AiSiteBuilderProviderInterface $provider,
        array $scope,
        array $providerState,
        ?array $sessionState
    ): array {
        $providerName = $provider->getName();

        return [
            'code' => $provider->getCode(),
            'name' => $providerName,
            'description' => $provider->getDescription(),
            'badge' => (string)__('已接入'),
            'target_url' => '',
            'target_label' => (string)__('查看此流程'),
            'workspace_label' => (string)__('创建工作区'),
            'handoff_label' => (string)__('打开 provider 原生入口'),
            'native_entry_url' => '',
            'welcome_message' => (string)__('已为你创建一个可恢复的 AI 建站工作区。你可以先整理需求、阶段与 scope，再按当前 provider 继续推进。'),
            'initial_stage' => $this->normalizeStageCode((string)($sessionState['current_stage'] ?? AiSiteBuilderSession::STAGE_BRIEF)),
            'scope' => $scope,
            'provider_state' => $providerState,
            'stage_guides' => $this->buildDefaultStageGuides($providerName),
            'tools' => [],
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $providerState
     * @param array<string, mixed>|null $sessionState
     * @return array{
     *   code:string,
     *   name:string,
     *   description:string,
     *   badge:string,
     *   target_url:string,
     *   target_label:string,
     *   workspace_label:string,
     *   handoff_label:string,
     *   native_entry_url:string,
     *   welcome_message:string,
     *   initial_stage:string,
     *   scope:array<string, mixed>,
     *   provider_state:array<string, mixed>,
     *   stage_guides:list<array{
     *     code:string,
     *     label:string,
     *     title:string,
     *     description:string,
     *     ai_recommendation_title:string,
     *     ai_recommendation:string,
     *     confirm_label:string,
     *     previous_label:string,
     *     next_stage:string,
     *     previous_stage:string,
     *     tool_codes:list<string>,
     *     scope_patch:array<string, mixed>,
     *     key_points:list<string>
     *   }>,
     *   tools:list<array{
     *     code:string,
     *     label:string,
     *     description:string,
     *     type:string,
     *     icon:string,
     *     button_class:string,
     *     url:string,
     *     target:string,
     *     stage:string,
     *     scope_patch:array<string, mixed>,
     *     enabled:bool
     *   }>
     * }
     */
    private function buildMissingProviderConfig(
        string $providerCode,
        array $scope,
        array $providerState,
        ?array $sessionState
    ): array {
        return [
            'code' => $providerCode,
            'name' => $providerCode !== '' ? $providerCode : 'websites_default',
            'description' => (string)__('未找到 provider 描述，当前按兼容模式展示。'),
            'badge' => (string)__('兼容模式'),
            'target_url' => '',
            'target_label' => (string)__('查看此流程'),
            'workspace_label' => (string)__('创建工作区'),
            'handoff_label' => (string)__('打开 provider 原生入口'),
            'native_entry_url' => '',
            'welcome_message' => (string)__('已为你创建兼容模式工作区。后续可继续补充 scope 并接入更完整的 provider 配置。'),
            'initial_stage' => $this->normalizeStageCode((string)($sessionState['current_stage'] ?? AiSiteBuilderSession::STAGE_BRIEF)),
            'scope' => $scope,
            'provider_state' => $providerState,
            'stage_guides' => $this->buildDefaultStageGuides((string)__('兼容 provider')),
            'tools' => [],
        ];
    }

    /**
     * @param array<string, mixed> $source
     */
    private function resolveString(array $source, string $key, string $default = ''): string
    {
        return isset($source[$key]) && \is_string($source[$key]) && \trim($source[$key]) !== ''
            ? \trim($source[$key])
            : $default;
    }

    /**
     * @param array<string, mixed> $source
     */
    private function resolveInitialStage(array $source, string $default): string
    {
        $stage = $this->resolveString($source, 'initial_stage', $default);
        return $this->normalizeStageCode($stage !== '' ? $stage : $default);
    }

    /**
     * @param mixed $guides
     * @param list<array{
     *   code:string,
     *   label:string,
     *   title:string,
     *   description:string,
     *   ai_recommendation_title:string,
     *   ai_recommendation:string,
     *   confirm_label:string,
     *   previous_label:string,
     *   next_stage:string,
     *   previous_stage:string,
     *   tool_codes:list<string>,
     *   scope_patch:array<string, mixed>,
     *   key_points:list<string>
     * }> $defaults
     * @return list<array{
     *   code:string,
     *   label:string,
     *   title:string,
     *   description:string,
     *   ai_recommendation_title:string,
     *   ai_recommendation:string,
     *   confirm_label:string,
     *   previous_label:string,
     *   next_stage:string,
     *   previous_stage:string,
     *   tool_codes:list<string>,
     *   scope_patch:array<string, mixed>,
     *   key_points:list<string>
     * }>
     */
    private function normalizeStageGuides(mixed $guides, array $defaults): array
    {
        $normalized = [];
        foreach ($defaults as $guide) {
            $normalized[$guide['code']] = $guide;
        }

        if (!\is_array($guides)) {
            return \array_values($normalized);
        }

        foreach ($guides as $index => $guide) {
            if (!\is_array($guide)) {
                continue;
            }

            $stageCode = \is_string($index) ? $index : (string)($guide['code'] ?? '');
            $stageCode = $this->normalizeStageCode($stageCode);
            if (!isset($normalized[$stageCode])) {
                continue;
            }

            $current = $normalized[$stageCode];
            $current['label'] = $this->resolveStageGuideString($guide, 'label', $current['label']);
            $current['title'] = $this->resolveStageGuideString($guide, 'title', $current['title']);
            $current['description'] = $this->resolveStageGuideString($guide, 'description', $current['description']);
            $current['ai_recommendation_title'] = $this->resolveStageGuideString($guide, 'ai_recommendation_title', $current['ai_recommendation_title']);
            $current['ai_recommendation'] = $this->resolveStageGuideString($guide, 'ai_recommendation', $current['ai_recommendation']);
            $current['confirm_label'] = $this->resolveStageGuideString($guide, 'confirm_label', $current['confirm_label']);
            $current['previous_label'] = $this->resolveStageGuideString($guide, 'previous_label', $current['previous_label']);
            $current['next_stage'] = $this->normalizeStageCode($this->resolveStageGuideString($guide, 'next_stage', $current['next_stage']));
            $current['previous_stage'] = $this->normalizeStageCode($this->resolveStageGuideString($guide, 'previous_stage', $current['previous_stage']));

            if (isset($guide['tool_codes'])) {
                $current['tool_codes'] = $this->normalizeStringList($guide['tool_codes']);
            }
            if (isset($guide['scope_patch']) && \is_array($guide['scope_patch'])) {
                $current['scope_patch'] = $guide['scope_patch'];
            }
            if (isset($guide['key_points'])) {
                $current['key_points'] = $this->normalizeStringList($guide['key_points']);
            }

            $normalized[$stageCode] = $current;
        }

        return \array_values($normalized);
    }

    /**
     * @return list<array{
     *   code:string,
     *   label:string,
     *   title:string,
     *   description:string,
     *   ai_recommendation_title:string,
     *   ai_recommendation:string,
     *   confirm_label:string,
     *   previous_label:string,
     *   next_stage:string,
     *   previous_stage:string,
     *   tool_codes:list<string>,
     *   scope_patch:array<string, mixed>,
     *   key_points:list<string>
     * }>
     */
    private function buildDefaultStageGuides(string $providerName): array
    {
        return [
            [
                'code' => 'prepare',
                'label' => (string)__('第一阶段'),
                'title' => (string)__('信息准备'),
                'description' => (string)__('先让 AI 理清需求、推荐域名和购买服务商，你只需要做确认。'),
                'ai_recommendation_title' => (string)__('AI 推荐'),
                'ai_recommendation' => (string)__('AI 会先给你一份建站简报、域名和服务商建议，确认后再进入页面生成。'),
                'confirm_label' => (string)__('确认基础方案，进入页面生成'),
                'previous_label' => '',
                'next_stage' => 'generate',
                'previous_stage' => '',
                'tool_codes' => [],
                'scope_patch' => [
                    'journey_stage' => 'prepare',
                    'ai_guided' => 1,
                ],
                'key_points' => [
                    (string)__('简单描述你要做什么站'),
                    (string)__('AI 推荐域名和购买服务商'),
                    (string)__('确认后进入自动准备'),
                ],
            ],
            [
                'code' => 'generate',
                'label' => (string)__('第二阶段'),
                'title' => (string)__('页面生成'),
                'description' => (string)__('先定页面类型，再定主题方向，再生成内容，尽量做到少点几次就能继续。'),
                'ai_recommendation_title' => (string)__('AI 页面建议'),
                'ai_recommendation' => (string)__('AI 会先给出默认页面结构、主题方向和关键内容组件，你只需要决定是否采用。'),
                'confirm_label' => (string)__('确认页面方案，进入完成'),
                'previous_label' => (string)__('返回信息准备'),
                'next_stage' => 'complete',
                'previous_stage' => 'prepare',
                'tool_codes' => [],
                'scope_patch' => [
                    'journey_stage' => 'generate',
                    'header_footer_locked' => 1,
                ],
                'key_points' => [
                    (string)__('默认页面类型先由 AI 推荐'),
                    (string)__('可直接用 AI 推荐主题，或换成已有模板'),
                    (string)__('Header / Footer 固定，主体内容支持 AI 生成'),
                ],
            ],
            [
                'code' => 'complete',
                'label' => (string)__('第三阶段'),
                'title' => (string)__('完成'),
                'description' => (string)__('等待域名和证书等准备就绪，然后预览、回退或继续交付。'),
                'ai_recommendation_title' => (string)__('AI 检查建议'),
                'ai_recommendation' => (string)__('AI 建议先预览首页和关键转化页，再决定是否回到上一阶段调整。'),
                'confirm_label' => (string)__('保留当前方案'),
                'previous_label' => (string)__('返回页面生成'),
                'next_stage' => '',
                'previous_stage' => 'generate',
                'tool_codes' => [],
                'scope_patch' => [
                    'journey_stage' => 'complete',
                ],
                'key_points' => [
                    (string)__('等待域名购买、解析、证书生成到可用'),
                    (string)__('可以直接预览'),
                    (string)__('不满意时可一键回退'),
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $guide
     */
    private function resolveStageGuideString(array $guide, string $key, string $default = ''): string
    {
        return isset($guide[$key]) && \is_string($guide[$key]) && \trim($guide[$key]) !== ''
            ? \trim($guide[$key])
            : $default;
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $items): array
    {
        if (!\is_array($items)) {
            return [];
        }

        $list = [];
        foreach ($items as $item) {
            if (!\is_string($item)) {
                continue;
            }
            $item = \trim($item);
            if ($item === '') {
                continue;
            }
            $list[] = $item;
        }

        return \array_values(\array_unique($list));
    }

    private function normalizeStageCode(string $stage): string
    {
        $stage = \trim($stage);
        if ($stage === '') {
            return 'prepare';
        }

        return match ($stage) {
            'prepare', 'brief', 'domain', 'domain_wait' => 'prepare',
            'generate', 'virtual_theme', 'page_types', 'content', 'visual_edit' => 'generate',
            'complete', 'publish' => 'complete',
            default => 'prepare',
        };
    }

    /**
     * @param mixed $tools
     * @return list<array{
     *   code:string,
     *   label:string,
     *   description:string,
     *   type:string,
     *   icon:string,
     *   button_class:string,
     *   url:string,
     *   target:string,
     *   stage:string,
     *   scope_patch:array<string, mixed>,
     *   enabled:bool
     * }>
     */
    private function normalizeTools(mixed $tools): array
    {
        if (!\is_array($tools)) {
            return [];
        }

        $normalized = [];
        foreach ($tools as $index => $tool) {
            if (!\is_array($tool)) {
                continue;
            }

            $label = isset($tool['label']) && \is_string($tool['label']) ? \trim($tool['label']) : '';
            if ($label === '') {
                continue;
            }

            $type = isset($tool['type']) && \is_string($tool['type']) ? \trim($tool['type']) : 'link';
            if (!\in_array($type, ['link', 'scope_patch'], true)) {
                $type = 'link';
            }

            $url = isset($tool['url']) && \is_string($tool['url']) ? \trim($tool['url']) : '';
            $scopePatch = isset($tool['scope_patch']) && \is_array($tool['scope_patch']) ? $tool['scope_patch'] : [];
            if ($type === 'link' && $url === '') {
                continue;
            }
            if ($type === 'scope_patch' && $scopePatch === []) {
                continue;
            }

            $normalized[] = [
                'code' => isset($tool['code']) && \is_string($tool['code']) && \trim($tool['code']) !== ''
                    ? \trim($tool['code'])
                    : 'tool_' . (string)$index,
                'label' => $label,
                'description' => isset($tool['description']) && \is_string($tool['description']) ? \trim($tool['description']) : '',
                'type' => $type,
                'icon' => isset($tool['icon']) && \is_string($tool['icon']) && \trim($tool['icon']) !== ''
                    ? \trim($tool['icon'])
                    : 'mdi mdi-hammer-wrench',
                'button_class' => isset($tool['button_class']) && \is_string($tool['button_class']) && \trim($tool['button_class']) !== ''
                    ? \trim($tool['button_class'])
                    : ($type === 'scope_patch' ? 'btn-outline-primary' : 'btn-outline-secondary'),
                'url' => $url,
                'target' => isset($tool['target']) && \is_string($tool['target']) && \trim($tool['target']) !== ''
                    ? \trim($tool['target'])
                    : ($type === 'link' ? '_self' : ''),
                'stage' => $this->normalizeStageCode(isset($tool['stage']) && \is_string($tool['stage']) ? \trim($tool['stage']) : ''),
                'scope_patch' => $scopePatch,
                'enabled' => !isset($tool['enabled']) || (bool)$tool['enabled'],
            ];
        }

        return \array_values(\array_filter(
            $normalized,
            static fn (array $tool): bool => $tool['enabled']
        ));
    }
}
