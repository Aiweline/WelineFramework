<?php

declare(strict_types=1);

namespace Weline\Review\Api;

/** 可选批量评论对象解析；旧扩展仍可只实现单条接口。 */
interface BatchReviewTypeProviderInterface extends ReviewTypeProviderInterface
{
    /** @param list<string> $uuids @return array<string, array{entity_id:int,entity_uuid:string}|null> */
    public function resolveEntities(array $uuids): array;
}
