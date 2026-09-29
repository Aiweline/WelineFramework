<?php

declare(strict_types=1);

namespace Weline\Framework\Controller\Extra;

interface FpcPolicySnapshotProviderInterface
{
    /** 仅读取已发布编译快照，禁止在请求期查询策略表。 */
    public function snapshot(): array;
}
