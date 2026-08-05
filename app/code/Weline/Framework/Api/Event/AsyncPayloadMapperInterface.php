<?php

declare(strict_types=1);

namespace Weline\Framework\Api\Event;

interface AsyncPayloadMapperInterface
{
    public function eventName(): string;

    public function schemaVersion(): int;

    /** @return array<string,mixed> */
    public function toPayload(mixed $data): array;

    public function fromPayload(array $payload): mixed;

    public function validate(array $payload): void;
}
