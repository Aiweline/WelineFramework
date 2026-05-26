<?php
declare(strict_types=1);

namespace Weline\Api\Data;

class ApiAppTokenContext
{
    public function __construct(
        private readonly ApiAppActor $actor,
        private readonly array $accessSources
    ) {
    }

    public function getActor(): ApiAppActor
    {
        return $this->actor;
    }

    public function getAccessSources(): array
    {
        return $this->accessSources;
    }
}
