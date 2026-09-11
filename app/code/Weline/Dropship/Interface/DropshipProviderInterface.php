<?php

declare(strict_types=1);

namespace Weline\Dropship\Interface;

/**
 * Core SPI for dropship / 货源代发 providers (Payment-shell style).
 * getCode() is also the product source marker (dropship_source).
 */
interface DropshipProviderInterface
{
    public function getCode(): string;

    /**
     * @return array<string, mixed>
     */
    public function getCapabilities(): array;

    /**
     * @return array<string, mixed>
     */
    public function getDisplayMetadata(): array;

    /**
     * @return array<string, mixed>
     */
    public function getConfigSchema(): array;

    /**
     * @param array<string, mixed> $context
     * @return array{ok:bool,message?:string,data?:array<string,mixed>}
     */
    public function probeConnection(array $context = []): array;
}
