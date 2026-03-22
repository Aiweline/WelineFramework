<?php

declare(strict_types=1);

namespace Weline\Websites\Api;

interface WebsiteThemeSourceRegistryInterface
{
    /**
     * @return array<string, WebsiteThemeSourceInterface>
     */
    public function getSources(bool $onlyEnabled = true, bool $forceReload = false): array;

    public function getSource(string $sourceCode, bool $forceReload = false): ?WebsiteThemeSourceInterface;

    public function clearCache(): void;
}
