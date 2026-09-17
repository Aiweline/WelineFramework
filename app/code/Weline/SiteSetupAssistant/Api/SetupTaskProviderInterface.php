<?php

declare(strict_types=1);

namespace Weline\SiteSetupAssistant\Api;

/**
 * 建站任务 Provider：业务模块自声明任务条目并自检。
 * 建站助手只收集 Extends 实现并调用本接口，不拥有业务完成条件。
 */
interface SetupTaskProviderInterface
{
    /**
     * @param array{website_id?:int,website_code?:string,storage_scope?:string} $context
     * @return list<array{
     *   code: string,
     *   title: string,
     *   tip: string,
     *   status: 'todo'|'doing'|'done',
     *   href?: string,
     *   category?: string,
     *   module?: string,
     *   scenarios?: list<'new'|'migrate'>,
     *   parent_code?: string,
     *   sort?: int,
     *   meta?: array<string, mixed>
     * }>
     */
    public function provideTasks(array $context = []): array;
}
