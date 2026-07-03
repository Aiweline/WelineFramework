<?php

declare(strict_types=1);

namespace Weline\Theme\Api;

interface TargetTypeProviderInterface
{
    public function getCode(): string;

    public function getLabel(): string;

    public function getModule(): string;

    /**
     * @return list<string>
     */
    public function getLayoutTypes(): array;

    /**
     * @return list<string>
     */
    public function getCapabilities(): array;

    /**
     * @param array<string,mixed> $context
     */
    public function validate(int $targetId, array $context = []): bool;

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>|null
     */
    public function resolve(int $targetId, array $context = []): ?array;

    public function canUseLayoutType(string $layoutType): bool;
}
