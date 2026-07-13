<?php

declare(strict_types=1);

namespace Weline\Widget\Api;

/**
 * Optional module-owned extension for enriching the Widget library.
 *
 * The contract intentionally exposes data only and contains no Theme types.
 */
interface WidgetLibraryProviderInterface
{
    public function supports(string $module, string $code, string $area = 'frontend'): bool;

    /**
     * @param array<string,mixed>|null $filterOptions
     * @return array<string,mixed>
     */
    public function getAvailableList(?string $pageType = null, ?array $filterOptions = null, string $area = 'frontend'): array;

    /** @return array<string,mixed> */
    public function getParamDefinitions(string $module, string $code, string $area = 'frontend'): array;

    /** @param array<string,mixed> $config */
    public function renderPreview(string $module, string $code, array $config = [], string $area = 'frontend'): string;
}
