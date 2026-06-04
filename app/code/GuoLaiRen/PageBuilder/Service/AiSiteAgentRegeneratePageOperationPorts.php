<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

final class AiSiteAgentRegeneratePageOperationPorts
{
    /**
     * @param \Closure(\GuoLaiRen\PageBuilder\Model\AiSiteAgentSession, int):void $assertActiveStreamLeaseAlive
     * @param \Closure(array):array<string,mixed> $normalizeScope
     * @param \Closure(array):array<int,string> $resolveScopedPageTypes
     * @param \Closure(array):array<string,mixed> $generateProfile
     * @param \Closure(array,array,string):array<string,mixed> $ensureTaskScope
     * @param \Closure(array,string):array<string,mixed> $resetPageTasksForRetry
     * @param \Closure(string):string $normalizeWorkspaceTrack
     * @param \Closure(string):array<string,string> $resolvePageTypeLabels
     * @param \Closure(mixed ...$args):void $sendOperationProgress
     * @param \Closure(array<int,string>,array):array<string,mixed> $buildVirtualPagesByType
     * @param \Closure(string,array,array):array<string,mixed> $buildPageBlueprint
     * @param \Closure(string,array,array):array<int,array<string,mixed>> $buildPlaceholderBlocksForPageType
     * @param \Closure(array,string,array):array<string,mixed> $markTaskDone
     * @param \Closure(string,int,array,array,array,array):array<string,mixed> $materializeGeneratedPages
     * @param \Closure(array,array):array<string,mixed> $mergeMaterializedPagesIntoScope
     * @param \Closure(array):array<string,mixed> $summarizePlanJsonTasks
     * @param \Closure(int,int,array):void $replaceScope
     * @param \Closure(int,int,int):void $bindVirtualTheme
     * @param \Closure(int,int,string,string,string,array):void $appendWorkspaceEvent
     * @param \Closure(mixed,array<int,string>):array<string,mixed> $normalizePageTypeLayouts
     * @param \Closure(array,string):array<string,mixed> $normalizeLayoutConfig
     * @param \Closure(array,array,array,array,int,bool):array<string,mixed> $ensureAiGeneratedVirtualTheme
     * @param null|\Closure(array,array,array,array,string,int):array<string,mixed> $regenerateAiGeneratedVirtualThemePage
     */
    public function __construct(
        public readonly \Closure $assertActiveStreamLeaseAlive,
        public readonly \Closure $normalizeScope,
        public readonly \Closure $resolveScopedPageTypes,
        public readonly \Closure $generateProfile,
        public readonly \Closure $ensureTaskScope,
        public readonly \Closure $resetPageTasksForRetry,
        public readonly \Closure $normalizeWorkspaceTrack,
        public readonly \Closure $resolvePageTypeLabels,
        public readonly \Closure $sendOperationProgress,
        public readonly \Closure $buildVirtualPagesByType,
        public readonly \Closure $buildPageBlueprint,
        public readonly \Closure $buildPlaceholderBlocksForPageType,
        public readonly \Closure $markTaskDone,
        public readonly \Closure $materializeGeneratedPages,
        public readonly \Closure $mergeMaterializedPagesIntoScope,
        public readonly \Closure $summarizePlanJsonTasks,
        public readonly \Closure $replaceScope,
        public readonly \Closure $bindVirtualTheme,
        public readonly \Closure $appendWorkspaceEvent,
        public readonly \Closure $normalizePageTypeLayouts,
        public readonly \Closure $normalizeLayoutConfig,
        public readonly \Closure $ensureAiGeneratedVirtualTheme,
        public readonly ?\Closure $regenerateAiGeneratedVirtualThemePage = null,
    ) {
    }
}
