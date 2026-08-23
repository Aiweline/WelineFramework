<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Api\Scope;

/** UI restoration only; never an authoritative write target. */
interface ScopeUiStateInterface
{
    /** @return array<string, mixed>|null */
    public function read(string $key): ?array;

    /** @param array<string, mixed> $value */
    public function write(string $key, array $value): void;
}
