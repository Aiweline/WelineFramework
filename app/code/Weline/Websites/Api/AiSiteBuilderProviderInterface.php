<?php

declare(strict_types=1);

namespace Weline\Websites\Api;

interface AiSiteBuilderProviderInterface
{
    public function getCode(): string;

    public function getName(): string;

    public function getDescription(): string;

    public function isEnabled(): bool;

    public function getSortOrder(): int;
}
