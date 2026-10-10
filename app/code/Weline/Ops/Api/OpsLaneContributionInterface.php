<?php

declare(strict_types=1);

namespace Weline\Ops\Api;

/**
 * 域模块向 AI 运营台贡献的范围车道。
 */
interface OpsLaneContributionInterface
{
    public function getLaneCode(): string;

    public function getTitle(): string;

    public function getSortOrder(): int;

    /**
     * ready|deeplink|placeholder
     */
    public function getStatus(): string;

    /**
     * @return list<array{code:string,label:string,url:string,connected:?bool}>
     */
    public function getActions(int $websiteId): array;

    /**
     * @return list<array{type:string,label:string}>
     */
    public function getTaskTypes(): array;

    /**
     * @return array{connected:bool,summary:string,detail?:array<string,mixed>}
     */
    public function getConnectionHealth(int $websiteId): array;
}
