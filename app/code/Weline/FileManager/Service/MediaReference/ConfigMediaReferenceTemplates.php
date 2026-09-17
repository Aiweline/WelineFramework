<?php

declare(strict_types=1);

namespace Weline\FileManager\Service\MediaReference;

/**
 * Helper for config / smtp / backend logo identity construction.
 */
final class ConfigMediaReferenceTemplates
{
    /**
     * @param array<string, mixed> $slot
     */
    public static function config(?string $scope, string $key, array $slot = []): MediaReferenceIdentity
    {
        $slot = array_merge(['kind' => 'media', 'field' => $key], $slot);
        return w_scope($scope, 'config', $key, $slot);
    }

    /**
     * @param array<string, mixed> $slot
     */
    public static function smtp(?string $scope, string $mailCode = 'default', array $slot = []): MediaReferenceIdentity
    {
        $slot = array_merge(['kind' => 'background'], $slot);
        return w_scope($scope, 'smtp', $mailCode, $slot);
    }

    /**
     * @param array<string, mixed> $slot
     */
    public static function backendLogo(?string $scope, string $key = 'logo_dark', array $slot = []): MediaReferenceIdentity
    {
        $slot = array_merge(['ns' => 'backend', 'kind' => 'media', 'field' => $key], $slot);
        return w_scope($scope, 'config', $key, $slot);
    }
}
