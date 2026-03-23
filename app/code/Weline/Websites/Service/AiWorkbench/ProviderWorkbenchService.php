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
            'initial_stage' => \trim((string)($sessionState['current_stage'] ?? AiSiteBuilderSession::STAGE_BRIEF)) ?: AiSiteBuilderSession::STAGE_BRIEF,
            'scope' => $scope,
            'provider_state' => $providerState,
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
            'initial_stage' => \trim((string)($sessionState['current_stage'] ?? AiSiteBuilderSession::STAGE_BRIEF)) ?: AiSiteBuilderSession::STAGE_BRIEF,
            'scope' => $scope,
            'provider_state' => $providerState,
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
        return $stage !== '' ? $stage : AiSiteBuilderSession::STAGE_BRIEF;
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
                'stage' => isset($tool['stage']) && \is_string($tool['stage']) ? \trim($tool['stage']) : '',
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
