<?php

declare(strict_types=1);

namespace Weline\SiteSetupAssistant\Api;

/**
 * 建站任务 Provider：业务模块自声明任务条目并自检。
 * 建站助手只收集 Extends 实现并调用本接口，不拥有业务完成条件。
 */
interface SetupTaskProviderInterface
{
    /** 建站任务分组（默认）：上线/迁站检查项 */
    public const GROUP_SETUP = 'setup';

    /** 性能优化分组：影响缓存命中等的可关闭提示（达标则不展示） */
    public const GROUP_PERFORMANCE = 'performance';

    /**
     * @param array{website_id?:int,website_code?:string,storage_scope?:string} $context
     * @return list<array{
     *   code: string,
     *   title: string,
     *   tip: string,
     *   status: 'todo'|'doing'|'done',
     *   href?: string,
     *   category?: string,
     *   group?: 'setup'|'performance',
     *   module?: string,
     *   scenarios?: list<'new'|'migrate'>,
     *   parent_code?: string,
     *   sort?: int,
     *   meta?: array<string, mixed>
     * }>
     */
    public function provideTasks(array $context = []): array;
}
