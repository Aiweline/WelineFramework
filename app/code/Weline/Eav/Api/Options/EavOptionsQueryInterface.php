<?php

declare(strict_types=1);

namespace Weline\Eav\Api\Options;

/** Read-only, data-only EAV option catalog used by editors and API controllers. */
interface EavOptionsQueryInterface
{
    /** @param array<string, mixed> $params @return array<string, mixed> */
    public function queryOptions(array $params = []): array;

    /** @param array<string, mixed> $params @return array<string, mixed> */
    public function queryAttributes(array $params = []): array;

    /** @return array<string, mixed> */
    public function queryEntities(): array;
}
