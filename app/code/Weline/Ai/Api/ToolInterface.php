<?php

declare(strict_types=1);

namespace Weline\Ai\Api;

interface ToolInterface
{
    public function getName(): string;

    public function getDescription(): string;

    public function getParameters(): array;

    public function execute(array $args): mixed;

    public function isEnabled(): bool;
}
