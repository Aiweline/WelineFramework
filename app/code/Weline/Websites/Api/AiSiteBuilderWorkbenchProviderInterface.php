<?php

declare(strict_types=1);

namespace Weline\Websites\Api;

interface AiSiteBuilderWorkbenchProviderInterface extends AiSiteBuilderProviderInterface
{
    /**
     * @param array<string, mixed>|null $sessionState
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $providerState
     * @param array<string, mixed> $context
     * @return array{
     *   badge?:string,
     *   target_url?:string,
     *   target_label?:string,
     *   workspace_label?:string,
     *   handoff_label?:string,
     *   native_entry_url?:string,
     *   welcome_message?:string,
     *   initial_stage?:string,
     *   scope?:array<string, mixed>,
     *   provider_state?:array<string, mixed>,
     *   tools?:list<array<string, mixed>>
     * }
     */
    public function getWorkbenchConfig(
        ?array $sessionState,
        int $adminUserId,
        array $scope = [],
        array $providerState = [],
        array $context = []
    ): array;
}
