<?php

declare(strict_types=1);

namespace Weline\Eav\Api\Entity;

/**
 * Stable, implementation-free description of an EAV-capable business entity.
 */
interface EntityDefinitionInterface
{
    public function getEntityCode(): string;

    public function getEntityName(): string;

    public function getEntityFieldIdType(): string;

    public function getEntityFieldIdLength(): int;
}
