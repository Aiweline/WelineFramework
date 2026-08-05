<?php

declare(strict_types=1);

namespace Weline\Framework\Event\ResourceChange;

use Weline\Framework\Api\Event\AsyncPayloadMapperInterface;
use Weline\Framework\Event\Async\CanonicalJson;
use Weline\Framework\Event\Async\ContextSnapshot;
use Weline\Framework\Event\Async\Exception\AsyncEventValidationException;

final class ResourceChangePayloadMapper implements AsyncPayloadMapperInterface
{
    public function __construct(
        private readonly CanonicalJson $canonicalJson,
        private readonly ContextSnapshot $contextSnapshot,
    ) {
    }

    public function eventName(): string
    {
        return ResourceChange::EVENT_NAME;
    }

    public function schemaVersion(): int
    {
        return ResourceChange::SCHEMA_VERSION;
    }

    public function toPayload(mixed $data): array
    {
        if (!$data instanceof ResourceChange) {
            throw new AsyncEventValidationException(__('资源变更异步载荷只接受不可变 ResourceChange DTO'));
        }
        $payload = $data->toArray();
        $this->validate($payload);
        return $payload;
    }

    public function fromPayload(array $payload): ResourceChange
    {
        $this->validate($payload);
        return ResourceChange::fromArray($payload);
    }

    public function validate(array $payload): void
    {
        ResourceChange::fromArray($payload);
        $this->contextSnapshot->validate((array)$payload['context']);
        $this->canonicalJson->encode($payload);
    }
}
