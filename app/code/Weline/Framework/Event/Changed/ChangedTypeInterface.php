<?php

declare(strict_types=1);

namespace Weline\Framework\Event\Changed;

use Weline\Framework\Event\ResourceChange\ResourceChange;

/**
 * 变更类型合同：校验 action + Enricher + Recipe。
 */
interface ChangedTypeInterface
{
    public const EXTENDS_RELATIVE_PREFIX = 'extends/module/weline_framework/changed/type/';

    public function code(): string;

    public function description(): string;

    /** @return list<string> upsert|delete|publish|unpublish */
    public function allowedActions(): array;

    /**
     * 从变更信封推导 impact 材料；不得读 HTTP Request。
     *
     * @return array{
     *   namespaces?:list<string>,
     *   previous_namespaces?:list<string>,
     *   urls?:list<string>,
     *   previous_urls?:list<string>,
     *   cache_ops?:list<array{pool:string,keys:list<string>}>
     * }
     */
    public function enrich(ResourceChange $change): array;

    /**
     * @return list<InvalidationEffect>
     */
    public function recipe(ResourceChange $change): array;
}
