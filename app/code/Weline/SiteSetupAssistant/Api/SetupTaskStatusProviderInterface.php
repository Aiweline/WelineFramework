<?php

declare(strict_types=1);

namespace Weline\SiteSetupAssistant\Api;

/**
 * 各业务模块向建站助手上报任务状态（Extends 多实现）。
 */
interface SetupTaskStatusProviderInterface
{
    /**
     * @param array{website_id?:int,website_code?:string,storage_scope?:string} $context
     * @return list<array{
     *   code: string,
     *   status: 'todo'|'doing'|'done',
     *   tip?: string,
     *   title?: string,
     *   href?: string,
     *   meta?: array<string, mixed>
     * }>
     */
    public function resolveTaskStatus(array $context = []): array;
}
