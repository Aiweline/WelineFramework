<?php

declare(strict_types=1);

namespace Weline\Theme\Api;

interface TargetPreviewPayloadProviderInterface
{
    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>|null
     */
    public function resolvePreviewPayload(int $targetId, array $context = []): ?array;
}
