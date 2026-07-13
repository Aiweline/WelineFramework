<?php

declare(strict_types=1);

namespace Weline\Framework\Manager;

interface ContainerInterface
{
    public function has(string $id): bool;

    public function get(string $id): object;

    /**
     * @param array<string, mixed> $arguments
     */
    public function create(string $id, array $arguments = []): object;

    public function reset(ServiceScope $scope): void;
}
