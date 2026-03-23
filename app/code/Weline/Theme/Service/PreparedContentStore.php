<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Runtime\StateManager;

class PreparedContentStore
{
    /** @var array<string,string> */
    private static array $contentByKey = [];

    private static int $counter = 0;

    private static bool $stateManagerRegistered = false;

    private static function registerStateManager(): void
    {
        if (self::$stateManagerRegistered) {
            return;
        }

        if (class_exists(StateManager::class)) {
            StateManager::registerResetCallback('Theme_PreparedContentStore', [self::class, 'resetRequestState']);
            self::$stateManagerRegistered = true;
        }
    }

    public static function put(string $content): string
    {
        self::registerStateManager();

        $key = 'prepared_content_' . (++self::$counter);
        self::$contentByKey[$key] = $content;

        return $key;
    }

    public static function has(?string $key): bool
    {
        self::registerStateManager();

        return $key !== null && $key !== '' && array_key_exists($key, self::$contentByKey);
    }

    public static function get(?string $key, string $fallback = ''): string
    {
        self::registerStateManager();

        if ($key === null || $key === '') {
            return $fallback;
        }

        return self::$contentByKey[$key] ?? $fallback;
    }

    public static function resolveLayoutContent(
        mixed $contentRenderKey,
        mixed $content = null,
        mixed $meta = null,
        mixed $childHtml = null,
        ?string $contentTemplate = null,
        ?callable $templateRenderer = null
    ): string {
        $resolvedKey = self::resolveKey($contentRenderKey, $meta, $childHtml);
        if (self::has($resolvedKey)) {
            return self::get($resolvedKey);
        }

        $directContent = self::resolveDirectContent($content, $meta, $childHtml);
        if ($directContent !== null) {
            return $directContent;
        }

        if ($contentTemplate !== null && $contentTemplate !== '' && $templateRenderer !== null) {
            return (string)$templateRenderer($contentTemplate);
        }

        return '';
    }

    public static function resetRequestState(): void
    {
        self::$contentByKey = [];
        self::$counter = 0;
    }

    private static function resolveKey(mixed $contentRenderKey, mixed $meta, mixed $childHtml): string
    {
        if (is_string($contentRenderKey) && $contentRenderKey !== '') {
            return $contentRenderKey;
        }

        if (is_array($meta) && isset($meta['contentRenderKey']) && is_string($meta['contentRenderKey'])) {
            return $meta['contentRenderKey'];
        }

        if (is_array($childHtml) && isset($childHtml['contentRenderKey']) && is_string($childHtml['contentRenderKey'])) {
            return $childHtml['contentRenderKey'];
        }

        return '';
    }

    private static function resolveDirectContent(mixed $content, mixed $meta, mixed $childHtml): ?string
    {
        if (is_string($content)) {
            return $content;
        }

        if (is_array($meta) && array_key_exists('content', $meta) && is_string($meta['content'])) {
            return $meta['content'];
        }

        if (is_array($childHtml) && array_key_exists('content', $childHtml) && is_string($childHtml['content'])) {
            return $childHtml['content'];
        }

        return null;
    }
}
