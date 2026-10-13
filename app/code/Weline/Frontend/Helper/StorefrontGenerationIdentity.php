<?php

declare(strict_types=1);

namespace Weline\Frontend\Helper;

use Weline\Framework\Manager\ObjectManager;

/**
 * Bridge to Theme Scope-published version identity for storefront generation.
 * Soft-depends on Weline_Theme; empty id skips generation enforcement.
 */
final class StorefrontGenerationIdentity
{
    /**
     * @return array{
     *   theme_published_version_id: string,
     *   theme_published_version: string,
     *   theme_id: int,
     *   canonical_scope: string
     * }
     */
    public static function current(): array
    {
        $empty = [
            'theme_published_version_id' => '',
            'theme_published_version' => '',
            'theme_id' => 0,
            'canonical_scope' => '',
        ];
        try {
            if (!\class_exists(\Weline\Theme\Service\ThemePublishedVersionRuntimeResolver::class)) {
                return $empty;
            }
            /** @var \Weline\Theme\Service\ThemePublishedVersionRuntimeResolver $resolver */
            $resolver = ObjectManager::getInstance(
                \Weline\Theme\Service\ThemePublishedVersionRuntimeResolver::class
            );
            $resolved = $resolver->resolveGenerationIdentity();

            return [
                'theme_published_version_id' => \trim((string)($resolved['themePublishedVersionId'] ?? '')),
                'theme_published_version' => \trim((string)($resolved['themePublishedVersion'] ?? '')),
                'theme_id' => (int)($resolved['themeId'] ?? 0),
                'canonical_scope' => \trim((string)($resolved['canonicalScope'] ?? '')),
            ];
        } catch (\Throwable) {
            return $empty;
        }
    }

    public static function currentVersionId(): string
    {
        return self::current()['theme_published_version_id'];
    }
}
