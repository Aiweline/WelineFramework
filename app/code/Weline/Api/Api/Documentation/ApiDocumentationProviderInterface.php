<?php

declare(strict_types=1);

namespace Weline\Api\Api\Documentation;

interface ApiDocumentationProviderInterface
{
    /** @return array<string, array<int, array<string, mixed>>> */
    public function generateAll(bool $force = false): array;
}
