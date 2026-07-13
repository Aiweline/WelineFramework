<?php

declare(strict_types=1);

namespace Weline\Backend\Api\View;

interface BackendThemeConfigInterface
{
    public const SESSION_CONFIG_KEY = 'backend_theme_config';

    /** Reload the current backend user's persisted theme context. */
    public function reloadForCurrentUser(): void;

    public function getOriginThemeConfig(string $key = '');

    public function getThemeConfig(string $key = '');

    /** Resolve the current backend color mode as a scalar. */
    public function getThemeModel(): string;

    /** Persist either one key or a data-only configuration map. */
    public function setThemeConfig(string|array $key, mixed $value = ''): static;
}
