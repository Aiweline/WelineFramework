<?php

declare(strict_types=1);

namespace Weline\Acl\Api\Resource;

interface MenuSourceProviderInterface
{
    /** @return list<string> */
    public function sourceIds(): array;
}
