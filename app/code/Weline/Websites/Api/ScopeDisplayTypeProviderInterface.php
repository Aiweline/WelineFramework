<?php

declare(strict_types=1);

namespace Weline\Websites\Api;

/**
 * Scope display-type identity provider (Extends SPI).
 *
 * Registers a display identity code only. Must not declare Product/Catalog
 * constraints — those belong to Product owning-module SPI.
 */
interface ScopeDisplayTypeProviderInterface
{
    /** Stable type code (≤64), e.g. b2b. */
    public function getCode(): string;

    /** Admin / UI label. */
    public function getLabel(): string;

    /** Owning module name, e.g. Weline_B2B. */
    public function getModule(): string;

    public function getSortOrder(): int;
}
