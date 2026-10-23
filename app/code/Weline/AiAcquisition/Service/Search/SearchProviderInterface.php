<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Service\Search;

/**
 * 搜索提供者契约。
 *
 * 每个实现只负责一次远端检索并返回统一结构；跨提供者的去重、排序与条数限制
 * 统一由 SearchService 处理，实现方不需要关心合并逻辑。
 */
interface SearchProviderInterface
{
    /**
     * 执行一次检索。
     *
     * @param string $query 原始检索词，允许 `site:`、引号等原生语法，实现方须原样传给远端
     * @param int $limit 期望条数（>=1）
     * @return list<array{title:string,url:string,snippet:string,source:string,rank:int}> 失败时返回空数组
     */
    public function search(string $query, int $limit = 10): array;
}
